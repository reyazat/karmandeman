<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-087 / NFR-12: live purge target is 24h from T0 (ADR-013, ADR-014). The
// overlap lock expires after 10 minutes, so a killed run cannot stall purges for a day.
Schedule::command('privacy:process-deletions')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// FR-084 retention limits of the policy bundle (ADR-015); Sanctum keeps expired
// tokens for one hour, matching the auth replay limit.
Schedule::command('privacy:prune-retention')->hourly()->withoutOverlapping(30)->onOneServer();
Schedule::command('sanctum:prune-expired', ['--hours' => 1])->hourly()->onOneServer();
