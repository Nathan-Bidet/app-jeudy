<?php

namespace App\Notifications;

use App\Models\MaintenanceTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Affectation, réaffectation, retrait ou modification d'une tâche de
 * maintenance, adressée à l'utilisateur concerné.
 *
 * Comme pour la demande, aucun commentaire n'entre dans le payload.
 */
class MaintenanceTaskAssignedNotification extends Notification
{
    use Queueable;

    public const REASON_ASSIGNED = 'assigned';

    public const REASON_UNASSIGNED = 'unassigned';

    public const REASON_UPDATED = 'updated';

    public function __construct(
        private readonly MaintenanceTask $task,
        private readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'maintenance_task_assigned',
            'reason' => $this->reason,
            'maintenance_task_id' => (int) $this->task->id,
            'title' => $this->title(),
            'message' => $this->message(),
        ];
    }

    public function title(): string
    {
        return match ($this->reason) {
            self::REASON_UNASSIGNED => 'Tâche de maintenance retirée',
            self::REASON_UPDATED => 'Tâche de maintenance modifiée',
            default => 'Nouvelle tâche de maintenance',
        };
    }

    public function message(): string
    {
        $date = $this->task->date?->format('d/m/Y') ?? '-';
        $excerpt = MaintenanceTaskSummary::excerpt($this->task->task);

        return match ($this->reason) {
            self::REASON_UNASSIGNED => sprintf('Cette tâche ne vous est plus affectée : %s', $excerpt),
            self::REASON_UPDATED => sprintf('Votre tâche du %s a été mise à jour : %s', $date, $excerpt),
            default => sprintf('Une tâche vous a été affectée pour le %s : %s', $date, $excerpt),
        };
    }
}
