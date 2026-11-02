<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\WalletLedger;

use App\Enums\CreditLedgerType;
use App\Models\CreditLedgerEntry;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentWalletRepository implements WalletRepositoryInterface
{
    public function findEntry(Wallet $wallet, string $idempotencyKey): ?CreditLedgerEntry
    {
        return CreditLedgerEntry::query()->where('wallet_id', $wallet->id)->where('idempotency_key', $idempotencyKey)->first();
    }

    public function ensureWallet(Workspace $workspace): Wallet
    {
        return Wallet::firstOrCreate(['workspace_id' => $workspace->id]);
    }

    public function forWorkspace(Workspace $workspace): ?Wallet
    {
        return Wallet::query()->where('workspace_id', $workspace->id)->first();
    }

    public function appendEntry(
        Wallet $wallet,
        CreditLedgerType $type,
        int $amount,
        string $idempotencyKey,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $reason = null,
    ): CreditLedgerEntry {
        // Reservation/capture/release require their own lifecycle, not signed deltas.
        if ($type !== CreditLedgerType::Grant || $amount <= 0 || $idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new DomainException('UNSUPPORTED_LEDGER_OPERATION');
        }

        return DB::transaction(function () use ($wallet, $type, $amount, $idempotencyKey, $relatedType, $relatedId, $reason): CreditLedgerEntry {
            /** @var Wallet $locked */
            $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

            $existing = CreditLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->wallet_id !== $locked->id || $existing->type !== $type || $existing->amount !== $amount
                    || $existing->related_type !== $relatedType || $existing->related_id !== $relatedId || $existing->reason !== $reason) {
                    throw new DomainException('IDEMPOTENCY_KEY_REUSED');
                }

                return $existing;
            }

            $entry = CreditLedgerEntry::create([
                'wallet_id' => $locked->id,
                'type' => $type->value,
                'amount' => $amount,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
            ]);

            $locked->increment('balance_cached', $amount);

            return $entry;
        });
    }

    public function findEntryById(int $id): ?CreditLedgerEntry
    {
        return CreditLedgerEntry::query()->find($id);
    }

    public function lockForWorkspace(Workspace $workspace): ?Wallet
    {
        return Wallet::query()->where('workspace_id', $workspace->id)->lockForUpdate()->first();
    }

    public function lock(int $walletId): Wallet
    {
        return Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();
    }

    public function record(
        Wallet $locked,
        CreditLedgerType $type,
        int $amount,
        int $heldAmount,
        string $idempotencyKey,
        string $relatedType,
        int $relatedId,
        string $reason,
    ): CreditLedgerEntry {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger entries are recorded inside the caller\'s transaction.');
        }
        $entry = CreditLedgerEntry::create([
            'wallet_id' => $locked->id,
            'type' => $type->value,
            'amount' => $amount,
            'held_amount' => $heldAmount,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'idempotency_key' => $idempotencyKey,
            'reason' => $reason,
        ]);
        $locked->forceFill([
            'balance_cached' => $locked->balance_cached + $amount,
            'reserved_cached' => $locked->reserved_cached + $heldAmount,
        ])->save();

        return $entry;
    }

    public function entries(Wallet $wallet, int $limit = 50): Collection
    {
        return CreditLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function cacheDrift(): array
    {
        $sums = DB::table('credit_ledger')
            ->select('wallet_id')
            ->selectRaw('SUM(amount) AS ledger_amount, SUM(held_amount) AS ledger_held')
            ->groupBy('wallet_id');

        return DB::table('wallets')
            ->leftJoinSub($sums, 'sums', 'sums.wallet_id', '=', 'wallets.id')
            ->select('wallets.id', 'wallets.balance_cached', 'wallets.reserved_cached')
            ->selectRaw('COALESCE(sums.ledger_amount, 0) AS ledger_amount, COALESCE(sums.ledger_held, 0) AS ledger_held')
            ->whereRaw('wallets.balance_cached <> COALESCE(sums.ledger_amount, 0) OR wallets.reserved_cached <> COALESCE(sums.ledger_held, 0)')
            ->orderBy('wallets.id')
            ->get()
            ->map(fn (object $row): array => [
                'wallet_id' => (int) $row->id,
                'balance_cached' => (int) $row->balance_cached,
                'reserved_cached' => (int) $row->reserved_cached,
                'ledger_amount' => (int) $row->ledger_amount,
                'ledger_held' => (int) $row->ledger_held,
            ])
            ->all();
    }

    public function holdsWithSettlements(): iterable
    {
        $rows = DB::table('credit_ledger AS hold')
            ->join('wallets', 'wallets.id', '=', 'hold.wallet_id')
            ->leftJoin('credit_ledger AS settled', function ($join): void {
                $join->on('settled.wallet_id', '=', 'hold.wallet_id')
                    ->whereRaw("settled.idempotency_key = 'credit:settle:' || hold.id");
            })
            ->leftJoin('ai_workflow_runs AS run', function ($join): void {
                $join->on('run.id', '=', 'hold.related_id')->where('hold.related_type', '=', CreditLedgerEntry::RELATED_RUN);
            })
            ->where('hold.type', CreditLedgerType::Reserve->value)
            ->select([
                'hold.public_id AS hold_id', 'hold.wallet_id', 'hold.held_amount AS held', 'hold.related_type', 'hold.related_id',
                'settled.type AS settlement', 'settled.amount AS settled_amount', 'settled.held_amount AS settled_held',
                'settled.related_type AS settled_related_type', 'settled.related_id AS settled_related_id',
                'run.status AS run_status', 'run.workspace_id AS run_workspace_id', 'wallets.workspace_id AS wallet_workspace_id',
            ])
            ->orderBy('hold.id')
            ->cursor();

        foreach ($rows as $row) {
            $nullable = fn (mixed $value): ?int => $value === null ? null : (int) $value;
            yield [
                'hold_id' => (string) $row->hold_id,
                'wallet_id' => (int) $row->wallet_id,
                'held' => (int) $row->held,
                'settlement' => $row->settlement === null ? null : (string) $row->settlement,
                'settled_amount' => $nullable($row->settled_amount),
                'settled_held' => $nullable($row->settled_held),
                'same_target' => $row->settlement === null
                    || ($row->settled_related_type === $row->related_type && $nullable($row->settled_related_id) === $nullable($row->related_id)),
                'run_status' => $row->run_status === null ? null : (string) $row->run_status,
                'run_workspace_id' => $nullable($row->run_workspace_id),
                'wallet_workspace_id' => (int) $row->wallet_workspace_id,
                'related_run' => $row->related_type === CreditLedgerEntry::RELATED_RUN,
            ];
        }
    }

    public function settlementsWithoutHold(): array
    {
        return DB::table('credit_ledger AS settled')
            ->leftJoin('credit_ledger AS hold', function ($join): void {
                $join->on('hold.wallet_id', '=', 'settled.wallet_id')
                    ->where('hold.type', '=', CreditLedgerType::Reserve->value)
                    ->whereRaw("settled.idempotency_key = 'credit:settle:' || hold.id");
            })
            ->whereIn('settled.type', [CreditLedgerType::Capture->value, CreditLedgerType::Release->value])
            ->whereNull('hold.id')
            ->orderBy('settled.id')
            ->get(['settled.public_id', 'settled.wallet_id'])
            ->map(fn (object $row): array => ['entry_id' => (string) $row->public_id, 'wallet_id' => (int) $row->wallet_id])
            ->all();
    }

    public function countWallets(): int
    {
        return Wallet::query()->count();
    }
}
