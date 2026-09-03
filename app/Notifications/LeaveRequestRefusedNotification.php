<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestRefusedNotification extends Notification
{
    use Queueable;

    /**
     * Le nom du valideur n'est volontairement pas transmis : l'identité des
     * valideurs n'est pas exposée aux utilisateurs. Elle reste dans les
     * colonnes de la demande et dans le journal d'audit.
     */
    public function __construct(
        private readonly LeaveRequest $leaveRequest,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $startAt = $this->leaveRequest->start_at?->toDateString();
        $endAt = $this->leaveRequest->end_at?->toDateString();

        return [
            'type' => 'leave_request_refused',
            'leave_request_id' => (int) $this->leaveRequest->id,
            'period' => [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ],
            'message' => sprintf(
                'Votre demande du %s au %s a été refusée.',
                $this->formatDateFr($startAt),
                $this->formatDateFr($endAt),
            ),
        ];
    }

    private function formatDateFr(?string $isoDate): string
    {
        if (! $isoDate || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
            return '-';
        }

        [$year, $month, $day] = explode('-', $isoDate);

        return sprintf('%s-%s-%s', $day, $month, $year);
    }
}
