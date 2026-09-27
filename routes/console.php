<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled agent runs (e.g. daily news reports). Needs `php artisan schedule:run` every minute (cron).
Schedule::command('agents:run-due')->everyMinute()->withoutOverlapping();
