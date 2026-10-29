<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\ProjectState;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProjectRepositoryInterface
{
    public function create(Workspace $workspace, Employee $employee, array $attributes = []): Project;

    public function findForWorkspace(Workspace $workspace, int $projectId): ?Project;

    /** The newest project of the workspace in this state. */
    public function latestInState(Workspace $workspace, ProjectState $state): ?Project;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Project;

    /** Move to the next state and bump the optimistic version (PRD §6). */
    public function advance(Project $project, ProjectState $state): Project;

    /**
     * Bump the version after a change to one of the batch's products, so a
     * batch approval against the earlier view is a conflict (ADR-018).
     */
    public function bumpVersion(Workspace $workspace, ?int $projectId): void;

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginateFor(Workspace $workspace, int $perPage = 20): LengthAwarePaginator;
}
