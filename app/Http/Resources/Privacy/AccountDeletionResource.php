<?php

declare(strict_types=1);

namespace App\Http\Resources\Privacy;

use App\Models\AccountDeletion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountDeletion */
final class AccountDeletionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            // Stays "requested" (in progress) until erasure is proven complete (FR-088).
            'status' => $this->status->value,
            'requested_at' => $this->requested_at->toIso8601String(),
            'deadline_at' => $this->deadline_at->toIso8601String(),
        ];
    }
}
