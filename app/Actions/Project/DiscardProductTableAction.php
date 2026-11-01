<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Models\ProductTable;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductTableRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\Storage\PrivateAssetStore;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The seller dropped a CSV before importing it (ADR-040): the row and the
 * encrypted file go. An imported table stays, since its products cite it.
 * Discarding twice is answered as done.
 */
final readonly class DiscardProductTableAction
{
    public function __construct(
        private LockActiveWorkspaceAction $lockActive,
        private ProductTableRepositoryInterface $tables,
        private ProjectRepositoryInterface $projects,
        private PrivateAssetStore $store,
    ) {}

    public function execute(Workspace $workspace, User $user, string $tablePublicId): void
    {
        $storageKey = DB::transaction(function () use ($workspace, $user, $tablePublicId): ?string {
            $this->lockActive->execute($workspace);
            $table = $this->tables->findPublicForWorkspace($workspace, $tablePublicId);
            if ($table === null) {
                return null;
            }
            $project = $this->projects->findForWorkspace($workspace, $table->project_id) ?? throw new DomainException('TABLE_NOT_FOUND');
            Gate::forUser($user)->authorize('update', $project);
            if ($table->status !== ProductTable::STATUS_PENDING) {
                throw new DomainException('TABLE_ALREADY_IMPORTED');
            }
            $this->tables->delete($table);

            return $table->storage_key;
        });
        // After commit: a rollback never leaves a row without its file.
        if ($storageKey !== null) {
            rescue(fn () => $this->store->delete($storageKey));
        }
    }
}
