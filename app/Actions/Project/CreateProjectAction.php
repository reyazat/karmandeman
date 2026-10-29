<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CreateProjectAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private EmployeeRepositoryInterface $employees,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    /**
     * Open a new batch/project in COLLECTING_INPUT state (FR-020).
     */
    public function execute(Workspace $workspace, ?string $name = null): Project
    {
        return DB::transaction(function () use ($workspace, $name): Project {
            $this->lockActive->execute($workspace);
            $employee = $this->employees->defaultFor($workspace);

            if ($employee === null) {
                throw new DomainException('NO_ACTIVE_EMPLOYEE');
            }

            $project = $this->projects->create($workspace, $employee, [
                'name' => $name ?: 'پروژه '.now()->format('Y-m-d'),
            ]);
            // A new batch becomes the bot's current one, whichever channel created it (ADR-028).
            $this->projects->selectForBot($project);

            return $project;
        });
    }
}
