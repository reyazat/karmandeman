<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Data\Project\ProjectActivity;
use App\Enums\ProjectState;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProjectRepositoryInterface
{
    public function create(Workspace $workspace, Employee $employee, array $attributes = []): Project;

    public function findForWorkspace(Workspace $workspace, int $projectId): ?Project;

    /**
     * The bot's current batch (ADR-028): the project selected or created last,
     * in whatever state; without any selection, the newest project open for input.
     */
    public function currentForBot(Workspace $workspace): ?Project;

    /**
     * Projects open for input (ProjectState::OPEN_FOR_INPUT, ADR-041), newest first.
     *
     * @return list<Project>
     */
    public function openForBot(Workspace $workspace, int $limit): array;

    /** Make the project the bot's current batch: later than every earlier selection. Under the workspace lock. */
    public function selectForBot(Project $project): void;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Project;

    /**
     * Files, live products and charged credit of each project (FR-080), keyed
     * by project id, counted inside the workspace only.
     *
     * @param  list<Project>  $projects
     * @return array<int, ProjectActivity>
     */
    public function activityFor(Workspace $workspace, array $projects): array;

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
