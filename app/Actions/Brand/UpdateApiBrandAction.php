<?php

declare(strict_types=1);

namespace App\Actions\Brand;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Models\Brand;
use App\Models\Workspace;

final readonly class UpdateApiBrandAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private UpdateBrandProfileAction $brands,
    ) {}

    /** @return array{Brand, bool} */
    public function execute(Workspace $workspace, int $userId, string $key, int $expectedVersion, array $attributes): array
    {
        /** @var array{Brand, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $userId, 'brand.update', $key,
            ['expected_version' => $expectedVersion, 'attributes' => $attributes],
            fn (): Brand => $this->brands->execute($workspace, $attributes, $userId, $expectedVersion),
        );

        return $result;
    }
}
