<?php

declare(strict_types=1);

namespace App\Http\Resources\Workspace;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workspace */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status,
            'credit_balance' => $this->whenLoaded('wallet', fn () => $this->wallet === null ? 0 : $this->wallet->balance_cached),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
