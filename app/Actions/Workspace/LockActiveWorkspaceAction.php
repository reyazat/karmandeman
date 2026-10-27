<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class LockActiveWorkspaceAction
{
    public function __construct(private WorkspaceRepositoryInterface $workspaces) {}

    /**
     * Must run inside the caller's transaction. Re-reads status under the row
     * lock so a request authorized before T0 cannot mutate after it (FR-086).
     */
    public function execute(Workspace $workspace): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LockActiveWorkspaceAction requires an open transaction.');
        }
        $status = $this->workspaces->lock($workspace)->status;
        if ($status !== 'active') {
            throw new DomainException($status === 'deletion_pending' ? 'ACCOUNT_DELETION_PENDING' : 'WORKSPACE_NOT_ACTIVE');
        }
    }
}
