<?php

declare(strict_types=1);

namespace App\Jobs\Export;

use App\Actions\Catalog\DeliverCatalogueArtifactAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a ready catalogue file (ADR-052). Carries only the artifact and seller ids. A
 * lost delivery is not charged again: the seller presses the button and the
 * same stored file is sent for free.
 */
final class DeliverCatalogueArtifact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $artifactId, public int $userId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(DeliverCatalogueArtifactAction $deliver): void
    {
        $deliver->execute($this->artifactId, $this->userId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('catalogue.delivery_failed', ['exception_type' => $exception ? $exception::class : null]);
        app(DeliverCatalogueArtifactAction::class)->notifyUndelivered($this->artifactId, $this->userId);
    }
}
