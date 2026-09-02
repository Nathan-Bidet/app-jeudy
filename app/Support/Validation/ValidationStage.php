<?php

namespace App\Support\Validation;

/**
 * Vocabulaire commun aux Congés et aux Heures pour la validation à deux
 * niveaux.
 *
 * `PENDING_VALIDATOR_1` vaut délibérément `pending` : c'est la valeur que
 * portent déjà toutes les demandes de congé en base. Le premier niveau n'est
 * pas un nouvel état, c'est celui qui existait — seul le second est nouveau.
 * Aucune ligne d'historique n'a donc besoin d'être réécrite, et le calendrier,
 * les exports et le front continuent de lire ce qu'ils lisaient.
 */
final class ValidationStage
{
    public const PENDING_VALIDATOR_1 = 'pending';

    public const PENDING_VALIDATOR_2 = 'pending_validator_2';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    /** États dans lesquels une décision de valideur est encore attendue. */
    public const OPEN = [
        self::PENDING_VALIDATOR_1,
        self::PENDING_VALIDATOR_2,
    ];

    /** États terminaux : plus aucune transition de validation n'est possible. */
    public const TERMINAL = [
        self::APPROVED,
        self::REFUSED,
    ];

    public static function isOpen(?string $status): bool
    {
        return in_array((string) $status, self::OPEN, true);
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array((string) $status, self::TERMINAL, true);
    }

    /**
     * Niveau attendu pour l'état donné, ou null si plus rien n'est attendu.
     */
    public static function levelFor(?string $status): ?int
    {
        return match ((string) $status) {
            self::PENDING_VALIDATOR_1 => 1,
            self::PENDING_VALIDATOR_2 => 2,
            default => null,
        };
    }

    /**
     * Libellé destiné à l'utilisateur.
     *
     * `$hasSecondLevel` distingue « 1/2 » de « 1/1 » : un utilisateur dont le
     * groupe n'a pas de Valideur 2 (ou qui n'a pas encore de groupe) suit un
     * circuit à un seul niveau, exactement comme avant cette évolution.
     */
    public static function label(?string $status, bool $hasSecondLevel = true): string
    {
        return match ((string) $status) {
            self::PENDING_VALIDATOR_1 => $hasSecondLevel
                ? 'En attente de validation 1/2'
                : 'En attente de validation',
            self::PENDING_VALIDATOR_2 => 'En attente de validation 2/2',
            self::APPROVED => 'Validé',
            self::REFUSED => 'Refusé',
            default => 'En attente',
        };
    }
}
