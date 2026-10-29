<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One private input file of a batch (ADR-022). The bytes live encrypted in
 * object storage under storage_key; nothing here identifies the file's content.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $project_id
 * @property string $storage_key
 * @property string $content_mac
 * @property string $mime
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property string $source
 * @property Carbon|null $created_at
 */
class Asset extends Model
{
    use HasPublicId;

    public const SOURCE_API_UPLOAD = 'api_upload';

    protected $fillable = [
        'workspace_id', 'project_id', 'storage_key', 'content_mac', 'mime',
        'size_bytes', 'width', 'height', 'source', 'created_by',
    ];

    protected $hidden = ['storage_key', 'content_mac'];

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
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
