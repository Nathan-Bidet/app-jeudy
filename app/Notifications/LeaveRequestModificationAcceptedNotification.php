<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestModificationAcceptedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
        private readonly ?string $acceptedStartAt = null,
        private readonly ?string $acceptedEndAt = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $startAt = $this->acceptedStartAt ?: $this->leaveRequest->start_at?->toDateString();
        $endAt = $this->acceptedEndAt ?: $this->leaveRequest->end_at?->toDateString();

        return [
            'type' => 'leave_request_modification_accepted',
            'leave_request_id' => (int) $this->leaveRequest->id,
            'period' => [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ],
            'message' => sprintf(
                'Votre proposition de modification a été acceptée. Nouvelle période : du %s au %s.',
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
