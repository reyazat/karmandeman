<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\TelegramOnboarding;
use App\Models\User;
use App\Models\Workspace;

interface TelegramOnboardingRepositoryInterface
{
    public function ensure(Workspace $workspace): TelegramOnboarding;

    public function hasConsent(Workspace $workspace, string $version, string $documentHash): bool;

    public function accept(Workspace $workspace, User $user, string $version, string $documentHash): void;

    public function save(TelegramOnboarding $onboarding, string $step, ?array $draft = null, ?int $expectedVersion = null): void;
}
