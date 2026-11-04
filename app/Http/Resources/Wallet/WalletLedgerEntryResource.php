<?php

declare(strict_types=1);

namespace App\Http\Resources\Wallet;

use App\Data\Wallet\CreditUsageLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditUsageLine */
final class WalletLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->entry;

        return [
            'id' => $entry->public_id,
            'type' => $entry->type->value,
            'amount' => $entry->amount,
            'held_amount' => $entry->held_amount,
            'reason' => $entry->reason,
            // What the entry paid for (FR-073); null for a gift or other non-run entry. A catalogue file names its project (ADR-052).
            'output_class' => $this->outputClass,
            'run_id' => $this->run?->public_id,
            'project_id' => $this->run->project->public_id ?? $this->project->public_id ?? null,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
