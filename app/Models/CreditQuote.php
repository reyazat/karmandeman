<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Services\Billing\CreditTariff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A priced offer for paid work on a batch, shown before any credit is held
 * (FR-072, ADR-039). It binds the batch version and photos it priced. Under
 * a usage tariff (ADR-042) the total is an estimate; the cap is what is held
 * and the most the seller pays.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $project_id
 * @property int $created_by
 * @property string $kind
 * @property string $tariff_version
 * @property array{version: string, cap_percent: int, markup_percent?: int, minimum: array<string, int>, rates: array{input_tokens_per_million: int, output_tokens_per_million: int, model_request: int}}|null $tariff
 * @property int $project_version
 * @property list<string> $input_asset_ids
 * @property list<array{type: string, quantity: int, unit_credits: int, credits: int}> $lines
 * @property int $credits_total
 * @property int $increase_cap_credits
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class CreditQuote extends Model
{
    use HasPublicId;

    public const KIND_PRODUCT_EXTRACTION = 'product_extraction';

    protected $fillable = [
        'workspace_id', 'project_id', 'created_by', 'kind', 'tariff_version', 'tariff', 'project_version',
        'input_asset_ids', 'lines', 'credits_total', 'increase_cap_credits', 'expires_at',
    ];

    /** What accepting the quote holds: the estimate and its headroom. */
    public function capCredits(): int
    {
        return $this->credits_total + $this->increase_cap_credits;
    }

    /** The least the extraction costs under its usage tariff; null for a fixed-price quote of ADR-039. */
    public function minimumCredits(): ?int
    {
        return $this->tariff === null ? null : max(1, (int) ($this->tariff['minimum'][CreditTariff::CLASS_FILE_TO_TEXT] ?? 1));
    }

    /** Karmandeman's share on top of usage under this quote's tariff (ADR-048); none before it existed. */
    public function markupPercent(): int
    {
        return max(0, (int) ($this->tariff['markup_percent'] ?? 0));
    }

    protected function casts(): array
    {
        return [
            'project_version' => 'integer',
            'input_asset_ids' => 'array',
            'tariff' => 'array',
            'lines' => 'array',
            'credits_total' => 'integer',
            'increase_cap_credits' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
