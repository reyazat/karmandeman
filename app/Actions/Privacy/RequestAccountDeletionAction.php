<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\AccountDeletion;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Single erasure entry point for every channel (FR-086, ADR-011). Records T0
 * and a fixed T0+168h deadline, then contains the workspace: no new
 * mutations, API tokens and token replays revoked. Purge itself (FR-087)
 * is not implemented here.
 */
final readonly class RequestAccountDeletionAction
{
    public const DEADLINE_HOURS = 168;

    public function __construct(
        private AccountDeletionRepositoryInterface $deletions,
        private WorkspaceRepositoryInterface $workspaces,
        private UserRepositoryInterface $users,
        private TelegramAuthRequestRepositoryInterface $authRequests,
    ) {}

    /** @return array{AccountDeletion, bool} the request and whether it already existed */
    public function execute(Workspace $workspace, User $user, string $channel): array
    {
        Gate::forUser($user)->authorize('requestDeletion', $workspace);

        return DB::transaction(function () use ($workspace, $user, $channel): array {
            // Global lock order is user, then workspace (as in registration and
            // token issuance); the account_deletions FK also takes a key-share
            // lock on the user row, so the reverse order deadlocks with sign-in.
            $this->authRequests->lock($user);
            $this->workspaces->lock($workspace);
            $existing = $this->deletions->forWorkspace($workspace);
            if ($existing !== null) {
                return [$existing, true];
            }

            $requestedAt = CarbonImmutable::now();
            $deletion = $this->deletions->create(
                $workspace, $user, $channel, $requestedAt, $requestedAt->addHours(self::DEADLINE_HOURS),
            );
            $this->workspaces->markDeletionPending($workspace);
            $this->users->revokeAccessTokens($user);
            $this->authRequests->forgetFor($user);

            return [$deletion, false];
        });
    }
}
