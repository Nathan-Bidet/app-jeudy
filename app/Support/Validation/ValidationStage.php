<?php

namespace App\Support\Validation;

/**
 * Vocabulaire commun aux Congés et aux Heures pour la double validation.
 *
 * Les deux valideurs décident INDÉPENDAMMENT l'un de l'autre, dans l'ordre
 * qu'ils veulent. Le statut ne décrit donc pas une étape mais l'issue globale :
 * en attente tant que les deux accords ne sont pas réunis, validé quand ils le
 * sont, refusé dès qu'un seul refus tombe.
 *
 * L'état individuel de chaque valideur ne vit pas ici : il est porté par les
 * colonnes `validator_1_decision` / `validator_2_decision` de chaque objet.
 */
final class ValidationStage
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    /**
     * Décisions individuelles possibles pour un valideur.
     */
    public const DECISION_APPROVED = 'approved';

    public const DECISION_REFUSED = 'refused';

    /**
     * État de l'ancien circuit séquentiel (« en attente du Valideur 2 »).
     *
     * Plus aucune écriture ne le produit et la migration a ramené les lignes
     * concernées sur `pending`. La constante subsiste, et `isOpen()` la
     * reconnaît, pour qu'une base pas encore migrée ne rende pas les demandes
     * invisibles à leurs valideurs.
     *
     * @deprecated Remplacé par PENDING + décisions individuelles.
     */
    public const LEGACY_PENDING_VALIDATOR_2 = 'pending_validator_2';

    /** États dans lesquels une décision de valideur est encore attendue. */
    public const OPEN = [
        self::PENDING,
        self::LEGACY_PENDING_VALIDATOR_2,
    ];

    /** États terminaux : plus aucune décision n'est possible. */
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
     * Libellé global.
     *
     * Volontairement sans numéro d'étape : « 1/2 » laisserait croire à un ordre
     * entre les deux valideurs, alors qu'ils sont interchangeables. Le détail
     * par valideur est rendu à côté, via decisionLabel().
     */
    public static function label(?string $status): string
    {
        return match ((string) $status) {
            self::APPROVED => 'Validé',
            self::REFUSED => 'Refusé',
            default => 'En attente de validation',
        };
    }

    /**
     * Libellé de la décision d'un valideur, sans jamais nommer personne.
     */
    public static function decisionLabel(?string $decision): string
    {
        return match ((string) $decision) {
            self::DECISION_APPROVED => 'Validé',
            self::DECISION_REFUSED => 'Refusé',
            default => 'En attente',
        };
    }
}
