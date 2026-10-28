<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\WorkspaceEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable once written; only the approval stamp is set later (ADR-016).
 *
 * @property int $revision
 * @property array<string, mixed> $payload
 * @property string|null $sku_mac
 * @property Carbon|null $approved_at
 */
class ProductRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'product_id', 'revision', 'payload', 'payload_mac', 'sku_mac',
        'created_by', 'approved_at', 'approved_by', 'created_at',
    ];

    protected $hidden = ['payload_mac', 'sku_mac'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => WorkspaceEncrypted::class.':array',
            'revision' => 'integer',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
