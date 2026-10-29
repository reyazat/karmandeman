<?php

declare(strict_types=1);

namespace App\Actions\Idempotency;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\Workspace;
use App\Repositories\Contracts\ApiIdempotencyRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class RunIdempotentMutationAction
{
    public function __construct(
        private ApiIdempotencyRepositoryInterface $records,
        private LockActiveWorkspaceAction $lockActive,
        private WorkspaceFieldCipher $cipher,
    ) {}

    /**
     * The workspace row serializes concurrent retries; the unique index is a second guard.
     * The raw model snapshot preserves the original response even after later edits.
     * The request fingerprint is an HMAC under the workspace key: a plain hash of
     * low-entropy input (project or brand names) would outlive key destruction.
     *
     * @param  array<string, mixed>  $input
     * @param  Closure(): Model  $mutation
     * @return array{Model, bool}
     */
    public function execute(Workspace $workspace, int $userId, string $operation, string $key, array $input, Closure $mutation): array
    {
        $canonical = json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($workspace, $userId, $operation, $key, $canonical, $mutation): array {
            $this->lockActive->execute($workspace);
            $hash = $this->cipher->mac($workspace, "api_idempotency_records.request_hash:{$operation}", $canonical);
            $record = $this->records->find($workspace, $userId, $operation, $key);
            if ($record !== null) {
                if (! hash_equals($record->request_hash, $hash)) {
                    throw new DomainException('IDEMPOTENCY_KEY_CONFLICT');
                }
                // Snapshot expired by retention (ADR-015): the key stays spent, never re-run.
                if ($record->result_attributes === null) {
                    throw new DomainException('IDEMPOTENCY_KEY_EXPIRED');
                }

                $class = match ($operation) {
                    'project.create', 'project.finish_input', 'project.reopen', 'project.cancel' => Project::class,
                    'project.approve_products' => ProjectSnapshot::class,
                    'project.attach_asset' => Asset::class,
                    'brand.update' => Brand::class,
                    'product.create', 'product.revise', 'product.approve', 'product.archive' => ProductRevision::class,
                    default => throw new DomainException('UNKNOWN_MUTATION'),
                };

                return [(new $class)->newFromBuilder($record->result_attributes), true];
            }

            $result = $mutation();
            $this->records->record($workspace, $userId, $operation, $key, $hash, $result->getAttributes());

            return [$result, false];
        });
    }

    private function canonicalize(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->canonicalize($value);
            }
        }

        return $input;
    }
}
