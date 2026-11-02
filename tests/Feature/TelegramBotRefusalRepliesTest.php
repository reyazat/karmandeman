<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Product\ApproveTelegramProductAction;
use App\Actions\Product\RejectTelegramProductAction;
use App\Actions\Project\ApproveTelegramBatchAction;
use App\Actions\Project\FinishTelegramBatchAction;
use App\Actions\Project\ReopenTelegramProjectAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/**
 * Review findings after ADR-031: a deletion that starts after the webhook's
 * check is answered as erasure, not "try again" (FR-086), and an outdated
 * card in an approved batch never offers reopen (ADR-029).
 */
class TelegramBotRefusalRepliesTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const BOT_ID = 123;

    private const ERASURE = 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.';

    private int $updates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config(['services.telegram.webhook_secret' => self::SECRET, 'services.telegram.bot_token' => self::BOT_ID.':test-token']);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    private function update(array $payload): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', ['update_id' => ++$this->updates] + $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function text(string $text, int $tid, ?array $repliedTo = null): TestResponse
    {
        $m = ['message_id' => ++$this->updates, 'date' => time(), 'text' => $text,
            'from' => ['id' => $tid, 'first_name' => 'U'], 'chat' => ['id' => $tid, 'type' => 'private']];
        if ($repliedTo !== null) {
            $m['reply_to_message'] = $repliedTo;
        }

        return $this->update(['message' => $m]);
    }

    private function press(string $data, int $tid): TestResponse
    {
        return $this->update(['callback_query' => ['id' => 'cb-'.++$this->updates, 'data' => $data,
            'from' => ['id' => $tid, 'first_name' => 'U'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $tid, 'type' => 'private']]]]);
    }

    private function seller(int $tid): User
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($tid));
        $ws = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $o = app(AdvanceTelegramOnboardingAction::class);
        $o->execute($ws, $user, 'accept', $this->policyConsentToken());
        $o->execute($ws, $user, 'input', 'Brand');
        $o->execute($ws, $user, 'unit', 'toman');
        $o->execute($ws, $user, 'skip');
        $o->execute($ws, $user, 'skip');
        $o->execute($ws, $user, 'confirm');
        $this->text('/new Batch', $tid)->assertOk();

        return $user;
    }

    private function card(string $fragment, int $tid): array
    {
        $job = Queue::pushed(SendTelegramMessage::class)->filter(fn ($j) => str_contains($j->text, $fragment))->last();
        $this->assertNotNull($job, $fragment);

        return ['message_id' => 900, 'date' => time(), 'text' => $job->text,
            'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'K'],
            'chat' => ['id' => $tid, 'type' => 'private'],
            'reply_markup' => ['inline_keyboard' => $job->buttons]];
    }

    private function lastText(): string
    {
        return Queue::pushed(SendTelegramMessage::class)->last()->text;
    }

    private function startErasure(User $user): Workspace
    {
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'deletion_pending']);

        return $workspace;
    }

    public function test_erasure_after_the_webhook_check_is_answered_without_a_retry_hint(): void
    {
        $user = $this->seller(2001);
        $this->text("/product کیف\n۱۰۰ تومان", 2001)->assertOk();
        $product = Product::query()->sole();
        $workspace = $this->startErasure($user);

        $replies = DB::transaction(fn (): array => [
            app(FinishTelegramBatchAction::class)->execute($workspace, $user, 'k-done')[0]->text,
            app(RejectTelegramProductAction::class)->execute($workspace, $user, 'cb-r', $product->public_id, 1),
            app(ApproveTelegramProductAction::class)->execute($workspace, $user, 'cb-a', $product->public_id, 1),
        ]);

        $this->assertSame([self::ERASURE, self::ERASURE, self::ERASURE], $replies);
        $this->assertSame('collecting_input', Project::query()->sole()->state->value);
        $this->assertSame(1, $product->fresh()->current_revision);
        $this->assertNull($product->fresh()->approved_revision);
    }

    public function test_erasure_refuses_batch_approval_and_reopen_with_the_erasure_answer(): void
    {
        $user = $this->seller(2003);
        $this->text("/product کیف\n۱۰۰ تومان", 2003)->assertOk();
        $product = Product::query()->sole();
        $this->press("product:approve:{$product->public_id}:1", 2003)->assertOk();
        $this->text('/done', 2003)->assertOk();
        $project = Project::query()->sole();
        $version = $project->fresh()->version;
        $workspace = $this->startErasure($user);

        $reply = DB::transaction(fn () => app(ApproveTelegramBatchAction::class)->execute($workspace, $user, 'cb-b', $project->public_id, $version));
        $this->assertSame(self::ERASURE, $reply->text);
        $this->assertSame('review_required', $project->fresh()->state->value);

        Workspace::query()->whereKey($workspace->id)->update(['status' => 'active']);
        $this->press("project:approve:{$project->public_id}:{$version}", 2003)->assertOk();
        $this->assertSame('product_approved', $project->fresh()->state->value);
        $workspace = $this->startErasure($user);

        $reopened = DB::transaction(fn (): array => app(ReopenTelegramProjectAction::class)->execute($workspace, $user, 'cb-o', $project->public_id, $project->fresh()->version));
        $this->assertSame(self::ERASURE, $reopened[0]->text);
        $this->assertSame('product_approved', $project->fresh()->state->value);
    }

    public function test_an_outdated_card_in_an_approved_batch_does_not_offer_reopen(): void
    {
        $this->seller(2002);
        $this->text("/product کیف\n۱۰۰ تومان", 2002)->assertOk();
        $product = Product::query()->sole();
        $first = $this->card('«کیف»', 2002);
        $this->text("کیف دو\n۲۰۰ تومان", 2002, $first)->assertOk();
        $this->press("product:approve:{$product->public_id}:2", 2002)->assertOk();
        $this->text('/done', 2002)->assertOk();
        $project = Project::query()->sole();
        $this->press("project:approve:{$project->public_id}:{$project->fresh()->version}", 2002)->assertOk();
        $this->assertSame('product_approved', $project->fresh()->state->value);

        $this->text("کیف سه\n۳۰۰ تومان", 2002, $first)->assertOk();

        $job = Queue::pushed(SendTelegramMessage::class)->last();
        $this->assertSame('این محصول پس از این پیام تغییر کرده است؛ به کارت نسخه تازه پاسخ بده.', $job->text);
        $this->assertSame([], $job->buttons);
        $this->assertSame('product_approved', $project->fresh()->state->value);
    }

    public function test_the_current_card_in_an_approved_batch_reopens_it_with_the_correction(): void
    {
        $this->seller(2004);
        $this->text("/product کیف\n۱۰۰ تومان", 2004)->assertOk();
        $product = Product::query()->sole();
        $card = $this->card('«کیف»', 2004);
        $this->press("product:approve:{$product->public_id}:1", 2004)->assertOk();
        $this->text('/done', 2004)->assertOk();
        $project = Project::query()->sole();
        $this->press("project:approve:{$project->public_id}:{$project->fresh()->version}", 2004)->assertOk();

        $this->text("کیف دو\n۲۰۰ تومان", 2004, $card)->assertOk();

        $job = Queue::pushed(SendTelegramMessage::class)->last();
        $this->assertStringContainsString('برای اصلاح دوباره باز شد', $job->text);
        $this->assertSame("product:approve:{$product->public_id}:2", $job->buttons[0][0]['callback_data']);
        $this->assertSame('review_required', $project->fresh()->state->value);
    }
}
