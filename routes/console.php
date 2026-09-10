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

// Compactage quotidien de l'historique des cotations. `cotations:refresh`
// tourne toutes les minutes et ajoute ~36 000 lignes par jour : sans
// compactage la table grossit d'environ 1,1 million de lignes par mois.
//
// L'heure d'exécution (03h30 par défaut) n'a rien à voir avec l'heure cible du
// relevé conservé (15h00) : c'est simplement une heure creuse. Aucune
// concurrence possible avec l'import, qui n'écrit que sur la journée en cours
// alors que le compactage ne touche que des journées vieilles d'au moins huit
// jours. withoutOverlapping empêche deux exécutions simultanées et onOneServer
// garantit un seul passage si l'application tourne sur plusieurs instances.
Schedule::command('cotations:compact-history')
    ->dailyAt(config('cotations.compaction.schedule_time', '03:30'))
    ->timezone(config('app.timezone', 'Europe/Paris'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('annonces:send-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->timezone(config('app.timezone', 'Europe/Paris'));
