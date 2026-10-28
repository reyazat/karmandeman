<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property ProductStatus $status
 * @property string $public_id
 * @property int|null $project_id
 * @property int $current_revision
 * @property int|null $approved_revision
 * @property bool $needs_confirmation
 * @property bool $price_missing
 * @property bool $image_missing
 */
class Product extends Model
{
    use HasPublicId;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'status',
        'current_revision',
        'approved_revision',
        'needs_confirmation',
        'price_missing',
        'image_missing',
    ];

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
     * Revisions only grow, so the highest one is the current revision.
     *
     * @return HasOne<ProductRevision, $this>
     */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(ProductRevision::class)->latestOfMany('revision');
    }

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'current_revision' => 'integer',
            'approved_revision' => 'integer',
            'needs_confirmation' => 'boolean',
            'price_missing' => 'boolean',
            'image_missing' => 'boolean',
        ];
    }
}
