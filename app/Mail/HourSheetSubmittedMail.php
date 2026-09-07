<?php

namespace App\Mail;

use App\Models\HourSheet;
use App\Support\Hours\WorkTimeReference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email informatif adressé aux destinataires configurés sur un groupe de
 * validation, lorsqu'une journée d'heures lui est soumise.
 *
 * Mêmes principes que LeaveRequestSubmittedMail : en plus des notifications
 * internes, purement informatif, mis en file d'attente.
 */
class HourSheetSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public readonly array $details)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Heures à valider — %s, %s',
                $this->details['user_label'] ?? 'Collaborateur',
                $this->details['work_date'] ?? '',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.validation.hour-sheet',
            with: ['details' => $this->details],
        );
    }

    /**
     * Charge utile de l'email.
     *
     * Le total et les heures supplémentaires ne sont pas recalculés ici : le
     * total vient de la journée elle-même, l'écart de WorkTimeReference — la
     * même source que le badge de la page Heures, la file du valideur et
     * l'export Excel. Aucune quatrième version du calcul.
     *
     * @return array<string, mixed>
     */
    public static function detailsFor(HourSheet $hourSheet, string $userLabel): array
    {
        $totalMinutes = (int) $hourSheet->total_minutes;
        $isNotWorked = (bool) $hourSheet->is_not_worked;

        $overtime = WorkTimeReference::overtimeForDay(
            $totalMinutes,
            $hourSheet->work_date?->toDateString(),
            $isNotWorked,
        );

        return [
            'user_label' => $userLabel,
            'work_date' => $hourSheet->work_date?->format('d/m/Y'),
            'is_not_worked' => $isNotWorked,
            'schedule' => $isNotWorked ? null : self::scheduleLabel($hourSheet),
            'total' => $isNotWorked ? null : WorkTimeReference::formatMinutes($totalMinutes),
            // Null quand il n'y a pas de dépassement : une ligne « 00h00 »
            // n'apprendrait rien et alourdirait l'email.
            'overtime' => $overtime > 0 ? WorkTimeReference::formatMinutes($overtime) : null,
            'description' => $hourSheet->description,
            'extras' => $isNotWorked ? [] : self::checkedExtras($hourSheet),
            'group_name' => $hourSheet->validation_group_name,
        ];
    }

    /**
     * Horaires saisis, dans la même forme que la file du valideur.
     */
    private static function scheduleLabel(HourSheet $hourSheet): ?string
    {
        // Les heures passent toutes par WorkTimeReference : la base les renvoie
        // avec les secondes, que l'application n'affiche jamais.
        $at = fn (?string $value): ?string => WorkTimeReference::formatTimeOfDay($value);

        if ((bool) $hourSheet->is_continuous_day) {
            return sprintf(
                '%s - %s (journée continue)',
                $at($hourSheet->morning_start) ?? '--:--',
                $at($hourSheet->afternoon_end) ?? '--:--',
            );
        }

        $ranges = [];

        if ($hourSheet->morning_start || $hourSheet->morning_end) {
            $ranges[] = sprintf('%s - %s', $at($hourSheet->morning_start) ?? '--:--', $at($hourSheet->morning_end) ?? '--:--');
        }

        if ($hourSheet->afternoon_start || $hourSheet->afternoon_end) {
            $ranges[] = sprintf('%s - %s', $at($hourSheet->afternoon_start) ?? '--:--', $at($hourSheet->afternoon_end) ?? '--:--');
        }

        return $ranges === [] ? null : implode(' / ', $ranges);
    }

    /**
     * Cases particulières cochées, dans l'ordre du formulaire. Les libellés
     * sont ceux de l'export Excel.
     *
     * @return array<int, string>
     */
    private static function checkedExtras(HourSheet $hourSheet): array
    {
        $fields = [
            'has_breakfast_before_5' => 'Casse-croûte (avant 5h)',
            'has_lunch' => 'Déjeuner',
            'has_dinner_after_21' => 'Dîner (après 21h)',
            'has_long_night' => 'Nuit (déplacement long)',
        ];

        $checked = [];

        foreach ($fields as $field => $label) {
            if ((bool) $hourSheet->{$field}) {
                $checked[] = $label;
            }
        }

        return $checked;
    }
}
