<?php

return [
    // Heure d'envoi quotidienne du rappel de saisie des heures.
    'reminder_time' => env('HOURS_REMINDER_TIME', '18:30'),
    // Première date affichable dans le module Heures.
    'min_visible_date' => env('HOURS_MIN_VISIBLE_DATE', '2026-04-27'),
];
