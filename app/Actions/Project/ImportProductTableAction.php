<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Exceptions\ProductTableRejected;
use App\Models\ProductTable;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProductTableRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductRevisionBuilder;
use App\Services\ProductIntake\ProductTableParser;
use App\Services\Storage\PrivateAssetStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The seller confirmed a CSV's column mapping (ADR-040): every readable row
 * becomes a product draft with its values confirmed and cited to the file
 * and line (FR-030). The price unit is the header's or the seller's, never
 * the brand default (QA-02). All rows are stored or none; Gate A is still
 * one press per product. No model and no credit are involved.
 */
final readonly class ImportProductTableAction
{
    private const OPEN_PROJECT_STATES = [
        ProjectState::CollectingInput, ProjectState::NeedsClarification, ProjectState::ReviewRequired,
    ];

    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private ProductTableRepositoryInterface $tables,
        private ProductTableParser $parser,
        private ProductRevisionBuilder $builder,
        private PrivateAssetStore $store,
    ) {}

    /**
     * @param  'IRT'|'IRR'|null  $unit  the seller's price unit when the file names none
     * @return array{ProductTable, bool}
     *
     * @throws ProductTableRejected TABLE_SKU_TAKEN
     */
    public function execute(Workspace $workspace, User $user, string $key, string $tablePublicId, ?string $unit): array
    {
        /** @var array{ProductTable, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'project.import_table', $key, ['table' => $tablePublicId, 'unit' => $unit],
            function () use ($workspace, $user, $tablePublicId, $unit): ProductTable {
                $this->consent->execute($workspace);
                $table = $this->tables->findPublicForWorkspace($workspace, $tablePublicId)
                    ?? throw new DomainException('TABLE_NOT_FOUND');
                $project = $this->projects->findForWorkspace($workspace, $table->project_id)
                    ?? throw new DomainException('TABLE_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if ($table->status !== ProductTable::STATUS_PENDING) {
                    throw new DomainException('TABLE_ALREADY_IMPORTED');
                }
                if (! in_array($project->state, self::OPEN_PROJECT_STATES, true)) {
                    throw new DomainException('PROJECT_NOT_ACCEPTING_PRODUCTS');
                }

                $parsed = $this->parser->parse($this->store->readObject($workspace, $table->storage_key, $table->public_id, PrivateAssetStore::KIND_TABLE));
                if ($parsed->headerUnit !== null && $unit !== null && $unit !== $parsed->headerUnit) {
                    throw new DomainException('TABLE_PRICE_UNIT_CONFLICT');
                }
                $unit = $parsed->headerUnit ?? $unit;
                if ($unit === null && $parsed->hasPrices()) {
                    throw new DomainException('TABLE_PRICE_UNIT_REQUIRED');
                }
                $rows = $parsed->importable();
                if ($rows === []) {
                    throw new DomainException('TABLE_HAS_NO_PRODUCTS');
                }

                $taken = [];
                foreach ($rows as $row) {
                    $draft = $row['price'] === null ? $row['draft'] : $row['draft'] + [
                        'pricing' => ['regular_price' => $row['price'], 'input_unit' => $unit, 'status' => 'confirmed'],
                    ];
                    $revision = $this->builder->build($workspace, $project, $draft, tableRow: ['table_id' => $table->public_id, 'line' => $row['line']]);
                    try {
                        // A savepoint: a taken SKU is reported with every other one, then nothing is kept.
                        DB::transaction(fn () => $this->products->createWithRevision($workspace, $project, $user, $revision));
                    } catch (DomainException $e) {
                        if ($e->getMessage() !== 'PRODUCT_SKU_TAKEN') {
                            throw $e;
                        }
                        $taken[] = $row['line'];
                    }
                }
                if ($taken !== []) {
                    throw new ProductTableRejected('TABLE_SKU_TAKEN', $taken);
                }
                $this->tables->markImported($table, count($rows), CarbonImmutable::now());
                $this->projects->bumpVersion($workspace, $project->id);

                return $table;
            },
        );

        return $result;
    }
}
