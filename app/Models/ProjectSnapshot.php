<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable approved product set of one batch (ADR-018).
 *
 * @property string $public_id
 * @property int $number
 * @property list<array{product_id: int, product: string, revision: int, payload_mac: string}> $items
 * @property string $snapshot_mac
 * @property Carbon $created_at
 */
class ProjectSnapshot extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $fillable = ['workspace_id', 'project_id', 'number', 'items', 'snapshot_mac', 'approved_by', 'created_at'];

    protected $hidden = ['snapshot_mac'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'number' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
