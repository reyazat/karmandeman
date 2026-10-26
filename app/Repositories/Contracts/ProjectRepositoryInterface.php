<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProjectRepositoryInterface
{
    public function create(Workspace $workspace, Employee $employee, array $attributes = []): Project;

    public function findForWorkspace(Workspace $workspace, int $projectId): ?Project;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Project;

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginateFor(Workspace $workspace, int $perPage = 20): LengthAwarePaginator;
}
