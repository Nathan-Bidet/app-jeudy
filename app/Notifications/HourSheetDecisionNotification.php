<?php

namespace App\Notifications;

use App\Models\HourSheet;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notification adressée au salarié à l'issue du circuit de validation d'une
 * journée d'heures : acceptation définitive, ou refus.
 *
 * Une seule classe pour les deux issues, contrairement aux congés qui en ont
 * une par cas. Les congés se comptent en dizaines par an et par personne ; les
 * journées d'heures en centaines. Multiplier les classes ici n'apporterait
 * rien qu'un `type` différent, que cette charge utile porte déjà.
 *
 * Aucune notification n'est émise vers les valideurs à la soumission ni au
 * passage de niveau : avec une journée soumise par personne et par jour, ce
 * serait plusieurs dizaines de notifications quotidiennes par valideur. Les
 * valideurs suivent leur file via le compteur de la page Heures.
 */
class HourSheetDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly HourSheet $hourSheet,
        private readonly bool $isApproved,
        private readonly ?string $decidedByLabel = null,
        private readonly ?string $refusalReason = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $workDate = $this->hourSheet->work_date?->toDateString();

        return [
            'type' => $this->isApproved ? 'hour_sheet_approved' : 'hour_sheet_refused',
            'hour_sheet_id' => (int) $this->hourSheet->id,
            'work_date' => $workDate,
            'decided_by_label' => $this->decidedByLabel,
            'refusal_reason' => $this->refusalReason,
            'message' => $this->isApproved
                ? sprintf(
                    'Vos heures du %s ont été définitivement validées.',
                    $this->formatDateFr($workDate),
                )
                : sprintf(
                    'Vos heures du %s ont été refusées%s.%s',
                    $this->formatDateFr($workDate),
                    $this->decidedByLabel !== null ? ' par '.$this->decidedByLabel : '',
                    $this->refusalReason !== null && $this->refusalReason !== ''
                        ? ' Motif : '.$this->refusalReason
                        : '',
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
