<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;

final readonly class CreateProjectAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private EmployeeRepositoryInterface $employees,
    ) {}

    /**
     * Open a new batch/project in COLLECTING_INPUT state (FR-020).
     */
    public function execute(Workspace $workspace, ?string $name = null): Project
    {
        $employee = $this->employees->defaultFor($workspace);

        if ($employee === null) {
            throw new DomainException('NO_ACTIVE_EMPLOYEE');
        }

        return $this->projects->create($workspace, $employee, [
            'name' => $name ?: 'پروژه '.now()->format('Y-m-d'),
        ]);

    }
}
