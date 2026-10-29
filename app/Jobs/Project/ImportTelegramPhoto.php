<?php

declare(strict_types=1);

namespace App\Jobs\Project;

use App\Actions\Project\ImportTelegramPhotoAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carries only the intake row id: no chat id, file id or bytes in the queue
 * payload, so erasure of the row is enough (ADR-024).
 */
final class ImportTelegramPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $intakeId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ImportTelegramPhotoAction $import): void
    {
        $import->execute($this->intakeId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('telegram.photo_import_failed', ['exception_type' => $exception ? $exception::class : null]);
        app(ImportTelegramPhotoAction::class)->fail($this->intakeId);
    }
}
