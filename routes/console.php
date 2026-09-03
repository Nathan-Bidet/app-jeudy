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

Schedule::command('engrais:archive-old')
    ->dailyAt('05:05')
    ->timezone(config('app.timezone', 'Europe/Paris'));

Schedule::command('hours:send-missing-reminders')
    ->dailyAt(config('hours.reminder_time', '18:30'))
    ->timezone(config('app.timezone', 'Europe/Paris'));

// Rappel hebdomadaire aux valideurs : tous les jeudis à 14h00, heure de
// l'application. `weeklyOn(4, ...)` désigne le jeudi (0 = dimanche).
// withoutOverlapping empêche deux exécutions concurrentes, onOneServer garantit
// un seul envoi si l'application tourne un jour sur plusieurs instances ; la
// commande reste par ailleurs idempotente sur la journée.
Schedule::command('validation:send-pending-reminders')
    ->weeklyOn(4, '14:00')
    ->timezone(config('app.timezone', 'Europe/Paris'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('cotations:refresh')
    ->everyMinute()
    ->withoutOverlapping()
    ->timezone(config('app.timezone', 'Europe/Paris'));

Schedule::command('annonces:send-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->timezone(config('app.timezone', 'Europe/Paris'));
