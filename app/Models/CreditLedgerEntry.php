<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditLedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property CreditLedgerType $type
 * @property Carbon|null $created_at
 */
class CreditLedgerEntry extends Model
{
    protected $table = 'credit_ledger';

    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'related_type',
        'related_id',
        'idempotency_key',
        'reason',
        'created_at',
    ];

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    protected function casts(): array
    {
        return [
            'type' => CreditLedgerType::class,
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
