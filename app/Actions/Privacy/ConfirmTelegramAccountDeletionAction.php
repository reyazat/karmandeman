<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\AccountDeletion;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Privacy\TelegramDeletionConfirmation;

final readonly class ConfirmTelegramAccountDeletionAction
{
    public function __construct(
        private UserRepositoryInterface $users,
        private WorkspaceRepositoryInterface $workspaces,
        private TelegramDeletionConfirmation $confirmation,
        private RequestAccountDeletionAction $requestDeletion,
    ) {}

    /** The deletion receipt, or null when the click is forged, stale or not this account's. */
    public function execute(int $telegramId, string $callbackData): ?AccountDeletion
    {
        $user = $this->users->findByIdentity('telegram', (string) $telegramId);
        $workspace = $user === null ? null : $this->workspaces->currentFor($user);
        if ($workspace === null || ! $this->confirmation->isValid($user, $workspace, $callbackData, now()->getTimestamp())) {
            return null;
        }

        return $this->requestDeletion->execute($workspace, $user, 'telegram')[0];
    }
}
