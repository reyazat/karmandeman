<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Workspace;

interface ServiceRequestRepositoryInterface
{
    public function record(Workspace $workspace, User $user, string $kind, string $key, ?string $details = null): ServiceRequest;

    public function findFor(Workspace $workspace, string $publicId): ?ServiceRequest;

    public function pendingSupportFor(Workspace $workspace, string $details): ?ServiceRequest;
}
