<?php

namespace App\Services\Validation;

use App\Support\Validation\ValidationStage;

/**
 * Résultat d'une tentative de décision de validation.
 *
 * L'appelant s'en sert pour décider quoi notifier et quoi journaliser sans
 * avoir à relire l'état du modèle ni à redevîner ce qui vient de se passer.
 */
final class ValidationTransition
{
    /**
     * @param  array<int, int>  $levels  Rangs effectivement tranchés.
     */
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $levels,
        public readonly ?string $decision,
        public readonly bool $isFinal,
        public readonly bool $wasApplied,
    ) {}

    /**
     * @param  array<int, int>  $levels
     */
    public static function applied(string $from, string $to, array $levels, string $decision, bool $isFinal): self
    {
        return new self($from, $to, $levels, $decision, $isFinal, true);
    }

    /**
     * Décision ignorée parce que l'objet n'était plus dans l'état attendu :
     * un second onglet, un double-clic, ou l'autre valideur passé entre-temps.
     * L'appelant ne doit alors ni notifier ni journaliser une décision.
     */
    public static function skipped(string $current): self
    {
        return new self($current, $current, [], null, false, false);
    }

    /**
     * L'objet est-il devenu définitivement validé grâce à cette décision ?
     */
    public function completesApproval(): bool
    {
        return $this->wasApplied && $this->to === ValidationStage::APPROVED;
    }

    /**
     * Accord enregistré, mais l'autre valideur doit encore se prononcer.
     */
    public function isPartialApproval(): bool
    {
        return $this->wasApplied
            && $this->decision === ValidationStage::DECISION_APPROVED
            && $this->to === ValidationStage::PENDING;
    }

    public function isRefusal(): bool
    {
        return $this->wasApplied && $this->decision === ValidationStage::DECISION_REFUSED;
    }
}
