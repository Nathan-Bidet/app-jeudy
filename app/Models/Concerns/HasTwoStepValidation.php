<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Models\ValidationGroup;
use App\Support\Validation\ValidationStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Colonnes, relations et lectures communes aux objets soumis à la validation
 * à deux niveaux (demandes de congé, journées d'heures).
 *
 * Les colonnes `validator_1_*` / `validator_2_*` sont un INSTANTANÉ pris au
 * moment de la soumission, jamais relu depuis le groupe. C'est ce qui rend
 * l'historique insensible aux modifications ultérieures de l'administration :
 * changer les valideurs d'un groupe, renommer le groupe, le supprimer, ou
 * déplacer un utilisateur d'un groupe à l'autre ne réécrit aucune demande déjà
 * partie en validation.
 *
 * Les libellés `validator_1_label` / `validator_2_label` doublent les clés
 * étrangères pour la même raison : la suppression d'un compte vide la clé, le
 * libellé, lui, dit toujours qui était le valideur.
 */
trait HasTwoStepValidation
{
    public function validator1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_1_id');
    }

    public function validator2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_2_id');
    }

    public function validator1DecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_1_decided_by_id');
    }

    public function validator2DecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_2_decided_by_id');
    }

    public function validationGroup(): BelongsTo
    {
        return $this->belongsTo(ValidationGroup::class, 'validation_group_id');
    }

    public function isPendingValidator1(): bool
    {
        return $this->status === ValidationStage::PENDING_VALIDATOR_1;
    }

    public function isPendingValidator2(): bool
    {
        return $this->status === ValidationStage::PENDING_VALIDATOR_2;
    }

    /**
     * Un second niveau a-t-il été prévu à la soumission ?
     *
     * Faux quand l'utilisateur n'appartenait à aucun groupe, ou que son groupe
     * n'avait pas de Valideur 2 : le circuit est alors à un seul niveau, comme
     * avant cette évolution.
     */
    public function hasSecondValidationLevel(): bool
    {
        return $this->validator_2_id !== null;
    }

    /**
     * Niveau auquel se trouve l'objet, ou null s'il est dans un état terminal.
     */
    public function currentValidationLevel(): ?int
    {
        return ValidationStage::levelFor($this->status);
    }

    public function validationStatusLabel(): string
    {
        return ValidationStage::label($this->status, $this->hasSecondValidationLevel());
    }

    /**
     * Valideur dont la décision est attendue maintenant.
     */
    public function currentValidatorId(): ?int
    {
        return match ($this->currentValidationLevel()) {
            1 => $this->validator_1_id !== null ? (int) $this->validator_1_id : null,
            2 => $this->validator_2_id !== null ? (int) $this->validator_2_id : null,
            default => null,
        };
    }

    /**
     * Objets en attente d'une décision de cet utilisateur, au niveau qui le
     * concerne et à ce niveau seulement.
     *
     * C'est cette portée qui garantit qu'une même demande n'apparaît jamais
     * « à traiter » chez les deux valideurs en même temps.
     */
    public function scopeAwaitingDecisionBy(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $query->where(function (Builder $query) use ($userId): void {
            $query
                ->where(function (Builder $first) use ($userId): void {
                    $first
                        ->where('status', ValidationStage::PENDING_VALIDATOR_1)
                        ->where('validator_1_id', $userId);
                })
                ->orWhere(function (Builder $second) use ($userId): void {
                    $second
                        ->where('status', ValidationStage::PENDING_VALIDATOR_2)
                        ->where('validator_2_id', $userId);
                });
        });
    }

    /**
     * Tout ce qui relève d'un utilisateur en tant que valideur, décidé ou non.
     *
     * Sert aux écrans d'historique : un valideur doit pouvoir relire ce qu'il a
     * traité, pas seulement ce qui l'attend.
     */
    public function scopeValidatedBy(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $query->where(function (Builder $query) use ($userId): void {
            $query
                ->where('validator_1_id', $userId)
                ->orWhere('validator_2_id', $userId);
        });
    }

    /**
     * Instantané des acteurs de la validation, pour l'audit et les écrans de
     * détail.
     *
     * @return array<string, mixed>
     */
    public function validationTrail(): array
    {
        return [
            'group_id' => $this->validation_group_id !== null ? (int) $this->validation_group_id : null,
            'group_name' => $this->validation_group_name,
            'has_second_level' => $this->hasSecondValidationLevel(),
            'validator_1' => [
                'user_id' => $this->validator_1_id !== null ? (int) $this->validator_1_id : null,
                'label' => $this->validator_1_label,
                'decided_at' => $this->validator_1_decided_at?->toIso8601String(),
                'decided_by_id' => $this->validator_1_decided_by_id !== null ? (int) $this->validator_1_decided_by_id : null,
            ],
            'validator_2' => [
                'user_id' => $this->validator_2_id !== null ? (int) $this->validator_2_id : null,
                'label' => $this->validator_2_label,
                'decided_at' => $this->validator_2_decided_at?->toIso8601String(),
                'decided_by_id' => $this->validator_2_decided_by_id !== null ? (int) $this->validator_2_decided_by_id : null,
            ],
        ];
    }
}
