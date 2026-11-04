<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One generated output file of one approved snapshot (ADR-052). The bytes are
 * encrypted in private storage under storage_key; a ready file is sent again
 * without being made or paid for again.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $project_id
 * @property int $project_snapshot_id
 * @property int|null $requested_by
 * @property string $kind
 * @property string $status
 * @property int $attempts
 * @property string|null $storage_key
 * @property string|null $content_mac
 * @property int|null $size_bytes
 * @property int $price_credits
 * @property int|null $hold_entry_id
 * @property string|null $error_code
 * @property Carbon|null $generated_at
 */
class Artifact extends Model
{
    use HasPublicId;

    public const KIND_CATALOGUE_PDF = 'catalogue_pdf';

    /** Held and waiting for, or in, generation. */
    public const STATUS_QUEUED = 'queued';

    /** Generated, stored and paid for. */
    public const STATUS_READY = 'ready';

    /** Generation failed; nothing was charged and the seller may ask again. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'project_id', 'project_snapshot_id', 'requested_by', 'kind', 'status', 'attempts',
        'storage_key', 'content_mac', 'size_bytes', 'price_credits', 'hold_entry_id', 'error_code', 'generated_at',
    ];

    protected $hidden = ['storage_key', 'content_mac', 'hold_entry_id'];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ProjectSnapshot::class, 'project_snapshot_id');
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'size_bytes' => 'integer',
            'price_credits' => 'integer',
            'generated_at' => 'datetime',
        ];
    }
}
