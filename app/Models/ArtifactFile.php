<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * One story image of an output pack (ADR-053), stored encrypted under its own
 * public id. Position 1 is the first approved product of the snapshot.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $artifact_id
 * @property int $position
 * @property string $storage_key
 * @property string $content_mac
 * @property int $size_bytes
 */
class ArtifactFile extends Model
{
    use HasPublicId;

    protected $fillable = ['public_id', 'workspace_id', 'artifact_id', 'position', 'storage_key', 'content_mac', 'size_bytes'];

    protected $hidden = ['storage_key', 'content_mac'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'size_bytes' => 'integer',
        ];
    }
}
