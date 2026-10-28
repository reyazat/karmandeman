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
