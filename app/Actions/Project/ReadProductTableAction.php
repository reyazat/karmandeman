<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Product\ParsedProductTable;
use App\Models\ProductTable;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductTableRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductTableParser;
use App\Services\Storage\PrivateAssetStore;
use DomainException;
use Illuminate\Support\Facades\Gate;

/** A CSV of the workspace with its mapping, read again from the encrypted file (ADR-040). */
final readonly class ReadProductTableAction
{
    public function __construct(
        private ProductTableRepositoryInterface $tables,
        private ProjectRepositoryInterface $projects,
        private ProductTableParser $parser,
        private PrivateAssetStore $store,
    ) {}

    /**
     * @return array{ProductTable, ParsedProductTable}
     */
    public function execute(Workspace $workspace, User $user, string $tablePublicId): array
    {
        $table = $this->tables->findPublicForWorkspace($workspace, $tablePublicId) ?? throw new DomainException('TABLE_NOT_FOUND');
        $project = $this->projects->findForWorkspace($workspace, $table->project_id) ?? throw new DomainException('TABLE_NOT_FOUND');
        Gate::forUser($user)->authorize('view', $project);

        return [$table, $this->parser->parse($this->store->readObject($workspace, $table->storage_key, $table->public_id, PrivateAssetStore::KIND_TABLE))];
    }
}
