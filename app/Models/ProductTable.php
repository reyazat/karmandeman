<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A seller's CSV of products in a batch, waiting for the seller to confirm
 * its column mapping (ADR-040). Only the encrypted file holds its content.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $project_id
 * @property int|null $created_by
 * @property string $storage_key
 * @property string $content_mac
 * @property int $size_bytes
 * @property int $row_count
 * @property string $source
 * @property string|null $source_message_key
 * @property string $status
 * @property int|null $product_count
 * @property Carbon|null $imported_at
 */
class ProductTable extends Model
{
    use HasPublicId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IMPORTED = 'imported';

    public const SOURCE_API = 'api_upload';

    public const SOURCE_TELEGRAM = 'telegram';

    protected $fillable = [
        'workspace_id', 'project_id', 'created_by', 'storage_key', 'content_mac', 'size_bytes',
        'row_count', 'source', 'source_message_key', 'status',
    ];

    protected $hidden = ['storage_key', 'content_mac', 'source_message_key'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'product_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
