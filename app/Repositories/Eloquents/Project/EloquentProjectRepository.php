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

        // workspace_id first: the encrypted name needs its workspace key.
        return Project::create([
            'workspace_id' => $workspace->id,
            'name' => $attributes['name'] ?? null,
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

    public function currentForBot(Workspace $workspace): ?Project
    {
        // The last selection stays current even once closed: input is refused
        // rather than sent to an older batch the seller did not pick.
        $selected = Project::query()->where('workspace_id', $workspace->id)->whereNotNull('bot_selected_at')
            ->orderByDesc('bot_selected_at')->orderByDesc('id')->first();

        return $selected ?? Project::query()->where('workspace_id', $workspace->id)
            ->whereIn('state', [ProjectState::CollectingInput->value, ProjectState::ReviewRequired->value])
            ->orderByDesc('id')->first();
    }

    public function openForBot(Workspace $workspace, int $limit): array
    {
        return Project::query()->where('workspace_id', $workspace->id)
            ->whereIn('state', [ProjectState::CollectingInput->value, ProjectState::ReviewRequired->value])
            ->orderByDesc('id')->limit($limit)->get()->all();
    }

    public function selectForBot(Project $project): void
    {
        Project::query()->where('workspace_id', $project->workspace_id)->whereKey($project->id)
            ->update(['bot_selected_at' => now()->format('Y-m-d H:i:s.u')]);
    }

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Project
    {
        return Project::query()->where('workspace_id', $workspace->id)
            ->where('public_id', $publicId)->first();
    }

    public function advance(Project $project, ProjectState $state): Project
    {
        $project->forceFill(['state' => $state, 'version' => $project->version + 1])->save();

        return $project;
    }

    public function bumpVersion(Workspace $workspace, ?int $projectId): void
    {
        if ($projectId !== null) {
            Project::query()->where('workspace_id', $workspace->id)->whereKey($projectId)->increment('version');
        }
    }

    public function paginateFor(Workspace $workspace, int $perPage = 20): LengthAwarePaginator
    {
        $page = Project::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')
            ->paginate($perPage);
        // Encrypted names decrypt with this workspace's key without one lookup per row.
        $page->getCollection()->each->setRelation('workspace', $workspace);

        return $page;
    }
}
