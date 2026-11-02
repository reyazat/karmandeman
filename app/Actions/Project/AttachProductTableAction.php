<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
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
use Illuminate\Support\Str;
use Throwable;

/**
 * A seller's CSV of products into an open batch, from the API or the bot
 * (ADR-040). The file is read first, so a table that cannot be mapped is
 * refused before anything is stored; then it is encrypted under the
 * workspace key like a photo (ADR-022). No product exists until the seller
 * confirms the mapping. The same bytes twice in one batch return the first.
 */
final readonly class AttachProductTableAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductTableRepositoryInterface $tables,
        private ProductTableParser $parser,
        private PrivateAssetStore $store,
    ) {}

    /**
     * @return array{ProductTable, ParsedProductTable, bool}
     */
    public function execute(
        Workspace $workspace, User $user, string $key, string $projectPublicId, #[\SensitiveParameter] string $bytes,
        string $source = ProductTable::SOURCE_API, ?string $sourceMessageKey = null,
    ): array {
        if (strlen($bytes) > (int) config('karmandeman.tables.max_kilobytes') * 1024) {
            throw new DomainException('TABLE_TOO_LARGE');
        }
        $parsed = $this->parser->parse($bytes);

        /** @var array{ProductTable, bool} $result */
        $result = $this->idempotency->execute(
            // The fingerprint is MACed by the wrapper; the plain digest is never stored.
            $workspace, $user->id, 'project.attach_table', $key, ['project' => $projectPublicId, 'sha256' => hash('sha256', $bytes)],
            function () use ($workspace, $user, $projectPublicId, $bytes, $parsed, $source, $sourceMessageKey): ProductTable {
                $this->consent->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if (! $project->state->isOpenForInput()) {
                    throw new DomainException('PROJECT_NOT_ACCEPTING_INPUT');
                }

                $contentMac = $this->store->contentMac($workspace, $bytes, PrivateAssetStore::KIND_TABLE);
                $existing = $this->tables->findByContent($project, $contentMac);
                if ($existing !== null) {
                    return $existing;
                }

                $publicId = Str::uuid()->toString();
                $storageKey = $this->store->put($workspace, $publicId, $bytes, PrivateAssetStore::KIND_TABLE);
                try {
                    return $this->tables->create($project, $user, $publicId, [
                        'storage_key' => $storageKey,
                        'content_mac' => $contentMac,
                        'size_bytes' => strlen($bytes),
                        'row_count' => count($parsed->rows),
                        'source' => $source,
                        'source_message_key' => $sourceMessageKey,
                    ]);
                } catch (Throwable $e) {
                    // A failed cleanup is reported, never allowed to hide the original error.
                    rescue(fn () => $this->store->delete($storageKey));

                    throw $e;
                }
            },
        );

        // A replay answers the table as it is now: one dropped since is gone, not pending.
        $table = $result[1] ? $this->tables->findPublicForWorkspace($workspace, $result[0]->public_id) : $result[0];
        if ($table === null) {
            throw new DomainException('TABLE_NOT_FOUND');
        }

        return [$table, $parsed, $result[1]];
    }
}
