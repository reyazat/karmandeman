<?php

declare(strict_types=1);

namespace App\Http\Resources\Project;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No storage key, digest or URL: the file is only reached through the download endpoint.
 *
 * @mixin Asset
 */
final class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
