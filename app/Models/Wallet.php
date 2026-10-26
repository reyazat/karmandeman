<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'workspace_id',
        'balance_cached',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
        ];
    }
}
