<?php

declare(strict_types=1);

namespace App\Http\Resources\Wallet;

use App\Models\CreditLedgerEntry;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin Wallet */
final class WalletLedgerResource extends JsonResource
{
    /** @var Collection<int, CreditLedgerEntry>|null */
    private ?Collection $entries = null;

    /**
     * @param  Collection<int, CreditLedgerEntry>  $entries  latest entries, newest first
     */
    public function withEntries(Collection $entries): self
    {
        $this->entries = $entries;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'balance' => $this->balance_cached,
            'entries' => WalletLedgerEntryResource::collection($this->entries ?? collect()),
        ];
    }
}
