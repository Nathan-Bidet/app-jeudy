<?php

namespace App\Support\Hours;

use App\Models\HourSheet;
use App\Support\Validation\ValidationStage;

/**
 * Ce que le PROPRIÉTAIRE d'une journée d'heures en voit.
 *
 * Le salarié sait si sa journée est encore en validation, mais pas si elle a
 * été validée ou refusée : une journée dont le circuit est clos lui apparaît
 * « Traitée », sans motif. L'état réel (`status`, décisions de chaque rang,
 * `refusal_reason`) reste en base, et les valideurs, l'administration,
 * l'export administratif et le journal d'audit continuent de le lire tel quel.
 *
 * Cette représentation est faite côté serveur : ni l'état réel ni le motif
 * ne sont transmis au navigateur du salarié.
 */
class HourSheetOwnerView
{
    public const STATUS_PROCESSED = 'processed';

    public const LABEL_PROCESSED = 'Traitée';

    public const LABEL_LEGACY = 'Saisie antérieure à la validation';

    /** Types des notifications de décision émises par le passé. */
    private const DECISION_NOTIFICATION_TYPES = ['hour_sheet_refused', 'hour_sheet_approved'];

    /**
     * @return array{status: ?string, status_label: string}
     */
    public static function status(HourSheet $hourSheet): array
    {
        if ($hourSheet->isLegacyEntry()) {
            return ['status' => null, 'status_label' => self::LABEL_LEGACY];
        }

        if (ValidationStage::isTerminal($hourSheet->status)) {
            return ['status' => self::STATUS_PROCESSED, 'status_label' => self::LABEL_PROCESSED];
        }

        return ['status' => $hourSheet->status, 'status_label' => $hourSheet->validationStatusLabel()];
    }

    public static function isDecisionNotification(string $type): bool
    {
        return in_array($type, self::DECISION_NOTIFICATION_TYPES, true);
    }

    /**
     * Notifications `hour_sheet_refused` / `hour_sheet_approved` déjà en base :
     * elles ne sont ni modifiées ni supprimées, mais servies au salarié sous
     * une forme neutre — sans le type, le mot « refusées » ni le motif.
     *
     * @param  array<string, mixed>  $data
     * @return array{type: string, message: string}
     */
    public static function neutralDecisionNotification(array $data): array
    {
        $workDate = (string) ($data['work_date'] ?? '');
        $formatted = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $workDate, $parts)
            ? sprintf('%s-%s-%s', $parts[3], $parts[2], $parts[1])
            : null;

        return [
            'type' => 'hour_sheet_processed',
            'message' => $formatted !== null
                ? sprintf('Vos heures du %s ont été traitées.', $formatted)
                : 'Vos heures ont été traitées.',
        ];
    }
}
