<?php

declare(strict_types=1);

namespace App\Jobs\Export;

use App\Actions\Catalog\FailCatalogueArtifactAction;
use App\Actions\Catalog\GenerateCatalogueArtifactAction;
use App\Repositories\Contracts\ArtifactRepositoryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes one catalogue file on the export worker (ADR-052). Carries only the
 * artifact id. After the last try the hold is released: an error in making
 * the file costs the seller nothing.
 */
final class GenerateCatalogueArtifact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 110;

    public function __construct(public int $artifactId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [20, 90];
    }

    public function handle(GenerateCatalogueArtifactAction $generate): void
    {
        $generate->execute($this->artifactId);
    }

    public function failed(?Throwable $exception): void
    {
        // Class name only: messages may carry paths or identifiers.
        Log::error('catalogue.generation_job_failed', ['exception_type' => $exception ? $exception::class : null]);
        $artifact = app(ArtifactRepositoryInterface::class)->find($this->artifactId);
        if ($artifact !== null) {
            app(FailCatalogueArtifactAction::class)->execute($artifact, 'GENERATION_FAILED');
        }
    }
}
