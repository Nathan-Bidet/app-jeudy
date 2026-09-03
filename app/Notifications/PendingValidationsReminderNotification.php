<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Rappel hebdomadaire : ce qu'un valideur n'a pas encore tranché.
 *
 * UNE SEULE notification par valideur, tous modules et tous groupes confondus.
 * Elle ne contient que des compteurs — aucun nom de demandeur, aucune date :
 * c'est un rappel, pas un résumé, et le détail est à un clic dans la file.
 *
 * Canal `database` uniquement, comme tout le module : le centre de
 * notifications est la source, et la notification native est envoyée à côté par
 * la commande, seulement si l'utilisateur l'a activée.
 */
class PendingValidationsReminderNotification extends Notification
{
    use Queueable;

    public const TYPE = 'pending_validations_reminder';

    public const TITLE = 'Validations en attente';

    public function __construct(
        private readonly int $leaveCount,
        private readonly int $hoursCount,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => self::TITLE,
            'leave_count' => $this->leaveCount,
            'hours_count' => $this->hoursCount,
            'total_count' => $this->leaveCount + $this->hoursCount,
            'target' => self::target($this->leaveCount, $this->hoursCount),
            'message' => self::message($this->leaveCount, $this->hoursCount),
        ];
    }

    /**
     * Formulation unique, réutilisée telle quelle par la notification native :
     * les deux canaux ne peuvent pas dire deux choses différentes.
     */
    public static function message(int $leaveCount, int $hoursCount): string
    {
        if ($leaveCount > 0 && $hoursCount > 0) {
            return sprintf(
                'Vous avez %d éléments en attente de validation : %s et %s.',
                $leaveCount + $hoursCount,
                self::leavePart($leaveCount),
                self::hoursPart($hoursCount),
            );
        }

        if ($leaveCount > 0) {
            return sprintf(
                'Vous avez %s en attente de validation.',
                $leaveCount > 1
                    ? sprintf('%d demandes de congés', $leaveCount)
                    : '1 demande de congé',
            );
        }

        return sprintf('Vous avez %s en attente de validation.', self::hoursPart($hoursCount));
    }

    /**
     * Destination du lien.
     *
     * Le centre de notifications n'accepte qu'une seule adresse et il n'existe
     * pas de page réunissant les deux files. Quand les deux modules sont
     * concernés, le lien mène aux Congés : une demande de congé bloque
     * quelqu'un qui attend de savoir s'il peut s'absenter, là où une journée
     * d'heures est rétrospective. Créer une page uniquement pour ce lien serait
     * disproportionné.
     */
    public static function target(int $leaveCount, int $hoursCount): string
    {
        return $leaveCount > 0 ? 'leaves' : 'hours';
    }

    private static function leavePart(int $count): string
    {
        return $count > 1 ? sprintf('%d congés', $count) : '1 congé';
    }

    private static function hoursPart(int $count): string
    {
        return $count > 1
            ? sprintf('%d journées d\'heures', $count)
            : '1 journée d\'heures';
    }
}
