<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Prévient le demandeur / le collaborateur concerné qu'un congé (en attente ou
 * validé) a été supprimé par un tiers. Ne contient que des valeurs simples : la
 * demande n'existe plus au moment de la lecture. Le nom de l'auteur n'est pas
 * transmis (il reste dans le journal d'audit).
 */
class LeaveRequestDeletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ?string $startAt,
        private readonly ?string $endAt,
        private readonly bool $wasApproved,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_request_deleted',
            'period' => ['start_at' => $this->startAt, 'end_at' => $this->endAt],
            'message' => sprintf(
                '%s du %s au %s a été supprimé.',
                $this->wasApproved ? 'Votre congé validé' : 'Votre demande de congé',
                $this->format($this->startAt),
                $this->format($this->endAt),
            ),
        ];
    }

    private function format(?string $isoDate): string
    {
        if (! $isoDate || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m)) {
            return '-';
        }

        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }
}
