<?php

declare(strict_types=1);

namespace App\Http\Resources\Project;

use App\Models\VoiceAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public metadata only; file names, storage keys and content MACs stay private. */
final class VoiceAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var VoiceAsset $voice */
        $voice = $this->resource;

        return [
            'id' => $voice->public_id,
            'mime' => $voice->mime,
            'size_bytes' => $voice->size_bytes,
            'duration_ms' => $voice->duration_ms,
            'source' => $voice->source,
            'created_at' => $voice->created_at?->toIso8601String(),
        ];
    }
}
