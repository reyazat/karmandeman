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
     * @param  list<string>  $inputAssetIds
     * @param  list<array{type: string, quantity: int, unit_credits: int, credits: int}>  $lines
     */
    public function create(Project $project, User $author, string $kind, string $tariffVersion, array $inputAssetIds, array $lines, CarbonImmutable $expiresAt): CreditQuote;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?CreditQuote;

    public function markAccepted(CreditQuote $quote, CarbonImmutable $at): void;
}
