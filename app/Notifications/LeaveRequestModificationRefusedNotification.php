<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestModificationRefusedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
        private readonly ?string $proposedStartAt = null,
        private readonly ?string $proposedEndAt = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $startAt = $this->proposedStartAt ?: $this->leaveRequest->proposed_start_at?->toDateString();
        $endAt = $this->proposedEndAt ?: $this->leaveRequest->proposed_end_at?->toDateString();

        return [
            'type' => 'leave_request_modification_refused',
            'leave_request_id' => (int) $this->leaveRequest->id,
            'period' => [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ],
            'message' => sprintf(
                'Votre proposition de modification a été refusée (période proposée : du %s au %s).',
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
