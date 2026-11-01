<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A priced offer for paid work on a batch, shown before any credit is held
 * (FR-072, ADR-039). It binds the batch version and photos it priced.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $project_id
 * @property int $created_by
 * @property string $kind
 * @property string $tariff_version
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
        'workspace_id', 'project_id', 'created_by', 'kind', 'tariff_version', 'project_version',
        'input_asset_ids', 'lines', 'credits_total', 'increase_cap_credits', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'project_version' => 'integer',
            'input_asset_ids' => 'array',
            'lines' => 'array',
            'credits_total' => 'integer',
            'increase_cap_credits' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
