<?php

declare(strict_types=1);

namespace App\Http\Resources\Project;

use App\Data\Project\ProjectActivity;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 *
 * @property-read ProjectActivity $activity set by the project list, never loaded from the database
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'state' => $this->state->value,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // The project list only (FR-080): files, live products and credit charged.
            'activity' => $this->whenLoaded('activity', fn (): array => [
                'photos' => $this->activity->photos,
                'tables' => $this->activity->tables,
                'voices' => $this->activity->voices,
                'products' => $this->activity->products,
                'credits_used' => $this->activity->creditsUsed,
            ]),
        ];
    }
}
