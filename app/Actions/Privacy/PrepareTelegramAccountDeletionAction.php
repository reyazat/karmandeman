<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Privacy\TelegramDeletionConfirmation;

final readonly class PrepareTelegramAccountDeletionAction
{
    public function __construct(
        private UserRepositoryInterface $users,
        private WorkspaceRepositoryInterface $workspaces,
        private TelegramDeletionConfirmation $confirmation,
    ) {}

    /** Signed confirmation callback data, or null when no account exists. Writes nothing. */
    public function execute(int $telegramId): ?string
    {
        $user = $this->users->findByIdentity('telegram', (string) $telegramId);
        $workspace = $user === null ? null : $this->workspaces->currentFor($user);

        return $workspace === null ? null : $this->confirmation->callbackData($user, $workspace, now()->getTimestamp());
    }
}
