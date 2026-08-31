<?php

namespace App\Notifications;

/**
 * Extrait court de la description d'une tâche, pour les libellés de
 * notification. Volontairement limité à la description : le commentaire n'a
 * jamais sa place dans une notification, puisqu'il peut être masqué.
 */
final class MaintenanceTaskSummary
{
    public static function excerpt(?string $task, int $length = 80): string
    {
        $value = trim((string) $task);

        if ($value === '') {
            return 'Tâche sans description';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strlen($value) > $length
            ? mb_substr($value, 0, $length - 1).'…'
            : $value;
    }
}
