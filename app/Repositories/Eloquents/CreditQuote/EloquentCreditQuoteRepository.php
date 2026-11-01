<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\CreditQuote;

use App\Models\CreditQuote;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\CreditQuoteRepositoryInterface;
use Carbon\CarbonImmutable;

final class EloquentCreditQuoteRepository implements CreditQuoteRepositoryInterface
{
    public function create(Project $project, User $author, string $kind, string $tariffVersion, array $inputAssetIds, array $lines, CarbonImmutable $expiresAt): CreditQuote
    {
        return CreditQuote::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $author->id,
            'kind' => $kind,
            'tariff_version' => $tariffVersion,
            'project_version' => $project->version,
            'input_asset_ids' => $inputAssetIds,
            'lines' => $lines,
            'credits_total' => array_sum(array_column($lines, 'credits')),
            'increase_cap_credits' => 0,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?CreditQuote
    {
        return CreditQuote::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->first();
    }

    public function markAccepted(CreditQuote $quote, CarbonImmutable $at): void
    {
        $quote->forceFill(['accepted_at' => $at])->save();
    }
}
