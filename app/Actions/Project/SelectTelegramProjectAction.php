<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\ProjectState;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Support\Facades\Gate;

/**
 * The bot's "select" button under /projects (ADR-028): makes an open batch the
 * current one for photos, /product and /done. No product data changes, so the
 * batch version stays.
 */
final readonly class SelectTelegramProjectAction
{
    public function __construct(private ProjectRepositoryInterface $projects) {}

    public function execute(Workspace $workspace, User $user, string $projectPublicId): string
    {
        $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId);
        if ($project === null || Gate::forUser($user)->denies('view', $project)) {
            return 'پروژه یافت نشد.';
        }
        if (! in_array($project->state, [ProjectState::CollectingInput, ProjectState::ReviewRequired], true)) {
            return "پروژه «{$project->name}» باز نیست و انتخاب نمی‌شود.";
        }
        $this->projects->selectForBot($project);

        return $project->state === ProjectState::CollectingInput
            ? "پروژه «{$project->name}» انتخاب شد؛ عکس‌ها و /product به این پروژه می‌روند."
            : "پروژه «{$project->name}» انتخاب شد؛ ورودی آن بسته است و /done آن را نشان می‌دهد.";
    }
}
