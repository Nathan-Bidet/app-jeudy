<?php

namespace App\Services\Validation;

/**
 * Résultat d'une tentative de transition de validation.
 *
 * L'appelant s'en sert pour décider quoi notifier et quoi journaliser sans
 * avoir à relire l'état du modèle ni à redevîner ce qui vient de se passer.
 */
final class ValidationTransition
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $level,
        public readonly bool $isFinal,
        public readonly bool $wasApplied,
    ) {}

    public static function applied(string $from, string $to, int $level, bool $isFinal): self
    {
        return new self($from, $to, $level, $isFinal, true);
    }

    /**
     * Transition ignorée parce que l'objet n'était plus dans l'état attendu :
     * un second onglet, un double-clic, ou l'autre valideur passé entre-temps.
     * L'appelant ne doit alors ni notifier ni journaliser une décision.
     */
    public static function skipped(string $current): self
    {
        return new self($current, $current, null, false, false);
    }
}
