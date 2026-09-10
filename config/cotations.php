<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Compactage de l'historique des cotations
    |--------------------------------------------------------------------------
    |
    | `cotations:refresh` tourne toutes les minutes : la table
    | `cotation_market_prices` gagne environ 36 000 lignes par jour. Le
    | compactage conserve la granularité complète sur une fenêtre glissante,
    | puis ne garde qu'un relevé quotidien par cotation pour l'historique plus
    | ancien.
    |
    */
    'compaction' => [
        // Nombre de jours de granularité complète conservés. Seules les
        // journées civiles entièrement antérieures à cette fenêtre sont
        // compactées : une journée n'est donc jamais compactée à moitié.
        'retention_days' => (int) env('COTATIONS_COMPACT_RETENTION_DAYS', 7),

        // Heure cible du relevé quotidien conservé, exprimée dans le fuseau
        // ci-dessous. Ce n'est pas l'heure d'exécution de la maintenance.
        'target_time' => env('COTATIONS_COMPACT_TARGET_TIME', '15:00:00'),

        // Fuseau métier servant à découper les journées et à situer l'heure
        // cible. Les changements d'heure sont gérés par Carbon.
        'timezone' => env('COTATIONS_COMPACT_TIMEZONE', 'Europe/Paris'),

        // Lignes lues par lot pendant la sélection des relevés à conserver.
        'chunk_size' => (int) env('COTATIONS_COMPACT_CHUNK', 5000),

        // Lignes supprimées par lot. Chaque lot est une transaction implicite
        // courte : les verrous restent brefs même sur 2,7 millions de lignes.
        'batch_size' => (int) env('COTATIONS_COMPACT_BATCH', 2000),

        // Pause en millisecondes entre deux lots de suppression, pour laisser
        // respirer la base pendant le rattrapage initial.
        'sleep_ms' => (int) env('COTATIONS_COMPACT_SLEEP_MS', 0),

        // Heure d'exécution quotidienne planifiée (cf. routes/console.php).
        'schedule_time' => env('COTATIONS_COMPACT_SCHEDULE_TIME', '03:30'),
    ],
];
