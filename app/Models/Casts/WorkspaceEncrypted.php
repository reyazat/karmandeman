<?php

declare(strict_types=1);

namespace App\Models\Casts;

use App\Models\Workspace;
use App\Services\Crypto\WorkspaceFieldCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Stores a private column encrypted under the owning workspace's data key
 * (NFR-11, ADR-013). The AAD context is "table.column", so a value copied to
 * another column or tenant does not decrypt. No plaintext or APP_KEY
 * fallback: a legacy value fails closed. Use `:array` for JSON values.
 *
 * Eloquent builds casts without constructor injection, so the request-scoped
 * cipher is resolved from the container here (KM-ENG-001 §1 exception).
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class WorkspaceEncrypted implements CastsAttributes, ComparesCastableAttributes
{
    private readonly bool $json;

    public function __construct(string $format = 'string')
    {
        $this->json = $format === 'array';
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $plaintext = $this->cipher()->decrypt($this->workspace($model), $this->context($model, $key), (string) $value);

        return $this->json ? json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR) : $plaintext;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $plaintext = $this->json ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (string) $value;

        return $this->cipher()->encrypt($this->workspace($model), $this->context($model, $key), $plaintext);
    }

    /** Random nonces make equal values differ, so compare plaintext to keep dirty checks honest. */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        return $this->get($model, $key, $firstValue, []) === $this->get($model, $key, $secondValue, []);
    }

    private function workspace(Model $model): Workspace
    {
        if ($model->getAttribute('workspace_id') === null) {
            throw new LogicException('Set workspace_id before assigning an encrypted attribute.');
        }
        $workspace = $model->getRelationValue('workspace');
        if (! $workspace instanceof Workspace || $workspace->id !== $model->getAttribute('workspace_id')) {
            $workspace = Workspace::query()->findOrFail($model->getAttribute('workspace_id'));
            $model->setRelation('workspace', $workspace);
        }

        return $workspace;
    }

    private function context(Model $model, string $key): string
    {
        return $model->getTable().'.'.$key;
    }

    private function cipher(): WorkspaceFieldCipher
    {
        return app(WorkspaceFieldCipher::class);
    }
}
