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

    public function entries(Wallet $wallet, int $limit = 50): Collection
    {
        return CreditLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
