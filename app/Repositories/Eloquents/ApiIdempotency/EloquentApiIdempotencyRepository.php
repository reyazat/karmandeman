<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\ApiIdempotency;

use App\Models\ApiIdempotencyRecord;
use App\Models\Workspace;
use App\Repositories\Contracts\ApiIdempotencyRepositoryInterface;

final class EloquentApiIdempotencyRepository implements ApiIdempotencyRepositoryInterface
{
    public function find(Workspace $workspace, int $userId, string $operation, string $key): ?ApiIdempotencyRecord
    {
        return ApiIdempotencyRecord::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where('operation', $operation)
            ->where('key', $key)
            ->first();
    }

    public function record(Workspace $workspace, int $userId, string $operation, string $key, string $hash, array $attributes): void
    {
        ApiIdempotencyRecord::create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'operation' => $operation,
            'key' => $key,
            'request_hash' => $hash,
            'result_attributes' => $attributes,
            'created_at' => now(),
        ]);
    }
}
