<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Models\Project;
use App\Models\Workspace;

final readonly class CreateApiProjectAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private CreateProjectAction $projects,
        private EnsurePolicyConsentAction $consent,
    ) {}

    /** @return array{Project, bool} */
    public function execute(Workspace $workspace, int $userId, string $key, ?string $name): array
    {
        /** @var array{Project, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $userId, 'project.create', $key, ['name' => $name],
            function () use ($workspace, $name): Project {
                $this->consent->execute($workspace);

                return $this->projects->execute($workspace, $name);
            },
        );

        return $result;
    }
}
