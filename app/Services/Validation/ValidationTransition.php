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
     * Cette décision a-t-elle clos le circuit ?
     *
     * Vrai seulement quand les deux valideurs se sont prononcés. Le sens de
     * l'issue (validée ou refusée) se lit dans `$to` : il ne se déduit PAS de
     * la décision qui vient d'être prise, puisque c'est le Valideur 2 qui
     * tranche en cas de désaccord — un accord du Valideur 1 peut donc clore une
     * demande sur un refus, et son refus la clore sur une validation.
     */
    public function closesCircuit(): bool
    {
        return $this->wasApplied && ValidationStage::isTerminal($this->to);
    }

    public function completesApproval(): bool
    {
        return $this->closesCircuit() && $this->to === ValidationStage::APPROVED;
    }

    public function completesRefusal(): bool
    {
        return $this->closesCircuit() && $this->to === ValidationStage::REFUSED;
    }

    /**
     * Décision enregistrée, mais l'autre valideur doit encore se prononcer.
     */
    public function isPartial(): bool
    {
        return $this->wasApplied && $this->to === ValidationStage::PENDING;
    }

    public function isRefusal(): bool
    {
        return $this->wasApplied && $this->decision === ValidationStage::DECISION_REFUSED;
    }
}
