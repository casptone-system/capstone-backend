<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the deadline near notification check to run daily at 8:00 AM
Schedule::command('notifications:check-deadline-near')
    ->dailyAt('08:00')
    ->description('Check for tasks with approaching deadlines and send notifications')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notifications:check-area-deadlines')
    ->hourly()
    ->description('Remind Area In-Charge and members about incomplete area deadlines')
    ->withoutOverlapping();

// Local mysqldump backup. Disabled on Railway/PaaS — disk is ephemeral and
// mysqldump is often missing. Use Railway MySQL backups instead.
Schedule::command('database:backup --compress=gzip')
    ->dailyAt('02:00')
    ->description('Daily compressed database backup')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn () => (bool) env('ENABLE_LOCAL_DB_BACKUP', false));