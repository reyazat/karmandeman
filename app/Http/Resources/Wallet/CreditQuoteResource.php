<?php

declare(strict_types=1);

namespace App\Http\Resources\Wallet;

use App\Models\CreditQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditQuote */
final class CreditQuoteResource extends JsonResource
{
    private ?int $available = null;

    /** The credit the seller could hold when the quote was answered. */
    public function withAvailable(int $available): self
    {
        $this->available = $available;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'tariff_version' => $this->tariff_version,
            'project_version' => $this->project_version,
            'photo_ids' => $this->input_asset_ids,
            'lines' => $this->lines,
            'credits_total' => $this->credits_total,
            'increase_cap_credits' => $this->increase_cap_credits,
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted' => $this->accepted_at !== null,
            'available_credits' => $this->available,
        ];
    }
}
