<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Adressée au Valideur 2 quand le Valideur 1 vient de donner son accord.
 *
 * Elle reprend la forme des notifications de congé existantes (canal
 * `database`, charge utile `type` + `message`) pour que le centre de
 * notifications l'affiche sans traitement particulier.
 */
class LeaveRequestFirstLevelApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
        private readonly string $targetLabel,
        private readonly string $firstValidatorLabel,
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
            'type' => 'leave_request_first_level_approved',
            'leave_request_id' => (int) $this->leaveRequest->id,
            'target_user_id' => (int) $this->leaveRequest->target_user_id,
            'target_label' => $this->targetLabel,
            'first_validator_label' => $this->firstValidatorLabel,
            'period' => [
                'start_at' => $startAt,
                'end_at' => $endAt,
            ],
            'message' => sprintf(
                'La demande de congé de %s du %s au %s a été validée au premier niveau par %s et nécessite votre validation.',
                $this->targetLabel,
                $this->formatDateFr($startAt),
                $this->formatDateFr($endAt),
                $this->firstValidatorLabel,
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
