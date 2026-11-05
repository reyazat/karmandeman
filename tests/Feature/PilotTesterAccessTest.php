<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\Privacy\PilotPolicy;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceConsent;
use App\Services\AI\AIWorkflowService;
use App\Services\Privacy\PilotPolicyService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Owner-invited testers use the unapproved draft; nobody else gets past the closed notice (ADR-057). */
final class PilotTesterAccessTest extends TestCase
{
    private const SECRET = 'tester-secret';

    private const TESTER = 7001;

    private const STRANGER = 7002;

    private int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            // The published bundle is the real, unapproved v3; the test bundle is the real v5 draft.
            'pilot_policy.directory' => resource_path('policies/pilot-2026-10-27-v3'),
            'pilot_policy.test_directory' => resource_path('policies/pilot-2026-11-05-v5'),
            'pilot_policy.tester_telegram_ids' => [(string) self::TESTER],
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'karmandeman.gift_credits' => 100,
        ]);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    private function send(string $text, int $telegramId): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', ['update_id' => ++$this->updateId, 'message' => [
            'message_id' => $this->updateId, 'date' => time(), 'text' => $text,
            'from' => ['id' => $telegramId, 'first_name' => 'U'], 'chat' => ['id' => $telegramId, 'type' => 'private'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function press(string $data, int $telegramId): void
    {
        $this->postJson('/api/webhooks/telegram', ['update_id' => ++$this->updateId, 'callback_query' => [
            'id' => 'cb-'.$this->updateId, 'data' => $data, 'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'message' => ['chat' => ['id' => $telegramId, 'type' => 'private']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function last(): SendTelegramMessage
    {
        return Queue::pushed(SendTelegramMessage::class)->last();
    }

    private function onboard(int $telegramId): void
    {
        $this->send('/start', $telegramId);
        $this->press($this->last()->buttons[0][0]['callback_data'], $telegramId);
        $this->send('فروشگاه نسترن', $telegramId);
        $this->press('onboard:unit:toman', $telegramId);
        $this->press('onboard:skip:category', $telegramId);
        $this->press('onboard:skip:tone', $telegramId);
        $this->press($this->last()->buttons[0][0]['callback_data'], $telegramId);
    }

    public function test_a_tester_accepts_the_marked_draft_and_starts_a_project_while_others_stay_closed(): void
    {
        $this->onboard(self::TESTER);
        $consent = WorkspaceConsent::query()->sole();
        $this->assertSame('pilot-20261105-v5-test', $consent->policy_version);
        $this->send('/new مجموعه آزمایشی', self::TESTER);
        $this->assertSame(1, Project::query()->count());

        $before = Queue::pushed(SendTelegramMessage::class)->count();
        $this->send('/terms', self::TESTER);
        // A long document goes in several messages; the first names the test version and notice.
        $first = Queue::pushed(SendTelegramMessage::class)->values()->get($before)->text;
        $this->assertStringContainsString('pilot-20261105-v5-test', $first);
        $this->assertStringContainsString(PilotPolicy::TEST_NOTICE, $first);

        // Anyone else meets the closed notice and leaves no consent or project.
        $this->send('/start', self::STRANGER);
        $this->assertStringContainsString('هنوز برای استفاده عمومی تأیید نشده', $this->last()->text);
        $this->send('/new پروژه', self::STRANGER);
        $this->send('/terms', self::STRANGER);
        $this->assertStringContainsString('هنوز برای استفاده عمومی تأیید نشده', $this->last()->text);
        $this->assertSame(1, WorkspaceConsent::query()->count());
        $this->assertSame(1, Project::query()->count());
    }

    public function test_a_tester_removed_from_the_list_loses_access_and_the_test_digest_never_matches_a_published_one(): void
    {
        $this->onboard(self::TESTER);
        $policies = app(PilotPolicyService::class);
        $workspace = Workspace::query()->sole();
        $test = $policies->forWorkspace($workspace);
        $this->assertTrue($test->testOnly);
        $this->assertTrue($test->isApproved());

        // The same draft read as the published bundle has another version and digest, and stays blocked.
        config(['pilot_policy.directory' => resource_path('policies/pilot-2026-11-05-v5')]);
        $published = $policies->current();
        $this->assertNotSame($test->digest, $published->digest);
        $this->assertNotSame($test->version, $published->version);
        $this->assertFalse($published->isApproved());

        config(['pilot_policy.tester_telegram_ids' => []]);
        $this->assertFalse($policies->forWorkspace($workspace)->testOnly);
        $this->send('/new پروژه دوم', self::TESTER);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_extraction_runs_in_production_only_for_a_tester_workspace(): void
    {
        config([
            'karmandeman.ai.extraction_enabled' => true,
            'karmandeman.ai.workflow_version' => AIWorkflowService::OPENAI_WORKFLOW_VERSION,
            'services.n8n.extraction_webhook_path' => AIWorkflowService::OPENAI_WEBHOOK_PATH,
        ]);
        $this->onboard(self::TESTER);
        $this->send('/start', self::STRANGER);
        [$tester, $stranger] = Workspace::query()->orderBy('id')->get()->all();

        $this->app['env'] = 'production';
        $ai = app(AIWorkflowService::class);
        $this->assertTrue($ai->extractionEnabled($tester));
        $this->assertFalse($ai->extractionEnabled($stranger));

        config(['karmandeman.ai.workflow_version' => 'product-extraction.v1']);
        $this->assertFalse($ai->extractionEnabled($tester));
        $this->app['env'] = 'testing';
    }

    public function test_a_malformed_list_or_directory_admits_nobody(): void
    {
        $policies = app(PilotPolicyService::class);
        config(['pilot_policy.test_directory' => '']);
        $this->assertFalse($policies->isTester(self::TESTER));

        // A test bundle that is broken, not merely unapproved, is never waived.
        config(['pilot_policy.test_directory' => resource_path('policies/missing-bundle')]);
        $policy = $policies->forTelegramId(self::TESTER);
        $this->assertFalse($policy->isApproved());
        $this->assertFalse($policy->testOnly);
    }
}
