<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every locked identity against its fingerprint, nightly (D-038). The deployment's cron runs the scheduler.
Schedule::command('employees:verify-identity')->dailyAt('02:30')->timezone(config('hr.timezone'))->withoutOverlapping();

// What is short-lived is removed (D-039): login challenges expired for a day, tokens expired for two days.
Schedule::command('model:prune')->dailyAt('03:00')->timezone(config('hr.timezone'));
Schedule::command('sanctum:prune-expired --hours=48')->dailyAt('03:10')->timezone(config('hr.timezone'));

// Contracts ending within 30 days, told to the staff who update files (D-046).
Schedule::command('employees:remind-expiring-contracts')->dailyAt('06:00')->timezone(config('hr.timezone'))->withoutOverlapping();
