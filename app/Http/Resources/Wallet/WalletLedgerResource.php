<?php

declare(strict_types=1);

namespace App\Http\Resources\Wallet;

use App\Data\Wallet\CreditUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditUsage */
final class WalletLedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'balance' => $this->wallet->balance_cached,
            // Held for accepted quotes until their work ends (ADR-039).
            'reserved' => $this->wallet->reserved_cached,
            'available' => $this->wallet->available(),
            'entries' => WalletLedgerEntryResource::collection($this->lines),
        ];
    }
}
