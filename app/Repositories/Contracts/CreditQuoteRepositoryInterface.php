<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\CreditQuote;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

interface CreditQuoteRepositoryInterface
{
    /**
     * @param  array{version: string, cap_percent: int, minimum: array<string, int>, rates: array<string, int>}  $tariff
     * @param  list<string>  $inputAssetIds
     * @param  list<array{type: string, quantity: int, unit_credits: int, credits: int}>  $lines
     * @param  int  $capCredits  the most the final charge may reach; at least the lines' total
     */
    public function create(Project $project, User $author, string $kind, array $tariff, array $inputAssetIds, array $lines, int $capCredits, CarbonImmutable $expiresAt): CreditQuote;

    public function findForWorkspace(int $workspaceId, int $id): ?CreditQuote;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?CreditQuote;

    public function markAccepted(CreditQuote $quote, CarbonImmutable $at): void;
}
