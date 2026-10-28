<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\AccountErasure;

use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountErasureRepositoryInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Explicit deletes instead of relying on FK cascades: nullOnDelete tables
 * (events, audit logs) would otherwise keep rows. A schema-wide test checks
 * that no table keeps a row for the purged workspace or user (ADR-014).
 */
final class EloquentAccountErasureRepository implements AccountErasureRepositoryInterface
{
    /** Tables keyed by workspace_id, children before parents. */
    private const WORKSPACE_TABLES = [
        'api_idempotency_records', 'service_requests', 'telegram_onboardings', 'memory_item_revisions',
        'memory_items', 'workspace_consents', 'projects', 'employees', 'brands', 'conversation_events',
        'audit_logs', 'workspace_data_keys', 'workspace_memberships',
    ];

    public function telegramIds(User $user): array
    {
        return UserIdentity::query()->where('user_id', $user->id)->where('provider', 'telegram')->pluck('external_id')->all();
    }

    public function isSingleOwnerAccount(Workspace $workspace, User $user): bool
    {
        // Another member would lose access silently; another workspace of the
        // user would lose its consents, tickets and replays to the FK cascade.
        return $workspace->owner_id === $user->id
            && ! DB::table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', '!=', $user->id)->exists()
            && ! DB::table('workspace_memberships')->where('user_id', $user->id)->where('workspace_id', '!=', $workspace->id)->exists()
            && ! DB::table('workspaces')->where('owner_id', $user->id)->where('id', '!=', $workspace->id)->exists();
    }

    public function purge(Workspace $workspace, User $user, array $subjectKeys): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Account purge must run inside a transaction.');
        }
        if (! $this->isSingleOwnerAccount($workspace, $user)) {
            throw new LogicException('Purge supports single-owner, single-workspace accounts only.');
        }

        if ($subjectKeys !== []) {
            DB::table('conversation_events')->whereIn('subject_key', $subjectKeys)->delete();
        }
        $wallets = DB::table('wallets')->where('workspace_id', $workspace->id)->pluck('id');
        DB::table('credit_ledger')->whereIn('wallet_id', $wallets)->delete();
        DB::table('wallets')->whereIn('id', $wallets)->delete();
        foreach (self::WORKSPACE_TABLES as $table) {
            DB::table($table)->where('workspace_id', $workspace->id)->delete();
        }
        DB::table('workspaces')->where('id', $workspace->id)->delete();

        DB::table('telegram_auth_requests')->where('user_id', $user->id)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        if ($user->email !== null) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }
        DB::table('user_identities')->where('user_id', $user->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
    }
}
