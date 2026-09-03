<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Models\ValidationGroup;
use App\Support\Validation\ValidationStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Colonnes, relations et lectures communes aux objets soumis à la double
 * validation (demandes de congé, journées d'heures).
 *
 * Les deux valideurs sont sur le même plan : chacun porte sa propre décision
 * (`validator_N_decision`), sa propre date, et son propre auteur. Aucun des
 * deux n'attend l'autre.
 *
 * Les colonnes `validator_N_*` sont un INSTANTANÉ pris à la soumission, jamais
 * relu depuis le groupe : changer les valideurs d'un groupe, le renommer, le
 * supprimer ou déplacer un utilisateur ne réécrit aucune demande en cours.
 * Les libellés `validator_N_label` doublent les clés étrangères parce que la
 * suppression d'un compte vide la clé — le libellé, lui, dit toujours qui
 * était le valideur. Ils servent l'audit, pas l'affichage : les écrans
 * n'exposent que « Validé » / « Refusé » / « En attente ».
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

    /**
     * Un second valideur a-t-il été désigné à la soumission ?
     *
     * Faux quand l'utilisateur n'appartenait à aucun groupe, ou que son groupe
     * n'avait pas de Valideur 2 : un seul accord suffit alors.
     */
    public function hasSecondValidationLevel(): bool
    {
        return $this->validator_2_id !== null;
    }

    /**
     * Rangs réellement attendus sur cet objet : [1] ou [1, 2].
     *
     * @return array<int, int>
     */
    public function expectedValidationLevels(): array
    {
        return $this->hasSecondValidationLevel() ? [1, 2] : [1];
    }

    public function decisionForLevel(int $level): ?string
    {
        return $level === 1 ? $this->validator_1_decision : $this->validator_2_decision;
    }

    public function validatorIdForLevel(int $level): ?int
    {
        $id = $level === 1 ? $this->validator_1_id : $this->validator_2_id;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Rangs sur lesquels cet utilisateur est désigné et n'a pas encore décidé.
     *
     * Aucune notion d'ordre : le rang 2 est ouvert dès la création, exactement
     * comme le rang 1.
     *
     * @return array<int, int>
     */
    public function undecidedLevelsFor(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return array_values(array_filter(
            $this->expectedValidationLevels(),
            fn (int $level): bool => $this->validatorIdForLevel($level) === $userId
                && $this->decisionForLevel($level) === null,
        ));
    }

    /**
     * Rangs attendus qui n'ont encore reçu aucune décision, quel qu'en soit le
     * titulaire.
     *
     * @return array<int, int>
     */
    public function undecidedLevels(): array
    {
        return array_values(array_filter(
            $this->expectedValidationLevels(),
            fn (int $level): bool => $this->decisionForLevel($level) === null,
        ));
    }

    /**
     * Statut global déduit des décisions individuelles.
     *
     * Un seul refus suffit à refuser ; il faut en revanche TOUS les accords
     * attendus pour valider.
     */
    public function resolveGlobalStatus(): string
    {
        foreach ($this->expectedValidationLevels() as $level) {
            if ($this->decisionForLevel($level) === ValidationStage::DECISION_REFUSED) {
                return ValidationStage::REFUSED;
            }
        }

        foreach ($this->expectedValidationLevels() as $level) {
            if ($this->decisionForLevel($level) !== ValidationStage::DECISION_APPROVED) {
                return ValidationStage::PENDING;
            }
        }

        return ValidationStage::APPROVED;
    }

    public function validationStatusLabel(): string
    {
        return ValidationStage::label($this->status);
    }

    /**
     * État des deux valideurs, ANONYMISÉ : destiné aux écrans, il ne contient
     * ni nom ni identifiant. L'identité reste en base et dans validationTrail().
     *
     * @return array<int, array{level:int, decision:?string, label:string}>
     */
    public function validationSummary(): array
    {
        return array_map(
            fn (int $level): array => [
                'level' => $level,
                'decision' => $this->decisionForLevel($level),
                'label' => ValidationStage::decisionLabel($this->decisionForLevel($level)),
            ],
            $this->expectedValidationLevels(),
        );
    }

    /**
     * Objets en attente d'une décision de cet utilisateur.
     *
     * Une demande apparaît chez les DEUX valideurs dès sa création, et quitte
     * la liste de celui qui a tranché sans quitter celle de l'autre.
     */
    public function scopeAwaitingDecisionBy(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $query
            ->whereIn('status', ValidationStage::OPEN)
            ->where(function (Builder $query) use ($userId): void {
                $query
                    ->where(function (Builder $first) use ($userId): void {
                        $first
                            ->where('validator_1_id', $userId)
                            ->whereNull('validator_1_decision');
                    })
                    ->orWhere(function (Builder $second) use ($userId): void {
                        $second
                            ->where('validator_2_id', $userId)
                            ->whereNull('validator_2_decision');
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
     * Instantané complet, NOMS COMPRIS, pour l'audit et les journaux.
     *
     * Ne jamais renvoyer ceci à une vue : les écrans utilisent
     * validationSummary(), qui est anonymisé.
     *
     * @return array<string, mixed>
     */
    public function validationTrail(): array
    {
        return [
            'group_id' => $this->validation_group_id !== null ? (int) $this->validation_group_id : null,
            'group_name' => $this->validation_group_name,
            'has_second_level' => $this->hasSecondValidationLevel(),
            'global_status' => $this->status,
            'validator_1' => [
                'user_id' => $this->validator_1_id !== null ? (int) $this->validator_1_id : null,
                'label' => $this->validator_1_label,
                'decision' => $this->validator_1_decision,
                'decided_at' => $this->validator_1_decided_at?->toIso8601String(),
                'decided_by_id' => $this->validator_1_decided_by_id !== null ? (int) $this->validator_1_decided_by_id : null,
            ],
            'validator_2' => [
                'user_id' => $this->validator_2_id !== null ? (int) $this->validator_2_id : null,
                'label' => $this->validator_2_label,
                'decision' => $this->validator_2_decision,
                'decided_at' => $this->validator_2_decided_at?->toIso8601String(),
                'decided_by_id' => $this->validator_2_decided_by_id !== null ? (int) $this->validator_2_decided_by_id : null,
            ],
        ];
    }
}
