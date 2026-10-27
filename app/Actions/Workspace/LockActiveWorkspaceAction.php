<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;

final readonly class LockActiveWorkspaceAction
{
    public function __construct(private WorkspaceRepositoryInterface $workspaces) {}

    /**
     * Must run inside the caller's transaction. Re-reads status under the row
     * lock so a request authorized before T0 cannot mutate after it (FR-086).
     */
    public function execute(Workspace $workspace): void
    {
        if ($this->workspaces->lock($workspace)->status !== 'active') {
            throw new DomainException('ACCOUNT_DELETION_PENDING');
        }
    }
}
