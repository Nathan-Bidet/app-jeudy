<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('a-prevoir:archive-old')
    ->dailyAt('05:00')
    ->timezone(config('app.timezone', 'Europe/Paris'));

Schedule::command('hours:send-missing-reminders')
    ->dailyAt(config('hours.reminder_time', '18:30'))
    ->timezone(config('app.timezone', 'Europe/Paris'));
