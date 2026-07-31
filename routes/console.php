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