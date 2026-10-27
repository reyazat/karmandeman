<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ServiceRequestRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;

final readonly class RecordServiceRequestAction
{
    public function __construct(
        private ServiceRequestRepositoryInterface $requests,
        private WorkspaceRepositoryInterface $workspaces,
    ) {}

    public function execute(Workspace $workspace, User $user, string $kind, string $key, ?string $details = null): ServiceRequest
    {
        return DB::transaction(function () use ($workspace, $user, $kind, $key, $details): ServiceRequest {
            $this->workspaces->lock($workspace);
            if ($kind === 'data_deletion') {
                $pending = $this->requests->pendingDeletionFor($workspace);
                if ($pending !== null) {
                    return $pending;
                }
            }
            if ($kind === 'support' && $details !== null) {
                $pending = $this->requests->pendingSupportFor($workspace, $details);
                if ($pending !== null) {
                    return $pending;
                }
            }

            return $this->requests->record($workspace, $user, $kind, $key, $details);
        });
    }

    public function findFor(Workspace $workspace, string $publicId): ?ServiceRequest
    {
        return $this->requests->findFor($workspace, $publicId);
    }
}
