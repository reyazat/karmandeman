<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\AccountDeletionStatus;
use App\Models\AccountDeletion;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use App\Repositories\Contracts\AccountErasureRepositoryInterface;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;
use App\Repositories\Contracts\TelegramOutboxRepositoryInterface;
use App\Repositories\Contracts\WorkspaceDataKeyRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Crypto\WorkspaceKeyManager;
use App\Services\Telegram\TelegramSubjectKey;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Live purge of FR-087 on a request recorded by FR-086 (ADR-014): destroy
 * the workspace's Transit key ring, then delete every live row of the
 * account and detach the receipt. Every precondition is checked under lock
 * before the irreversible destroy. Safe to retry at any step; T0 and the
 * deadline never move.
 */
final readonly class PurgeAccountDataAction
{
    public function __construct(
        private AccountDeletionRepositoryInterface $deletions,
        private AccountErasureRepositoryInterface $erasure,
        private TelegramAuthRequestRepositoryInterface $authRequests,
        private WorkspaceRepositoryInterface $workspaces,
        private WorkspaceDataKeyRepositoryInterface $dataKeys,
        private TelegramOutboxRepositoryInterface $outbox,
        private TelegramSubjectKey $subjects,
        private WorkspaceFieldCipher $cipher,
        private WorkspaceKeyManager $keys,
    ) {}

    public function execute(AccountDeletion $deletion): AccountDeletion
    {
        // The stored key name is recorded before its row goes, so a retry after
        // a partial run can still destroy it. A workspace without a stored key
        // may still have a Transit key orphaned by a rolled back creation.
        $deletion = $this->underLocks($deletion, function (AccountDeletion $locked, Workspace $workspace): AccountDeletion {
            if ($locked->transit_key === null) {
                $stored = $this->dataKeys->forWorkspace($workspace)?->transit_key;
                $this->deletions->rememberTransitKey($locked, $stored ?? $this->cipher->keyName($workspace));
            }

            return $locked;
        });
        if ($deletion->status !== AccountDeletionStatus::Requested) {
            return $deletion;
        }
        if ($deletion->key_destroy_started_at === null && $deletion->key_destroyed_at === null) {
            $deletion = $this->confirmKey($deletion, $this->keys->exists((string) $deletion->transit_key));
        }

        if ($deletion->key_destroyed_at === null) {
            // External and irreversible: outside any transaction. A key that is
            // gone now was destroyed by an earlier run of this purge.
            $this->keys->destroy((string) $deletion->transit_key);
            $this->deletions->markKeyDestroyed($deletion, CarbonImmutable::now());
        }

        return $this->underLocks($deletion, function (AccountDeletion $locked, Workspace $workspace, User $user): AccountDeletion {
            $telegramIds = $this->erasure->telegramIds($user);
            foreach ($telegramIds as $telegramId) {
                $this->outbox->forgetChat($telegramId);
            }
            $this->erasure->purge($workspace, $user, array_map($this->subjects->for(...), $telegramIds));
            $purgedAt = CarbonImmutable::now();
            $this->deletions->markLivePurged($locked, $purgedAt);

            $backupExpiry = $purgedAt->addHours((int) config('karmandeman.erasure.database_backup_max_age_hours'));
            if ($backupExpiry->greaterThan($locked->deadline_at)) {
                // A backup taken before this purge outlives the deadline (ADR-014).
                Log::critical('privacy.backup_window_exceeds_deadline', ['receipt' => $locked->public_id]);
            }

            return $locked;
        });
    }

    /**
     * key_destroy_started_at is set only once the key store has confirmed the
     * key under the recorded name, and always before destroy is called, so a
     * later "not found" can only mean this purge already destroyed it. A stored
     * data key whose Transit key is missing (wrong name or mount) is never
     * marked: every retry fails again and the real key is never counted as gone.
     */
    private function confirmKey(AccountDeletion $deletion, bool $exists): AccountDeletion
    {
        $missing = false;
        $deletion = $this->underLocks($deletion, function (AccountDeletion $locked, Workspace $workspace) use ($exists, &$missing): AccountDeletion {
            if ($locked->key_destroy_started_at !== null || $locked->key_destroyed_at !== null) {
                return $locked;
            }
            if ($exists) {
                $this->deletions->markKeyDestroyStarted($locked, CarbonImmutable::now());
            } elseif ($this->dataKeys->forWorkspace($workspace) === null) {
                // No data key and no orphaned key: nothing was created under the name.
                $this->deletions->markKeyDestroyed($locked, CarbonImmutable::now());
            } else {
                $missing = true;
            }

            return $locked;
        });
        if ($missing) {
            throw new LogicException('Transit key for a stored data key was not found.');
        }

        return $deletion;
    }

    /**
     * Locks user, workspace and receipt in the global order, checks every
     * purge precondition, then runs the step. A receipt another pass already
     * moved on is returned untouched.
     *
     * @param  Closure(AccountDeletion, Workspace, User): AccountDeletion  $step
     */
    private function underLocks(AccountDeletion $deletion, Closure $step): AccountDeletion
    {
        return DB::transaction(function () use ($deletion, $step): AccountDeletion {
            $user = $deletion->user;
            $workspace = $deletion->workspace;
            if ($user === null || $workspace === null) {
                return $this->settled($deletion);
            }
            try {
                $this->authRequests->lock($user);
                $workspace = $this->workspaces->lock($workspace);
            } catch (ModelNotFoundException $exception) {
                // A concurrent pass purged the rows while this one waited.
                return $this->settled($deletion, $exception);
            }
            $locked = $this->deletions->lock($deletion);
            if ($locked->status !== AccountDeletionStatus::Requested) {
                return $locked;
            }
            if ($workspace->status !== 'deletion_pending') {
                throw new LogicException('Only a contained workspace can be purged.');
            }
            if (! $this->erasure->isSingleOwnerAccount($workspace, $user)) {
                throw new LogicException('Purge supports single-owner, single-workspace accounts only.');
            }

            return $step($locked, $workspace, $user);
        });
    }

    private function settled(AccountDeletion $deletion, ?ModelNotFoundException $cause = null): AccountDeletion
    {
        $current = $this->deletions->lock($deletion);
        if ($current->status === AccountDeletionStatus::Requested) {
            throw $cause ?? new LogicException('Requested deletion has no workspace or user.');
        }

        return $current;
    }
}
