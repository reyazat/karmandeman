<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
use DomainException;

/**
 * New batch input (a photo, a file, a voice or a product) needs spendable
 * credit, gift or bought alike (ADR-048). Nothing is held or spent here: a
 * paid run still holds its quote's cap first (ADR-039). Reading, approving,
 * downloading, /balance, /support and erasure stay open without credit.
 */
final readonly class EnsureInputCreditAction
{
    public function __construct(private WalletRepositoryInterface $wallets) {}

    /** @throws DomainException CREDIT_REQUIRED */
    public function execute(Workspace $workspace): void
    {
        if (($this->wallets->forWorkspace($workspace)?->available() ?? 0) < 1) {
            throw new DomainException('CREDIT_REQUIRED');
        }
    }
}
