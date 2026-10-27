<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\TelegramOnboarding;
use App\Models\Workspace;

interface TelegramOnboardingRepositoryInterface
{
    public function ensure(Workspace $workspace): TelegramOnboarding;

    public function find(Workspace $workspace): ?TelegramOnboarding;

    public function save(TelegramOnboarding $onboarding, string $step, ?array $draft = null, ?int $expectedVersion = null): void;
}
