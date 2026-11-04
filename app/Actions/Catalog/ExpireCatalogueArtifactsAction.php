<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Repositories\Contracts\ArtifactRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * A catalogue file still queued well past every retry of its job, because the
 * worker was down or the job was lost, fails without charge (ADR-052), so
 * no hold stays held and the seller can ask again. A job that runs later
 * finds the file no longer queued and makes nothing.
 */
final readonly class ExpireCatalogueArtifactsAction
{
    /** Longer than three tries of the job with their backoff (110 s each, 20 s and 90 s apart). */
    public const AFTER_MINUTES = 30;

    public function __construct(
        private ArtifactRepositoryInterface $artifacts,
        private FailCatalogueArtifactAction $fail,
    ) {}

    public function execute(CarbonImmutable $now): int
    {
        $expired = 0;
        foreach ($this->artifacts->queuedBefore($now->subMinutes(self::AFTER_MINUTES), 50) as $artifact) {
            $this->fail->execute($artifact, 'GENERATION_TIMED_OUT');
            $expired++;
        }

        return $expired;
    }
}
