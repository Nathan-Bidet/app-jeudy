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

    /*
    |--------------------------------------------------------------------------
    | Pièces jointes du message d'information (modale « Envoyer »)
    |--------------------------------------------------------------------------
    |
    | Fichiers temporaires stockés sur le disque privé (storage/app/private,
    | jamais public), le temps de la rédaction du courriel. Les limites restent
    | sous celles de l'infrastructure : nginx client_max_body_size 25M, PHP
    | upload_max_filesize 20M, et environ 25 Mo par message chez la plupart des
    | fournisseurs de messagerie (les pièces jointes gonflent d'environ 37 %
    | en base64 : 15 Mo bruts restent donc sous 25 Mo une fois encodés).
    |
    */
    'mail' => [
        // Destinataire prérempli dans le champ « À » de la modale d'envoi (modifiable
        // par l'utilisateur ; vide pour ne rien préremplir).
        'default_recipient' => env('COTATIONS_MAIL_DEFAULT_RECIPIENT', 'cotation@jeudy-sa.fr'),

        'disk' => env('COTATIONS_MAIL_DISK', 'local'),

        // Fichiers ajoutés manuellement (le PDF généré n'entre pas dans ce compte).
        'max_files' => (int) env('COTATIONS_MAIL_MAX_FILES', 5),

        // Taille maximale d'un fichier ajouté, en Ko.
        'max_file_kb' => (int) env('COTATIONS_MAIL_MAX_FILE_KB', 5120),

        // Taille totale maximale de toutes les pièces jointes (PDF inclus), en Ko.
        'max_total_kb' => (int) env('COTATIONS_MAIL_MAX_TOTAL_KB', 15360),

        // Durée de conservation d'un brouillon abandonné, en heures.
        'expire_hours' => (int) env('COTATIONS_MAIL_EXPIRE_HOURS', 6),

        // extension => types MIME réels acceptés (détectés sur le contenu).
        'allowed' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain', 'application/csv'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'xls' => ['application/vnd.ms-excel', 'application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ],
    ],
];
