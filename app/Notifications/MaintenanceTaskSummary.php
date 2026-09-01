<?php

namespace App\Notifications;

use App\Models\MaintenanceTask;

/**
 * Extrait court de la description d'une tâche, pour les libellés de
 * notification. Volontairement limité à la description : le commentaire n'a
 * jamais sa place dans une notification, puisqu'il peut être masqué.
 */
final class MaintenanceTaskSummary
{
    /**
     * Date qui situe la tâche dans un libellé de notification.
     *
     * Une demande n'a pas de date de début — c'est la personne qui la
     * transforme en tâche qui la fixe : sa date souhaitée est alors la seule
     * qui ait un sens. Renvoie null quand aucune n'est connue, pour que le
     * message omette le fragment plutôt que d'afficher un tiret.
     */
    public static function dateLabel(?MaintenanceTask $task): ?string
    {
        if ($task === null) {
            return null;
        }

        $moment = $task->isPendingRequest()
            ? $task->due_date
            : ($task->date ?? $task->due_date);

        return $moment?->format('d/m/Y');
    }

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
