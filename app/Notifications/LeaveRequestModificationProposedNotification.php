<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestModificationProposedNotification extends Notification
{
    use Queueable;

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
        $proposedStartAt = $this->leaveRequest->proposed_start_at?->toDateString();
        $proposedEndAt = $this->leaveRequest->proposed_end_at?->toDateString();

        return [
            'type' => 'leave_request_modification_proposed',
            'leave_request_id' => (int) $this->leaveRequest->id,
            'period' => [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ],
            'proposed_period' => [
                'start_at' => $proposedStartAt,
                'end_at' => $proposedEndAt,
            ],
            'message' => sprintf(
                'Une modification a été proposée pour votre demande du %s au %s. Nouvelle période proposée : du %s au %s.',
                $this->formatDateFr($startAt),
                $this->formatDateFr($endAt),
                $this->formatDateFr($proposedStartAt),
                $this->formatDateFr($proposedEndAt),
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
