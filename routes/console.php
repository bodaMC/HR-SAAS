<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 3.4: release placements of assignments whose end date has passed (idempotent; writes also do this per employee under the tenant lock).
Schedule::command('org:expire-assignments')->dailyAt('00:10')->withoutOverlapping();
