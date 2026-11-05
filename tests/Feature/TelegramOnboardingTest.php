<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Brand;
use App\Models\MemoryItem;
use App\Models\MemoryItemRevision;
use App\Models\Project;
use App\Models\ServiceRequest;
use App\Models\TelegramOnboarding;
use App\Models\Wallet;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

final class TelegramOnboardingTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-onboarding-secret';

    private int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'karmandeman.gift_credits' => 100,
        ]);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    private function send(string $text = '', int $telegramId = 555, array $extra = []): TestResponse
    {
        $message = array_merge([
            'message_id' => $this->updateId + 1,
            'date' => time(),
            'from' => ['id' => $telegramId, 'first_name' => 'Seller'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
        ], $extra);
        if ($text !== '') {
            $message['text'] = $text;
        }

        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => ++$this->updateId,
            'message' => $message,
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function pressButton(string $data, int $telegramId = 555, ?int $chatId = null): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => ++$this->updateId,
            'callback_query' => [
                'id' => 'callback-'.$this->updateId,
                'from' => ['id' => $telegramId, 'first_name' => 'Seller'],
                'message' => ['chat' => ['id' => $chatId ?? $telegramId, 'type' => 'private']],
                'data' => $data,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function lastReply(): SendTelegramMessage
    {
        return Queue::pushed(SendTelegramMessage::class)->last();
    }

    private function finishOnboarding(): string
    {
        $this->send('/start')->assertOk();
        $accept = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->pressButton($accept)->assertOk();
        $this->send('کیف آوا')->assertOk();
        $this->pressButton('onboard:unit:toman')->assertOk();
        $this->send('کیف')->assertOk();
        $this->send('صمیمی')->assertOk();
        $confirm = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->pressButton($confirm)->assertOk();

        return $confirm;
    }

    public function test_seller_can_open_the_menu_and_manage_projects_in_their_own_chat(): void
    {
        $this->send('/menu')->assertOk();
        $this->assertSame('menu:start', $this->lastReply()->buttons[0][0]['callback_data']);
        $this->assertDatabaseCount('users', 0);

        $this->finishOnboarding();
        $this->assertSame('menu:home', $this->lastReply()->buttons[0][0]['callback_data']);
        $this->send('/start')->assertOk();
        $this->assertStringContainsString('منوی کارمند من', $this->lastReply()->text);
        $new = $this->lastReply()->buttons[0][0]['callback_data'];
        $projects = $this->lastReply()->buttons[0][1]['callback_data'];

        $this->pressButton($new, 777, 555)->assertOk();
        $this->assertDatabaseCount('projects', 0);
        $this->pressButton($new)->assertOk();
        $this->assertDatabaseCount('projects', 1);
        $review = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->pressButton($projects)->assertOk();
        $this->assertStringContainsString(Project::sole()->name.' — در حال دریافت ورودی', $this->lastReply()->text);

        $this->send('/product کیف')->assertOk();
        $this->pressButton($review)->assertOk();
        $this->assertDatabaseHas('projects', ['state' => 'review_required']);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_menu_buttons_act_only_as_their_commands_would(): void
    {
        $this->finishOnboarding();
        $this->send('/menu')->assertOk();
        $new = $this->lastReply()->buttons[0][0]['callback_data'];

        // A bundle that is not approved stops every project action, from a button as from /new.
        config(['pilot_policy.directory' => resource_path('policies/pilot-2026-10-27-v3')]);
        foreach ([$new, 'menu:done', 'menu:start'] as $button) {
            $this->pressButton($button)->assertOk();
        }
        $this->assertDatabaseCount('projects', 0);

        // An account whose erasure started gets no project from a stale button either.
        $this->useApprovedPilotPolicy();
        Workspace::query()->update(['status' => 'deletion_pending']);
        $this->pressButton($new)->assertOk();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_review_pressed_twice_finishes_the_batch_once(): void
    {
        $this->finishOnboarding();
        $this->send('/new مجموعه آبان')->assertOk();
        $review = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->send('/product کیف')->assertOk();
        $ledger = DB::table('credit_ledger')->count();

        $this->pressButton($review)->assertOk();
        $this->pressButton($review)->assertOk();

        $this->assertSame('review_required', Project::query()->sole()->state->value);
        $this->assertSame($ledger, DB::table('credit_ledger')->count());
    }

    public function test_stale_project_button_cannot_bypass_new_policy_consent(): void
    {
        $this->finishOnboarding();
        $this->send('/menu')->assertOk();
        $new = $this->lastReply()->buttons[0][0]['callback_data'];

        $this->useApprovedPilotPolicy('test-policy-v2');
        $this->pressButton($new)->assertOk();

        $this->assertDatabaseCount('projects', 0);
        $this->assertStringContainsString('پذیرش نسخه test-policy-v2 لازم است', $this->lastReply()->text);
    }

    public function test_consent_and_confirmed_brand_survive_restart_without_credit_charge_or_duplicate_mutation(): void
    {
        $this->send('/start')->assertOk();
        $this->send('/new قبل از رضایت')->assertOk();
        $this->send('', 555, ['photo' => [['file_id' => 'never-download']]])->assertOk();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('workspace_consents', 0);
        $this->assertDatabaseCount('memory_items', 0);
        $this->assertStringContainsString('فایل تجاری نفرستید', $this->lastReply()->text);

        $accept = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->pressButton($accept)->assertOk();
        $this->assertDatabaseCount('workspace_consents', 1);
        $this->assertDatabaseHas('workspace_consents', ['policy_version' => 'test-policy-v1']);
        $this->send('کیف آوا')->assertOk();
        $this->assertStringNotContainsString('کیف آوا', DB::table('telegram_onboardings')->value('draft'));
        $this->send('/start')->assertOk();
        $this->assertStringContainsString('واحد نمایش قیمت', $this->lastReply()->text);
        $this->pressButton('onboard:unit:toman')->assertOk();
        $skipMessageId = $this->updateId + 1;
        $this->send('/skip')->assertOk();
        $this->send('/skip', 555, ['message_id' => $skipMessageId])
            ->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame('tone', TelegramOnboarding::sole()->step);
        $this->send('صمیمی')->assertOk();
        $confirm = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->assertDatabaseCount('memory_items', 0);
        $this->assertNull(Brand::sole()->name);

        $this->pressButton($confirm)->assertOk();
        $this->pressButton($confirm)->assertOk();
        $this->send('/start')->assertOk();
        $this->assertSame('کیف آوا', Brand::sole()->name);
        $this->assertSame(2, Brand::sole()->profile_version);
        $this->assertDatabaseCount('memory_items', 3);
        $this->assertDatabaseCount('memory_item_revisions', 3);
        $this->send('/new کیف جدید')->assertOk();
        $this->assertPrivateValue(Project::class, ['state' => 'collecting_input'], 'name', 'کیف جدید');
        $this->assertSame(100, Wallet::sole()->balance_cached);
        $this->assertDatabaseCount('credit_ledger', 1);
        Queue::assertPushed(AnswerTelegramCallback::class, 4);
    }

    public function test_revisions_record_edits_and_revocation_and_only_owner_can_resume(): void
    {
        $this->finishOnboarding();
        $this->send('/brand')->assertOk();
        $this->send('کیف جدید')->assertOk();
        $this->pressButton('onboard:unit:irr')->assertOk();
        $this->send('/skip')->assertOk();
        $this->send('رسمی')->assertOk();
        $this->pressButton($this->lastReply()->buttons[0][0]['callback_data'])->assertOk();

        $name = MemoryItem::query()->where('key', 'brand.name')->firstOrFail();
        $this->assertSame(2, $name->revision);
        $this->assertSame(
            ['کیف آوا', 'کیف جدید'],
            MemoryItemRevision::query()->where('memory_item_id', $name->id)
                ->orderBy('revision')->get()->pluck('value_json')->map(fn ($value) => $value['value'])->all()
        );
        $category = MemoryItem::query()->where('key', 'brand.category')->firstOrFail();
        $this->assertSame('deprecated', $category->status);
        $this->assertSame(2, $category->revision);
        $this->send('/forget tone')->assertOk();
        $tone = MemoryItem::query()->where('key', 'brand.tone')->firstOrFail();
        $this->assertSame('deprecated', $tone->status);
        $this->assertNull(Brand::sole()->tone);
        $this->assertDatabaseHas('memory_item_revisions', [
            'memory_item_id' => $tone->id, 'status' => 'deprecated', 'revision' => 3,
        ]);
        $this->send('/forget name')->assertOk();
        $this->assertNull(Brand::sole()->name);
        $this->assertSame('name', TelegramOnboarding::sole()->step);
        $this->send('/new')->assertOk();
        $this->assertDatabaseCount('projects', 0);
        $this->send('/start')->assertOk();
        $this->assertStringContainsString('نام برندتان', $this->lastReply()->text);
    }

    public function test_returning_seller_keeps_the_brand_and_changes_only_one_preference(): void
    {
        $this->finishOnboarding();
        $this->send('/new مجموعه آبان')->assertOk();
        $this->assertStringContainsString('برند «کیف آوا» با قیمت به تومان', $this->lastReply()->text);

        $this->send('/mybrand')->assertOk();
        $this->assertStringContainsString("دسته: کیف\nلحن: صمیمی", $this->lastReply()->text);
        $this->assertSame('onboard:edit', $this->lastReply()->buttons[0][0]['callback_data']);
        $this->pressButton('onboard:edit')->assertOk();
        $this->assertStringContainsString('مقدار فعلی: کیف آوا', $this->lastReply()->text);
        $this->assertSame('onboard:keep:name', $this->lastReply()->buttons[0][0]['callback_data']);
        $this->pressButton('onboard:keep:name')->assertOk();
        $this->assertStringContainsString('مقدار فعلی: تومان', $this->lastReply()->text);
        $keepUnitMessage = $this->updateId + 1;
        $this->send('/keep')->assertOk();
        $this->send('/keep', 555, ['message_id' => $keepUnitMessage])
            ->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->pressButton('onboard:keep:category')->assertOk();
        // A stale keep button for an earlier step cannot skip the current question.
        $this->pressButton('onboard:keep:category')->assertOk();
        $this->assertSame('tone', TelegramOnboarding::sole()->step);
        $this->send('رسمی و کوتاه')->assertOk();
        $this->assertStringContainsString("نام: کیف آوا\nواحد قیمت: تومان\nدسته: کیف\nلحن: رسمی و کوتاه", $this->lastReply()->text);
        $this->pressButton($this->lastReply()->buttons[0][0]['callback_data'])->assertOk();

        $brand = Brand::sole();
        $this->assertSame(['کیف آوا', 'toman', 'کیف', 'رسمی و کوتاه'], [$brand->name, $brand->currency_display_unit, $brand->category, $brand->tone]);
        $this->assertSame(3, $brand->profile_version);
        $revisions = MemoryItem::query()->pluck('revision', 'key')->all();
        $this->assertSame(1, $revisions['brand.name']);
        $this->assertSame(1, $revisions['brand.category']);
        $this->assertSame(2, $revisions['brand.tone']);

        $this->send('/new محصولات تازه')->assertOk();
        $this->assertStringContainsString('ساخته شد', $this->lastReply()->text);
        $this->assertDatabaseCount('projects', 2);
        $this->assertSame(100, Wallet::sole()->balance_cached);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_keep_needs_a_current_answer_and_stays_within_its_own_account(): void
    {
        $this->send('/start')->assertOk();
        $this->pressButton($this->lastReply()->buttons[0][0]['callback_data'])->assertOk();
        // First onboarding has nothing to keep: the required name is still asked.
        $this->send('/keep')->assertOk();
        $this->pressButton('onboard:keep:name')->assertOk();
        $this->assertSame('name', TelegramOnboarding::sole()->step);
        $this->assertSame([], $this->lastReply()->buttons);
        $this->send('کیف آوا')->assertOk();
        $this->pressButton('onboard:keep:unit')->assertOk();
        $this->assertSame('unit', TelegramOnboarding::sole()->step);

        // Another seller's keep reaches only that seller's own onboarding.
        $this->send('/start', 777)->assertOk();
        $this->pressButton($this->lastReply()->buttons[0][0]['callback_data'], 777)->assertOk();
        $this->pressButton('onboard:keep:unit', 777)->assertOk();
        $this->assertSame(['unit', 'name'], TelegramOnboarding::query()->orderBy('id')->pluck('step')->all());
        $this->assertDatabaseCount('memory_items', 0);
    }

    public function test_support_receipts_are_persisted_and_tenant_scoped(): void
    {
        $this->send('/support مشکل در حساب')->assertOk();
        preg_match('/رسید: ([0-9a-f-]{36})/', $this->lastReply()->text, $matches);
        $receipt = $matches[1];
        $this->assertTrue(Str::isUuid($receipt));
        $this->assertSame('مشکل در حساب', ServiceRequest::query()->where('public_id', $receipt)->firstOrFail()->details);
        $this->assertStringNotContainsString('مشکل در حساب', DB::table('service_requests')->value('details'));
        $this->send('/support مشکل در حساب')->assertOk();
        $this->assertStringContainsString($receipt, $this->lastReply()->text);
        $this->send("/ticket {$receipt}")->assertOk();
        $this->assertStringContainsString('received', $this->lastReply()->text);
        $this->assertDatabaseCount('service_requests', 1);
        $this->send("/ticket {$receipt}", 556)->assertOk();
        $this->assertSame('رسید یافت نشد.', $this->lastReply()->text);
    }

    public function test_stale_policy_and_callback_cannot_bypass_consent_or_confirm_wrong_draft(): void
    {
        $this->send('/start')->assertOk();
        $oldButton = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->useApprovedPilotPolicy('test-policy-v2');
        $this->pressButton($oldButton)->assertOk();
        $this->assertDatabaseCount('workspace_consents', 0);
        $newButton = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->pressButton($newButton)->assertOk();
        $this->send('اول')->assertOk();
        $this->pressButton('onboard:unit:toman')->assertOk();
        $this->pressButton('onboard:skip:category')->assertOk();
        $this->pressButton('onboard:skip:category')->assertOk();
        $this->assertSame('tone', TelegramOnboarding::sole()->step);
        $this->pressButton('onboard:skip:tone')->assertOk();
        $stale = $this->lastReply()->buttons[0][0]['callback_data'];
        $this->pressButton('onboard:edit')->assertOk();
        $this->send('دوم')->assertOk();
        $this->pressButton('onboard:unit:irr')->assertOk();
        $this->pressButton('onboard:skip:category')->assertOk();
        $this->pressButton('onboard:skip:tone')->assertOk();
        $this->pressButton($stale)->assertOk();
        $this->assertSame('review', TelegramOnboarding::sole()->step);
        $this->assertDatabaseCount('memory_items', 0);
        $this->pressButton($this->lastReply()->buttons[0][0]['callback_data'])->assertOk();
        $this->assertSame('دوم', Brand::sole()->name);
        $this->pressButton('onboard:confirm:1:0000000000000000', 555, 999)->assertOk();
        $this->assertDatabaseCount('memory_items', 2);
    }
}
