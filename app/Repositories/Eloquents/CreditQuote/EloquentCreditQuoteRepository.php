<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\CreditQuote;

use App\Models\CreditQuote;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\CreditQuoteRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentCreditQuoteRepository implements CreditQuoteRepositoryInterface
{
    public function create(Project $project, User $author, string $kind, array $tariff, array $inputAssetIds, array $lines, int $capCredits, CarbonImmutable $expiresAt): CreditQuote
    {
        $total = array_sum(array_column($lines, 'credits'));

        return CreditQuote::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $author->id,
            'kind' => $kind,
            'tariff_version' => $tariff['version'],
            'tariff' => $tariff,
            'project_version' => $project->version,
            'input_asset_ids' => $inputAssetIds,
            'lines' => $lines,
            'credits_total' => $total,
            'increase_cap_credits' => max(0, $capCredits - $total),
            'expires_at' => $expiresAt,
        ]);
    }

    public function findForWorkspace(int $workspaceId, int $id): ?CreditQuote
    {
        return CreditQuote::query()->where('workspace_id', $workspaceId)->find($id);
    }

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?CreditQuote
    {
        return CreditQuote::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->first();
    }

    public function markAccepted(CreditQuote $quote, CarbonImmutable $at): void
    {
        $quote->forceFill(['accepted_at' => $at])->save();
    }

    public function deleteUnacceptedCreatedBefore(CarbonImmutable $cutoff): int
    {
        return CreditQuote::query()
            ->whereNull('accepted_at')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(fn ($runs) => $runs->select(DB::raw(1))->from('ai_workflow_runs')->whereColumn('ai_workflow_runs.quote_id', 'credit_quotes.id'))
            ->delete();
    }
}
