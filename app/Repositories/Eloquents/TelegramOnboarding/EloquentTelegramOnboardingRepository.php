<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\TelegramOnboarding;

use App\Models\TelegramOnboarding;
use App\Models\Workspace;
use App\Repositories\Contracts\TelegramOnboardingRepositoryInterface;

final class EloquentTelegramOnboardingRepository implements TelegramOnboardingRepositoryInterface
{
    public function ensure(Workspace $workspace): TelegramOnboarding
    {
        return TelegramOnboarding::firstOrCreate(['workspace_id' => $workspace->id])->refresh();
    }

    public function find(Workspace $workspace): ?TelegramOnboarding
    {
        return TelegramOnboarding::query()->where('workspace_id', $workspace->id)->first();
    }

    public function save(TelegramOnboarding $onboarding, string $step, ?array $draft = null, ?int $expectedVersion = null): void
    {
        $onboarding->step = $step;
        $onboarding->draft = $draft;
        $onboarding->expected_brand_version = $expectedVersion;
        $onboarding->save();
    }
}
