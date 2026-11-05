<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\TelegramOnboarding;
use App\Models\WorkspaceConsent;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Privacy\PilotPolicyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

final class PilotPolicyTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private function manifest(): array
    {
        return json_decode(file_get_contents(config('pilot_policy.directory').'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeManifest(array $manifest): void
    {
        file_put_contents(config('pilot_policy.directory').'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    public function test_real_bundle_is_blocked_and_identifies_owner_without_fabricated_approval(): void
    {
        $policy = app(PilotPolicyService::class)->current();
        $this->assertSame('pilot-20261027-v3', $policy->version);
        $this->assertFalse($policy->isApproved());
        $this->assertContains('POLICY_NOT_APPROVED', $policy->blockers);
        $this->assertContains('APPROVAL_PRODUCT_OWNER_MISSING', $policy->blockers);
        $this->assertContains('EVIDENCE_COMPLETE_ERASURE_AND_RESTORE_MISSING', $policy->blockers);
        $this->assertSame('وحید ریاضت و تیم کارمند من', $this->manifest()['operator']['name']);
        $this->assertSame('ایران', $this->manifest()['operator']['country']);
        $this->assertStringContainsString('دکمه حذف کامل در پنل هنوز', $policy->privacy);
        $retention = json_decode(file_get_contents(config('pilot_policy.directory').'/retention.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(14, $retention['deletion']['backup_max_age_days']);
        $this->assertSame(7, $retention['deletion']['complete_max_days_from_verified_request']);
        $this->assertContains('RETENTION_NOT_APPROVED', $policy->blockers);
    }

    public function test_approved_fixture_is_bound_to_content_and_telegram_button_is_short(): void
    {
        $this->useApprovedPilotPolicy();
        $policy = app(PilotPolicyService::class)->current();
        $this->assertTrue($policy->isApproved());
        $this->assertSame(64, strlen($policy->digest));
        $this->assertLessThanOrEqual(64, strlen('onboard:accept:'.$policy->consentToken()));
        $this->assertStringContainsString('۹۰ روز', $policy->retention);
        $this->assertStringContainsString('حداکثر ۷ روز از درخواست تأییدشده', $policy->retention);
    }

    public function test_status_flag_alone_cannot_approve_a_bundle(): void
    {
        $this->useApprovedPilotPolicy();
        $manifest = $this->manifest();
        $manifest['approval'] = [];
        $this->writeManifest($manifest);
        $policy = app(PilotPolicyService::class)->current();
        $this->assertFalse($policy->isApproved());
        $this->assertContains('APPROVAL_PRODUCT_OWNER_MISSING', $policy->blockers);
        $this->assertContains('APPROVAL_DIGEST_MISMATCH', $policy->blockers);
    }

    public function test_editing_policy_text_or_retention_breaks_prior_approval(): void
    {
        $this->useApprovedPilotPolicy();
        file_put_contents(config('pilot_policy.directory').'/privacy.fa.txt', "\nتغییر متن", FILE_APPEND);
        $this->assertContains('APPROVAL_DIGEST_MISMATCH', app(PilotPolicyService::class)->current()->blockers);
        $this->useApprovedPilotPolicy('test-policy-v2');
        file_put_contents(config('pilot_policy.directory').'/retention.json', "\n", FILE_APPEND);
        $this->assertContains('APPROVAL_DIGEST_MISMATCH', app(PilotPolicyService::class)->current()->blockers);
    }

    public function test_operator_and_evidence_changes_need_fresh_approval_and_missing_evidence_blocks(): void
    {
        $this->useApprovedPilotPolicy();
        $manifest = $this->manifest();
        $manifest['operator']['country'] = '';
        $manifest['release_evidence']['encryption_and_key_separation'] = null;
        $this->writeManifest($manifest);
        $policy = app(PilotPolicyService::class)->current();
        $this->assertContains('OPERATOR_COUNTRY_MISSING', $policy->blockers);
        $this->assertContains('EVIDENCE_ENCRYPTION_AND_KEY_SEPARATION_MISSING', $policy->blockers);
        $this->assertContains('APPROVAL_DIGEST_MISMATCH', $policy->blockers);
    }

    public function test_only_an_approved_bundle_naming_the_provider_allows_sending_photos_to_it(): void
    {
        // A bundle without ai_processing keeps the digest it had before ADR-037, so earlier consents stay valid.
        config(['pilot_policy.directory' => resource_path('policies/pilot-2026-10-27-v3')]);
        $this->assertSame('cce1616f5da81767a1abae338a0a29b7d526dc34d3b06685f3b7348ba90a37da', app(PilotPolicyService::class)->current()->digest);

        $this->useApprovedPilotPolicy();
        $this->assertTrue(app(PilotPolicyService::class)->current()->isApproved());
        $this->assertFalse(app(PilotPolicyService::class)->current()->allowsAiProvider('google'));

        $this->useApprovedPilotPolicy(aiProviders: ['google']);
        $this->assertTrue(app(PilotPolicyService::class)->current()->allowsAiProvider('google'));
        $this->assertFalse(app(PilotPolicyService::class)->current()->allowsAiProvider('openai'));

        // The provider list is signed: adding one after approval needs a fresh approval (ADR-037).
        $manifest = $this->manifest();
        $manifest['ai_processing']['providers'][] = 'openai';
        $this->writeManifest($manifest);
        $policy = app(PilotPolicyService::class)->current();
        $this->assertContains('APPROVAL_DIGEST_MISMATCH', $policy->blockers);
        $this->assertFalse($policy->allowsAiProvider('google'));

        $manifest['ai_processing']['providers'] = ['Google Gemini'];
        $this->writeManifest($manifest);
        $policy = app(PilotPolicyService::class)->current();
        $this->assertContains('AI_PROCESSING_INVALID', $policy->blockers);
        $this->assertSame([], $policy->aiProviders);
    }

    public function test_voice_intake_requires_a_new_signed_policy_and_old_bundle_hash_is_unchanged(): void
    {
        $this->useApprovedPilotPolicy();
        $old = app(PilotPolicyService::class)->current();
        $this->assertFalse($old->allowsVoiceIntake());

        $this->useApprovedPilotPolicy('test-voice-v1', voiceIntake: true);
        $voice = app(PilotPolicyService::class)->current();
        $this->assertTrue($voice->allowsVoiceIntake());
        $this->assertNotSame($old->digest, $voice->digest);

        $manifest = $this->manifest();
        $manifest['voice_intake'] = ['enabled' => false];
        $this->writeManifest($manifest);
        $changed = app(PilotPolicyService::class)->current();
        $this->assertFalse($changed->allowsVoiceIntake());
        $this->assertContains('VOICE_INTAKE_INVALID', $changed->blockers);
        $this->assertContains('APPROVAL_DIGEST_MISMATCH', $changed->blockers);
    }

    public function test_future_approval_and_invalid_bundle_fail_closed(): void
    {
        $this->useApprovedPilotPolicy();
        $manifest = $this->manifest();
        $manifest['approval']['approved_at'] = '2099-01-01T00:00:00Z';
        $this->writeManifest($manifest);
        $this->assertContains('APPROVAL_DATE_INVALID', app(PilotPolicyService::class)->current()->blockers);
        file_put_contents(config('pilot_policy.directory').'/retention.json', '{broken json');
        $this->assertSame(['POLICY_BUNDLE_INVALID'], app(PilotPolicyService::class)->current()->blockers);
    }

    public function test_read_only_command_reports_candidate_blockers_and_approved_fixture(): void
    {
        $path = config('pilot_policy.directory').'/manifest.json';
        $before = hash_file('sha256', $path);
        $this->artisan('policy:check')->assertFailed();
        $this->assertSame($before, hash_file('sha256', $path));
        $this->assertDatabaseCount('workspace_consents', 0);
        $this->useApprovedPilotPolicy();
        $this->artisan('policy:check')->assertSuccessful();
    }

    public function test_preview_receipt_is_not_promoted_and_new_receipt_records_exact_hash(): void
    {
        $this->useApprovedPilotPolicy();
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(501));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $legacy = WorkspaceConsent::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'policy_version' => 'onboarding-preview-1', 'accepted_at' => now(),
        ]);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $reply = $onboarding->execute($workspace, $user);
        $this->assertStringContainsString('/terms', $reply->text);
        $this->assertNull($legacy->fresh()->policy_document_hash);
        $policy = app(PilotPolicyService::class)->current();
        $onboarding->execute($workspace, $user, 'accept', $policy->consentToken());
        $onboarding->execute($workspace, $user, 'accept', $policy->consentToken());
        $this->assertDatabaseCount('workspace_consents', 2);
        $this->assertDatabaseHas('workspace_consents', ['policy_version' => $policy->version, 'policy_document_hash' => $policy->digest, 'user_id' => $user->id]);
        $this->assertSame('name', TelegramOnboarding::sole()->step);
    }

    public function test_same_version_with_changed_text_refuses_without_failing_the_update(): void
    {
        $this->useApprovedPilotPolicy();
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(506));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $policy = app(PilotPolicyService::class)->current();
        WorkspaceConsent::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id, 'policy_version' => $policy->version,
            'policy_document_hash' => str_repeat('0', 64), 'accepted_at' => now(),
        ]);

        $reply = app(AdvanceTelegramOnboardingAction::class)->execute($workspace, $user, 'accept', $policy->consentToken());

        $this->assertStringContainsString('تغییر کرده', $reply->text);
        $this->assertDatabaseCount('workspace_consents', 1);
        $this->assertDatabaseHas('workspace_consents', ['policy_document_hash' => str_repeat('0', 64)]);
    }

    public function test_accepting_another_workspaces_policy_is_forbidden(): void
    {
        $this->useApprovedPilotPolicy();
        $owner = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(502));
        $other = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(503));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($owner);
        $this->expectException(AuthorizationException::class);
        app(AdvanceTelegramOnboardingAction::class)->execute($workspace, $other, 'accept', $this->policyConsentToken());
    }

    public function test_unapproved_policy_cannot_accept_brand_but_support_and_deletion_remain_available(): void
    {
        config(['services.telegram.webhook_secret' => 'policy-test-secret']);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $i = 0;
        foreach (['/start', '/menu', 'private brand name', '/new', '/privacy', '/support رسیدگی به حساب', '/delete_data'] as $text) {
            $this->postJson('/api/webhooks/telegram', [
                'update_id' => ++$i,
                'message' => ['message_id' => $i, 'text' => $text, 'from' => ['id' => 504], 'chat' => ['id' => 504, 'type' => 'private']],
            ], ['X-Telegram-Bot-Api-Secret-Token' => 'policy-test-secret'])->assertOk();
        }
        $this->assertDatabaseCount('workspace_consents', 0);
        $this->assertDatabaseCount('brands', 0);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('memory_items', 0);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertNull(TelegramOnboarding::sole()->draft);
        Queue::assertPushed(SendTelegramMessage::class, fn ($job) => str_contains($job->text, 'تأیید نشده') && $job->buttons === []);
        $menu = Queue::pushed(SendTelegramMessage::class)->get(1);
        $this->assertStringContainsString('فایل تجاری نفرستید', $menu->text);
        $this->assertSame(['menu:support', 'menu:account'], array_column($menu->buttons[0], 'callback_data'));

        $confirm = Queue::pushed(SendTelegramMessage::class)->last()->buttons[0][0]['callback_data'];
        $this->assertStringStartsWith('delete:confirm:', $confirm);
        $this->postJson('/api/webhooks/telegram', ['update_id' => ++$i, 'callback_query' => [
            'id' => 'deletion-callback', 'data' => $confirm, 'from' => ['id' => 504],
            'message' => ['chat' => ['id' => 504, 'type' => 'private']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'policy-test-secret'])->assertOk();
        $this->assertDatabaseCount('account_deletions', 1);
    }

    public function test_approved_documents_are_accessible_without_consent_and_split_to_safe_messages(): void
    {
        $this->useApprovedPilotPolicy();
        config(['services.telegram.webhook_secret' => 'policy-test-secret']);
        Queue::fake([SendTelegramMessage::class]);
        foreach (['/terms', '/privacy', '/retention'] as $i => $text) {
            $this->postJson('/api/webhooks/telegram', [
                'update_id' => $i + 1,
                'message' => ['text' => $text, 'from' => ['id' => 505], 'chat' => ['id' => 505, 'type' => 'private']],
            ], ['X-Telegram-Bot-Api-Secret-Token' => 'policy-test-secret'])->assertOk();
        }
        $this->assertDatabaseCount('workspace_consents', 0);
        $this->assertDatabaseCount('users', 0);
        $jobs = Queue::pushed(SendTelegramMessage::class);
        $this->assertGreaterThanOrEqual(3, $jobs->count());
        foreach ($jobs as $job) {
            $this->assertLessThanOrEqual(3000, mb_strlen($job->text));
        }
        $this->assertStringContainsString('وحید ریاضت', $jobs->pluck('text')->implode("\n"));
    }
}
