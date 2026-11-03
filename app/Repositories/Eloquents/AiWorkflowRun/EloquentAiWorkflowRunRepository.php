<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\AiWorkflowRun;

use App\Enums\AiRunStatus;
use App\Models\AiWorkflowRun;
use App\Models\CreditLedgerEntry;
use App\Models\CreditQuote;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class EloquentAiWorkflowRunRepository implements AiWorkflowRunRepositoryInterface
{
    private const OPEN = [AiRunStatus::Queued->value, AiRunStatus::Dispatched->value];

    public function create(Project $project, User $requester, string $workflow, string $workflowVersion, array $inputAssetIds, CarbonImmutable $deadline): AiWorkflowRun
    {
        return AiWorkflowRun::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'requested_by' => $requester->id,
            'workflow' => $workflow,
            'workflow_version' => $workflowVersion,
            'status' => AiRunStatus::Queued,
            'input_asset_ids' => $inputAssetIds,
            'deadline_at' => $deadline,
        ]);
    }

    public function attachCredit(AiWorkflowRun $run, CreditQuote $quote, CreditLedgerEntry $hold): void
    {
        $run->forceFill(['quote_id' => $quote->id, 'reservation_entry_id' => $hold->id])->save();
    }

    public function find(int $id): ?AiWorkflowRun
    {
        return AiWorkflowRun::query()->find($id);
    }

    public function lock(AiWorkflowRun $run): AiWorkflowRun
    {
        return AiWorkflowRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
    }

    public function findByPublicId(string $publicId): ?AiWorkflowRun
    {
        return AiWorkflowRun::query()->where('public_id', $publicId)->first();
    }

    public function forWorkspaceByIds(int $workspaceId, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return AiWorkflowRun::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)
            // The workspace too: decrypting each project name would otherwise load it once per run.
            ->with(['project' => fn ($query) => $query->where('workspace_id', $workspaceId), 'project.workspace'])
            ->get()
            ->keyBy('id')
            ->toBase();
    }

    public function openForProject(Project $project): ?AiWorkflowRun
    {
        return AiWorkflowRun::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->whereIn('status', self::OPEN)
            ->latest('id')
            ->first();
    }

    public function extractedAssetIds(Project $project): array
    {
        return AiWorkflowRun::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->where('status', AiRunStatus::Succeeded->value)
            ->get(['input_asset_ids'])
            ->flatMap(fn (AiWorkflowRun $run): array => $run->input_asset_ids)
            ->unique()->values()->all();
    }

    public function extractedVoiceIds(Project $project): array
    {
        return AiWorkflowRun::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->where('status', AiRunStatus::Succeeded->value)
            ->get(['input_voice_ids'])
            ->flatMap(fn (AiWorkflowRun $run): array => $run->input_voice_ids ?? [])
            ->unique()->values()->all();
    }

    public function markDispatched(AiWorkflowRun $run, CarbonImmutable $at): void
    {
        AiWorkflowRun::query()->whereKey($run->id)->where('status', AiRunStatus::Queued->value)
            ->update(['status' => AiRunStatus::Dispatched->value, 'dispatched_at' => $at]);
    }

    public function countAttempt(AiWorkflowRun $run): void
    {
        AiWorkflowRun::query()->whereKey($run->id)->increment('dispatch_attempts');
    }

    public function claim(AiWorkflowRun $run, string $execution, CarbonImmutable $at): void
    {
        $run->forceFill(['claimed_at' => $at, 'claimed_by_execution' => $execution]);
        if ($run->status === AiRunStatus::Queued) {
            $run->forceFill(['status' => AiRunStatus::Dispatched, 'dispatched_at' => $at]);
        }
        $run->save();
    }

    public function close(AiWorkflowRun $run, AiRunStatus $status, CarbonImmutable $at, ?string $errorCode = null, ?string $resultMac = null, ?int $candidates = null, ?array $usage = null): void
    {
        $run->forceFill([
            'status' => $status, 'completed_at' => $at, 'error_code' => $errorCode,
            'result_mac' => $resultMac, 'candidate_count' => $candidates, 'usage' => $usage,
        ])->save();
    }

    public function recordLateUsage(AiWorkflowRun $run, array $usage): void
    {
        $run->forceFill(['usage' => $usage])->save();
    }

    public function overdueIds(CarbonImmutable $now, int $limit): array
    {
        return AiWorkflowRun::query()
            ->whereIn('status', self::OPEN)
            ->where('deadline_at', '<', $now)
            ->whereIn('workspace_id', Workspace::query()->select('id')->where('status', 'active'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }
}
