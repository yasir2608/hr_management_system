<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reminders for approvals and tasks still waiting (never after the action is done).
Schedule::command('notifications:remind')->dailyAt('08:00')->withoutOverlapping();
