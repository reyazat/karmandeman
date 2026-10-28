<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Privacy\ProcessAccountDeletionsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ProcessAccountDeletions extends Command
{
    protected $signature = 'privacy:process-deletions';

    protected $description = 'Purge contained accounts, complete expired erasures and alert on deadlines (FR-087, NFR-12)';

    public function handle(ProcessAccountDeletionsAction $process): int
    {
        $summary = $process->execute(CarbonImmutable::now());
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
