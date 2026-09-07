<?php

namespace App\Services\Validation;

use App\Mail\HourSheetSubmittedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\ValidationGroup;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails informatifs adressés aux destinataires configurés sur un groupe de
 * validation, lors d'une nouvelle soumission.
 *
 * S'AJOUTE aux notifications existantes, ne remplace rien : les deux valideurs
 * continuent de recevoir leur notification interne et, s'ils l'ont activée,
 * leur notification native. Ce service ne connaît que des adresses email.
 *
 * CONFIDENTIALITÉ — un envoi par destinataire, jamais un envoi collectif. Ni
 * `to` multiple ni copie cachée : aucun destinataire ne voit à qui d'autre
 * l'email est parti, et un échec sur une adresse n'empêche pas les suivantes
 * d'être servies. Ces adresses sont souvent nominatives (un responsable, une
 * comptable) et n'ont pas à être diffusées entre services.
 *
 * ROBUSTESSE — un email qui ne part pas ne doit jamais faire échouer la
 * soumission qui l'a déclenché. Tout est encapsulé et journalisé ; la demande
 * ou la journée reste enregistrée quoi qu'il arrive.
 */
class ValidationGroupMailer
{
    /**
     * Prévient les destinataires du groupe d'une nouvelle demande de congé.
     *
     * @return int nombre d'emails effectivement mis en file
     */
    public function sendForLeaveRequest(LeaveRequest $leaveRequest, string $requesterLabel): int
    {
        return $this->dispatchTo(
            $this->groupOf($leaveRequest->validation_group_id),
            fn (): Mailable => new LeaveRequestSubmittedMail(
                LeaveRequestSubmittedMail::detailsFor($leaveRequest, $requesterLabel)
            ),
            ['module' => 'conges', 'leave_request_id' => (int) $leaveRequest->id],
        );
    }

    /**
     * Prévient les destinataires du groupe d'une journée d'heures soumise.
     *
     * @return int nombre d'emails effectivement mis en file
     */
    public function sendForHourSheet(HourSheet $hourSheet, string $userLabel): int
    {
        return $this->dispatchTo(
            $this->groupOf($hourSheet->validation_group_id),
            fn (): Mailable => new HourSheetSubmittedMail(
                HourSheetSubmittedMail::detailsFor($hourSheet, $userLabel)
            ),
            ['module' => 'heures', 'hour_sheet_id' => (int) $hourSheet->id],
        );
    }

    /**
     * Groupe RÉELLEMENT rattaché à l'élément, relu depuis sa clé.
     *
     * L'élément porte un instantané du groupe (identifiant et nom figés à la
     * soumission) ; la configuration email, elle, est lue en direct. C'est
     * voulu : on veut les destinataires tels qu'ils sont configurés au moment
     * de l'envoi, pas ceux d'une version passée.
     *
     * Un élément sans groupe — salarié hors groupe, ou antérieur à la date
     * d'effet — ne déclenche aucun email : il n'y a personne à prévenir.
     */
    private function groupOf(mixed $groupId): ?ValidationGroup
    {
        if ($groupId === null) {
            return null;
        }

        return ValidationGroup::query()->find((int) $groupId);
    }

    /**
     * @param  callable(): Mailable  $factory  construit un exemplaire neuf par destinataire
     * @param  array<string, mixed>  $context  ce qui identifie l'élément dans les journaux
     */
    private function dispatchTo(?ValidationGroup $group, callable $factory, array $context): int
    {
        // emailRecipients() porte à la fois le drapeau et le dédoublonnage :
        // option désactivée ou liste vide, il n'y a rien à faire.
        $recipients = $group?->emailRecipients() ?? [];

        if ($recipients === []) {
            return 0;
        }

        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                // Mailable neuf à chaque tour : un exemplaire réutilisé
                // accumulerait les destinataires d'un envoi à l'autre.
                Mail::to($recipient)->send($factory());
                $sent++;
            } catch (Throwable $exception) {
                // Une adresse en échec ne prive pas les autres de l'email, et
                // n'annule surtout pas la soumission déjà enregistrée.
                Log::warning('Email de groupe de validation : envoi impossible', $context + [
                    'validation_group_id' => (int) $group->id,
                    'recipient' => $recipient,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        Log::info('Email de groupe de validation : envoi déclenché', $context + [
            'validation_group_id' => (int) $group->id,
            'recipients' => count($recipients),
            'sent' => $sent,
        ]);

        return $sent;
    }
}
