<?php

declare(strict_types=1);

namespace App\Actions\Idempotency;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Models\Brand;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ApiIdempotencyRepositoryInterface;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class RunIdempotentMutationAction
{
    public function __construct(
        private ApiIdempotencyRepositoryInterface $records,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    /**
     * The workspace row serializes concurrent retries; the unique index is a second guard.
     * The raw model snapshot preserves the original response even after later edits.
     *
     * @param  array<string, mixed>  $input
     * @param  Closure(): Model  $mutation
     * @return array{Model, bool}
     */
    public function execute(Workspace $workspace, int $userId, string $operation, string $key, array $input, Closure $mutation): array
    {
        $hash = hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($workspace, $userId, $operation, $key, $hash, $mutation): array {
            $this->lockActive->execute($workspace);
            $record = $this->records->find($workspace, $userId, $operation, $key);
            if ($record !== null) {
                if (! hash_equals($record->request_hash, $hash)) {
                    throw new DomainException('IDEMPOTENCY_KEY_CONFLICT');
                }

                $class = match ($operation) {
                    'project.create' => Project::class,
                    'brand.update' => Brand::class,
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
