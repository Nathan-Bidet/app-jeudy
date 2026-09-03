<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Prévient un valideur que des journées déjà saisies viennent d'entrer dans sa
 * file de validation, à la suite d'un rattrapage.
 *
 * UNE SEULE notification par valideur et par rattrapage, portant le nombre de
 * journées et la période couverte. Un rattrapage peut en reprendre plusieurs
 * centaines : une notification par journée rendrait le centre de notifications
 * inutilisable, et le module Heures ne notifie de toute façon jamais les
 * valideurs journée par journée.
 */
class HoursAttachedToValidationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $dayCount,
        private readonly string $firstWorkDate,
        private readonly string $lastWorkDate,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $period = $this->firstWorkDate === $this->lastWorkDate
            ? sprintf('du %s', $this->formatDateFr($this->firstWorkDate))
            : sprintf('du %s au %s', $this->formatDateFr($this->firstWorkDate), $this->formatDateFr($this->lastWorkDate));

        return [
            'type' => 'hours_attached_to_validation',
            'day_count' => $this->dayCount,
            'period' => [
                'start_at' => $this->firstWorkDate,
                'end_at' => $this->lastWorkDate,
            ],
            'message' => sprintf(
                '%d journée%s d\'heures %s %s ajoutée%s à vos heures à valider.',
                $this->dayCount,
                $this->dayCount > 1 ? 's' : '',
                $period,
                $this->dayCount > 1 ? 'ont été' : 'a été',
                $this->dayCount > 1 ? 's' : '',
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
