<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Project;

use App\Enums\ProjectState;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentProjectRepository implements ProjectRepositoryInterface
{
    public function create(Workspace $workspace, Employee $employee, array $attributes = []): Project
    {
        if ($employee->workspace_id !== $workspace->id || $employee->status !== 'active') {
            throw new \DomainException('INVALID_PROJECT_EMPLOYEE');
        }

        return Project::create([
            'name' => $attributes['name'] ?? null,
            'workspace_id' => $workspace->id,
            'employee_id' => $employee->id,
            'state' => ProjectState::CollectingInput,
        ]);
    }

    public function findForWorkspace(Workspace $workspace, int $projectId): ?Project
    {
        return Project::query()
            ->where('workspace_id', $workspace->id)
            ->find($projectId);
    }

    public function paginateFor(Workspace $workspace, int $perPage = 20): LengthAwarePaginator
    {
        return Project::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
