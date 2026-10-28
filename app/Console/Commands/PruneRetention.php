<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Privacy\PruneRetentionAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PruneRetention extends Command
{
    protected $signature = 'privacy:prune-retention';

    protected $description = 'Delete or expire short-lived technical records past their retention limit (FR-084)';

    public function handle(PruneRetentionAction $prune): int
    {
        $this->line(json_encode($prune->execute(CarbonImmutable::now()), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
