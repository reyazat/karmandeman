<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Project;

use App\Data\Project\ProjectActivity;
use App\Enums\CreditLedgerType;
use App\Enums\ProductStatus;
use App\Enums\ProjectState;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
            ->whereIn('state', array_map(fn (ProjectState $state): string => $state->value, ProjectState::OPEN_FOR_INPUT))
            ->orderByDesc('id')->first();
    }

    public function openForBot(Workspace $workspace, int $limit): array
    {
        return Project::query()->where('workspace_id', $workspace->id)
            ->whereIn('state', array_map(fn (ProjectState $state): string => $state->value, ProjectState::OPEN_FOR_INPUT))
            ->orderByDesc('id')->limit($limit)->get()->all();
    }

    public function selectForBot(Project $project): void
    {
        // Strictly after the latest selection, even if the wall clock stepped back,
        // so the last selection always wins. Callers hold the workspace lock (ADR-031).
        $latest = Project::query()->where('workspace_id', $project->workspace_id)->max('bot_selected_at');
        $at = CarbonImmutable::now();
        if ($latest !== null && ! $at->greaterThan($floor = CarbonImmutable::parse((string) $latest))) {
            $at = $floor->addMicrosecond();
        }
        Project::query()->where('workspace_id', $project->workspace_id)->whereKey($project->id)
            ->update(['bot_selected_at' => $at->format('Y-m-d H:i:s.u')]);
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

    public function activityFor(Workspace $workspace, array $projects): array
    {
        $ids = array_map(fn (Project $project): int => $project->id, $projects);
        if ($ids === []) {
            return [];
        }
        $count = fn (string $table, ?callable $scope = null): array => DB::table($table)
            ->where('workspace_id', $workspace->id)->whereIn('project_id', $ids)
            ->when($scope !== null, $scope)
            ->groupBy('project_id')->selectRaw('project_id, count(*) AS n')
            ->pluck('n', 'project_id')->all();
        $photos = $count('assets');
        $tables = $count('product_tables');
        $voices = $count('voice_assets');
        $products = $count('products', fn ($query) => $query->where('status', '!=', ProductStatus::Archived->value));
        // A capture is the charge itself (ADR-039, ADR-042): only the workspace's own wallet and runs.
        $used = DB::table('credit_ledger')
            ->join('wallets', 'wallets.id', '=', 'credit_ledger.wallet_id')
            ->join('ai_workflow_runs', 'ai_workflow_runs.id', '=', 'credit_ledger.related_id')
            ->where('wallets.workspace_id', $workspace->id)
            ->where('ai_workflow_runs.workspace_id', $workspace->id)
            ->where('credit_ledger.related_type', CreditLedgerEntry::RELATED_RUN)
            ->where('credit_ledger.type', CreditLedgerType::Capture->value)
            ->whereIn('ai_workflow_runs.project_id', $ids)
            ->groupBy('ai_workflow_runs.project_id')
            ->selectRaw('ai_workflow_runs.project_id, -sum(credit_ledger.amount) AS n')
            ->pluck('n', 'project_id')->all();
        // Catalogue files of the project (ADR-052), on the same terms.
        $files = DB::table('credit_ledger')
            ->join('wallets', 'wallets.id', '=', 'credit_ledger.wallet_id')
            ->join('artifacts', 'artifacts.id', '=', 'credit_ledger.related_id')
            ->where('wallets.workspace_id', $workspace->id)
            ->where('artifacts.workspace_id', $workspace->id)
            ->where('credit_ledger.related_type', CreditLedgerEntry::RELATED_ARTIFACT)
            ->where('credit_ledger.type', CreditLedgerType::Capture->value)
            ->whereIn('artifacts.project_id', $ids)
            ->groupBy('artifacts.project_id')
            ->selectRaw('artifacts.project_id, -sum(credit_ledger.amount) AS n')
            ->pluck('n', 'project_id')->all();
        foreach ($files as $id => $credits) {
            $used[$id] = (int) ($used[$id] ?? 0) + (int) $credits;
        }

        $activity = [];
        foreach ($ids as $id) {
            $activity[$id] = new ProjectActivity(
                (int) ($photos[$id] ?? 0), (int) ($tables[$id] ?? 0), (int) ($products[$id] ?? 0), (int) ($used[$id] ?? 0),
                (int) ($voices[$id] ?? 0),
            );
        }

        return $activity;
    }
}
