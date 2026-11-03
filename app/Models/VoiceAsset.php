<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An encrypted, untranscribed source voice; no provider data is stored here. */
final class VoiceAsset extends Model
{
    use HasPublicId;

    public const SOURCE_API = 'api_upload';

    public const SOURCE_TELEGRAM = 'telegram';

    protected $fillable = [
        'workspace_id', 'project_id', 'created_by', 'storage_key', 'content_mac',
        'mime', 'size_bytes', 'duration_ms', 'source', 'source_message_key',
    ];

    protected $hidden = ['storage_key', 'content_mac', 'source_message_key'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'duration_ms' => 'integer'];
    }
}
