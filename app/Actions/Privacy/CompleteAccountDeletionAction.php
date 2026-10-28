<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\AccountDeletionStatus;
use App\Models\AccountDeletion;
use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marks a purged request completed once every backup that could still hold
 * the account has expired (FR-087/088, ADR-014). Without Ops evidence that
 * the backup caps are enforced, the receipt stays in progress.
 */
final readonly class CompleteAccountDeletionAction
{
    public function __construct(private AccountDeletionRepositoryInterface $deletions) {}

    public function execute(AccountDeletion $deletion, CarbonImmutable $now): bool
    {
        if (! config('karmandeman.erasure.backup_lifecycle_verified')) {
            return false;
        }

        return DB::transaction(function () use ($deletion, $now): bool {
            $locked = $this->deletions->lock($deletion);
            if ($locked->status !== AccountDeletionStatus::AwaitingBackupExpiry || $locked->live_purged_at === null || $locked->key_destroyed_at === null) {
                return false;
            }
            $databaseBackupsGone = $locked->live_purged_at->addHours((int) config('karmandeman.erasure.database_backup_max_age_hours'));
            $keyBackupsGone = $locked->key_destroyed_at->addHours((int) config('karmandeman.erasure.key_backup_max_age_hours'));
            if ($now->lessThan($databaseBackupsGone) || $now->lessThan($keyBackupsGone)) {
                return false;
            }
            $this->deletions->markCompleted($locked, $now);

            return true;
        });
    }
}
