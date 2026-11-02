<?php

declare(strict_types=1);

namespace App\Data\Wallet;

use App\Enums\CreditLedgerType;
use App\Models\Wallet;

/** A wallet and its latest entries, newest first, each tied to its cause (FR-073). */
final readonly class CreditUsage
{
    /** @param  list<CreditUsageLine>  $lines */
    public function __construct(public Wallet $wallet, public array $lines) {}

    /**
     * The latest charges, newest first.
     *
     * @return list<CreditUsageLine>
     */
    public function charges(int $limit): array
    {
        $charges = array_filter($this->lines, fn (CreditUsageLine $line): bool => $line->entry->type === CreditLedgerType::Capture);

        return array_slice(array_values($charges), 0, $limit);
    }
}
