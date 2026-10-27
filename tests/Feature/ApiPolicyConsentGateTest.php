<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

final class ApiPolicyConsentGateTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private function account(int $telegramId): User
    {
        return app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
    }

    private function assertMutationsBlocked(User $user, string $suffix): void
    {
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/projects', ['name' => 'Blocked'], ['Idempotency-Key' => "gate-project-{$suffix}"])
            ->assertForbidden()->assertJsonPath('error.code', 'POLICY_CONSENT_REQUIRED');
        $this->patchJson('/api/v1/brands/current', ['name' => 'Blocked', 'expected_version' => 1], ['Idempotency-Key' => "gate-brand-{$suffix}"])
            ->assertForbidden()->assertJsonPath('error.code', 'POLICY_CONSENT_REQUIRED');
    }

    public function test_unapproved_policy_blocks_api_mutations_but_reads_stay_open(): void
    {
        $user = $this->account(93001);
        $this->assertMutationsBlocked($user, 'unapproved');
        $this->getJson('/api/v1/brands/current')->assertOk();
        $this->getJson('/api/v1/projects')->assertOk();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('api_idempotency_records', 0);
        $this->assertNull(app(BrandRepositoryInterface::class)->forWorkspace(
            app(WorkspaceRepositoryInterface::class)->currentFor($user)
        )?->name);
    }

    public function test_consent_is_per_workspace_and_per_exact_policy_version(): void
    {
        $this->useApprovedPilotPolicy('consent-gate-v1');
        $consenting = $this->account(93002);
        $other = $this->account(93003);
        $this->grantPilotConsent($consenting);

        $this->assertMutationsBlocked($other, 'other');
        Sanctum::actingAs($consenting);
        $this->postJson('/api/v1/projects', ['name' => 'Allowed'], ['Idempotency-Key' => 'gate-project-ok'])->assertCreated();
        $this->patchJson('/api/v1/brands/current', ['name' => 'Allowed', 'expected_version' => 1], ['Idempotency-Key' => 'gate-brand-ok'])->assertOk();

        $this->useApprovedPilotPolicy('consent-gate-v2');
        $this->assertMutationsBlocked($consenting, 'new-version');
        Sanctum::actingAs($consenting);
        $this->postJson('/api/v1/projects', ['name' => 'Allowed'], ['Idempotency-Key' => 'gate-project-ok'])
            ->assertOk()->assertJsonPath('data.name', 'Allowed');
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('api_idempotency_records', 2);
    }
}
