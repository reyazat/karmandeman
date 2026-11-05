<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Enums\AiRunStatus;
use App\Jobs\Project\ImportTelegramPhoto;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AiWorkflowRun;
use App\Models\Asset;
use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Models\TelegramOnboarding;
use App\Models\User;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** A photo's caption, and the seller's replies to it, are that photo's text for extraction (ADR-050). */
final class TelegramPhotoTextTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private int $updates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'karmandeman.gift_credits' => 100,
        ]);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class]);
    }

    private function fakeTelegram(int $seed = 1): void
    {
        $bytes = ImageFixture::make($seed);
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => [
                'file_id' => 'x', 'file_path' => 'photos/file_7.jpg', 'file_size' => strlen($bytes),
            ]]),
            'api.telegram.org/file/bot*' => Http::response($bytes),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /** @return int the message id */
    private function send(array $message, int $telegramId, ?int $updateId = null, ?int $messageId = null): int
    {
        $messageId ??= ++$this->updates;
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => $updateId ?? ++$this->updates,
            'message' => $message + [
                'message_id' => $messageId, 'date' => time(),
                'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        return $messageId;
    }

    private function seller(int $telegramId): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', 'Brand');
        $onboarding->execute($workspace, $user, 'unit', 'toman');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'confirm');
        $this->send(['text' => '/new مجموعه آبان'], $telegramId);
    }

    private function photo(string $suffix = 'a'): array
    {
        return ['photo' => [['file_id' => "large-{$suffix}", 'file_unique_id' => 'l', 'width' => 1280, 'height' => 853, 'file_size' => 90_000]]];
    }

    /** A text in reply to the seller's own photo message. */
    private function replyTo(int $photoMessageId, int $telegramId, string $text): array
    {
        return ['text' => $text, 'reply_to_message' => [
            'message_id' => $photoMessageId, 'date' => time(),
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'U'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
        ] + $this->photo()];
    }

    private function lastReply(): string
    {
        return Queue::pushed(SendTelegramMessage::class)->last()->text;
    }

    public function test_a_caption_and_replies_become_that_photo_text_encrypted_and_only_once(): void
    {
        $this->seller(990);
        $this->fakeTelegram();

        $photo = $this->send($this->photo() + ['caption' => '  کیف روزمره؛ جنس پارچه؛ مشکی؛ ۳۵۰٬۰۰۰ تومان '], 990);
        $asset = Asset::query()->sole();
        $this->assertSame('کیف روزمره؛ جنس پارچه؛ مشکی؛ ۳۵۰٬۰۰۰ تومان', $asset->seller_text);
        $this->assertStringContainsString('متن همراه آن هم برای همین عکس ثبت شد', $this->lastReply());
        $this->assertStringNotContainsString('پارچه', (string) DB::table('assets')->value('seller_text'));
        $this->assertSame(0, TelegramMediaIntake::query()->count());
        $version = Project::query()->sole()->version;

        $update = ++$this->updates;
        $this->send($this->replyTo($photo, 990, 'سه رنگ دارد'), 990, $update);
        $this->send($this->replyTo($photo, 990, 'سه رنگ دارد'), 990, $update);
        $this->assertSame("کیف روزمره؛ جنس پارچه؛ مشکی؛ ۳۵۰٬۰۰۰ تومان\nسه رنگ دارد", $asset->fresh()->seller_text);
        $this->assertStringContainsString('مشخصات برای همین عکس در پروژه', $this->lastReply());
        $this->assertSame($version + 1, Project::query()->sole()->version);
    }

    public function test_a_photo_without_caption_invites_a_reply_and_takes_one(): void
    {
        $this->seller(991);
        $this->fakeTelegram();

        $photo = $this->send($this->photo(), 991);
        $this->assertNull(Asset::query()->sole()->seller_text);
        $this->assertStringContainsString('با Reply روی همین عکس', $this->lastReply());

        $this->send($this->replyTo($photo, 991, 'کیف چرم؛ ۲۰۰ هزار تومان'), 991);
        $this->assertSame('کیف چرم؛ ۲۰۰ هزار تومان', Asset::query()->sole()->seller_text);
    }

    public function test_the_same_photo_again_keeps_the_first_text_and_says_so(): void
    {
        $this->seller(997);
        $this->fakeTelegram();
        $this->send($this->photo(), 997);
        $this->send($this->photo('b') + ['caption' => 'کیف چرمی؛ ۳۵۰٬۰۰۰ تومان'], 997);

        $this->assertNull(Asset::query()->sole()->seller_text);
        $this->assertStringContainsString('متن همراه این پیام ثبت نشد', $this->lastReply());
    }

    public function test_before_onboarding_ends_a_reply_to_a_photo_is_an_onboarding_answer(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(998));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        app(AdvanceTelegramOnboardingAction::class)->execute($workspace, $user, 'accept', $this->policyConsentToken());

        $this->send($this->replyTo(41, 998, 'فروشگاه نسترن'), 998);
        $this->assertSame('unit', TelegramOnboarding::query()->sole()->step);
    }

    public function test_text_over_the_limit_is_refused_whole(): void
    {
        $this->seller(992);
        $this->fakeTelegram();

        $this->send($this->photo() + ['caption' => str_repeat('م', Asset::SELLER_TEXT_MAX + 1)], 992);
        $this->assertSame(0, Asset::query()->count());
        $this->assertSame(0, TelegramMediaIntake::query()->count());
        $this->assertStringContainsString('حداکثر 1000 نویسه', $this->lastReply());
        // A Premium caption longer than 1024 characters is answered too, not dropped by validation.
        $this->send($this->photo() + ['caption' => str_repeat('م', 2048)], 992);
        $this->assertSame(0, Asset::query()->count());
        $this->assertStringContainsString('حداکثر 1000 نویسه', $this->lastReply());

        $photo = $this->send($this->photo() + ['caption' => str_repeat('م', Asset::SELLER_TEXT_MAX - 2)], 992);
        $this->send($this->replyTo($photo, 992, 'سه رنگ'), 992);
        $this->assertStringContainsString('کوتاه‌تر بنویس', $this->lastReply());
        $this->assertSame(str_repeat('م', Asset::SELLER_TEXT_MAX - 2), Asset::query()->sole()->seller_text);
    }

    public function test_text_never_attaches_by_guess_or_to_another_seller_photo(): void
    {
        $this->seller(993);
        $this->seller(994);
        $this->fakeTelegram();
        $photo = $this->send($this->photo(), 993);

        // Free text goes nowhere.
        $this->send(['text' => 'جنس: برزنت'], 993);
        $this->assertStringContainsString('به محصولی وصل نشد', $this->lastReply());
        // The other seller citing the same message id in their own chat finds no photo of theirs.
        $this->send($this->replyTo($photo, 994, 'جنس: برزنت'), 994);
        $this->assertStringContainsString('در هیچ پروژه‌ای ثبت نشده', $this->lastReply());
        // A forwarded reply whose quoted photo is someone else's is not the seller's own photo.
        $forged = $this->replyTo($photo, 993, 'جنس: برزنت');
        $this->send($forged, 994);
        $this->assertStringContainsString('به محصولی وصل نشد', $this->lastReply());
        $this->assertNull(Asset::query()->sole()->seller_text);
    }

    public function test_a_photo_an_open_or_succeeded_run_read_keeps_its_text(): void
    {
        $this->seller(996);
        $this->fakeTelegram();
        $photo = $this->send($this->photo(), 996);
        $asset = Asset::query()->sole();
        $runs = app(AiWorkflowRunRepositoryInterface::class);
        $run = $runs->create(Project::query()->sole(), User::query()->sole(), AiWorkflowRun::WORKFLOW_PRODUCT_EXTRACTION,
            'product-extraction.openai.v2', [$asset->public_id], CarbonImmutable::now()->addMinutes(15));

        // The run may already have read the text; a later reply would never reach it.
        $this->send($this->replyTo($photo, 996, 'رنگ: قرمز'), 996);
        $this->assertStringContainsString('قبلاً خوانده شده', $this->lastReply());
        $runs->close($run, AiRunStatus::Succeeded, CarbonImmutable::now());
        $this->send($this->replyTo($photo, 996, 'رنگ: قرمز'), 996);
        $this->assertStringContainsString('قبلاً خوانده شده', $this->lastReply());
        $this->assertNull($asset->fresh()->seller_text);
    }

    public function test_a_used_or_still_queued_photo_keeps_its_text(): void
    {
        $this->seller(995);
        $this->fakeTelegram();
        $photo = $this->send($this->photo(), 995);
        $this->send(['text' => "/product کیف آبی\n120000 تومان"], 995);
        $this->assertNotNull(Asset::query()->sole()->claimed_by_product_id);

        $this->send($this->replyTo($photo, 995, 'رنگ: سرمه‌ای'), 995);
        $this->assertStringContainsString('روی کارت همان محصول Reply کن', $this->lastReply());
        $this->assertNull(Asset::query()->sole()->seller_text);

        // The import has not run yet: the text is not put on the queued intake, where it could be lost.
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        $queued = $this->send($this->photo('b') + ['caption' => 'کیف دوم'], 995);
        $this->send($this->replyTo($queued, 995, 'رنگ: قرمز'), 995);
        $this->assertStringContainsString('هنوز در حال ثبت است', $this->lastReply());
        $this->assertNotSame('کیف دوم', TelegramMediaIntake::query()->sole()->seller_text);
        $this->assertSame(1, Asset::query()->count());
    }
}
