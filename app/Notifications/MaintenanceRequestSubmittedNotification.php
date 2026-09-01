<?php

namespace App\Notifications;

use App\Models\MaintenanceTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Nouvelle demande de maintenance, adressée aux responsables du traitement.
 *
 * Aucun commentaire n'est embarqué : ni en clair, ni tronqué, ni masqué. Le
 * payload est stocké en base et relu par un destinataire dont on ne connaît
 * pas, au moment de la lecture, les permissions sur les commentaires masqués.
 */
class MaintenanceRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MaintenanceTask $task,
        private readonly string $requesterLabel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    private function message(): string
    {
        $excerpt = MaintenanceTaskSummary::excerpt($this->task->task);
        $date = MaintenanceTaskSummary::dateLabel($this->task);

        // Sans date connue, le fragment disparaît : mieux vaut une phrase
        // courte qu'un tiret à la place d'une information.
        return $date === null
            ? sprintf('Demande de %s : %s', $this->requesterLabel, $excerpt)
            : sprintf('Demande de %s pour le %s : %s', $this->requesterLabel, $date, $excerpt);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'maintenance_request_submitted',
            'maintenance_task_id' => (int) $this->task->id,
            'requester_user_id' => $this->task->requested_by_user_id
                ? (int) $this->task->requested_by_user_id
                : null,
            'requester_label' => $this->requesterLabel,
            'title' => 'Nouvelle demande de maintenance',
            'message' => $this->message(),
        ];
    }
}
