<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Workspace;

use App\Models\TelegramOnboarding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConsent;
use App\Repositories\Contracts\TelegramOnboardingRepositoryInterface;

final class EloquentTelegramOnboardingRepository implements TelegramOnboardingRepositoryInterface
{
    public function ensure(Workspace $workspace): TelegramOnboarding
    {
        return TelegramOnboarding::firstOrCreate(['workspace_id' => $workspace->id])->refresh();
    }

    public function hasConsent(Workspace $workspace, string $version, string $documentHash): bool
    {
        return WorkspaceConsent::query()->where('workspace_id', $workspace->id)
            ->where('policy_version', $version)->where('policy_document_hash', $documentHash)->exists();
    }

    public function accept(Workspace $workspace, User $user, string $version, string $documentHash): void
    {
        $receipt = WorkspaceConsent::firstOrCreate(
            ['workspace_id' => $workspace->id, 'policy_version' => $version],
            ['user_id' => $user->id, 'accepted_at' => now(), 'policy_document_hash' => $documentHash],
        );
        if ($receipt->policy_document_hash !== $documentHash) {
            throw new \DomainException('POLICY_VERSION_REUSED');
        }
    }

    public function save(TelegramOnboarding $onboarding, string $step, ?array $draft = null, ?int $expectedVersion = null): void
    {
        $onboarding->step = $step;
        $onboarding->draft = $draft;
        $onboarding->expected_brand_version = $expectedVersion;
        $onboarding->save();
    }
}
