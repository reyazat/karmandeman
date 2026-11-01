<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $balance_cached
 * @property int $reserved_cached
 */
class Wallet extends Model
{
    protected $fillable = [
        'workspace_id',
        'balance_cached',
        'reserved_cached',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Credit the seller can still spend or reserve (ADR-039). */
    public function available(): int
    {
        return $this->balance_cached - $this->reserved_cached;
    }

    /**
     * @return HasMany<CreditLedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    protected function casts(): array
    {
        return [
            'balance_cached' => 'integer',
            'reserved_cached' => 'integer',
        ];
    }
}
