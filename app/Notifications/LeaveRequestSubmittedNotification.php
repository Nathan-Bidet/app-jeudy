<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
        private readonly string $requesterLabel,
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
            'type' => 'leave_request_submitted',
            'leave_request_id' => (int) $this->leaveRequest->id,
            'requester_user_id' => (int) $this->leaveRequest->requester_user_id,
            'requester_label' => $this->requesterLabel,
            'period' => [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ],
            'message' => sprintf(
                'Nouvelle demande de congé de %s du %s au %s.',
                $this->requesterLabel,
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
