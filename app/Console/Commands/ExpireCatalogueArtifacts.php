<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalog\ExpireCatalogueArtifactsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExpireCatalogueArtifacts extends Command
{
    protected $signature = 'catalogue:expire';

    protected $description = 'Fail catalogue files queued too long and release their holds (ADR-052)';

    public function handle(ExpireCatalogueArtifactsAction $expire): int
    {
        $this->line(json_encode(['expired' => $expire->execute(CarbonImmutable::now())], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
