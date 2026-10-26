<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ApiIdempotencyRecord;
use App\Models\Workspace;

interface ApiIdempotencyRepositoryInterface
{
    public function find(Workspace $workspace, int $userId, string $operation, string $key): ?ApiIdempotencyRecord;

    public function record(Workspace $workspace, int $userId, string $operation, string $key, string $hash, array $attributes): void;
}
