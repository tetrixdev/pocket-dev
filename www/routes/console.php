<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Memory Schema Snapshots
|--------------------------------------------------------------------------
|
| Hourly snapshots of the memory schema for disaster recovery.
| Tiered retention: hourly (24h) → 4/day (7d) → 1/day (30d)
|
*/

Schedule::command('memory:snapshot create')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('memory:snapshot prune')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();
