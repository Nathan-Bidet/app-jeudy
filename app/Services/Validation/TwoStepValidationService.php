<?php

namespace App\Services\Validation;

use App\Models\User;
use App\Models\ValidationGroup;
use App\Support\Validation\ValidationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de validation à deux niveaux, partagé par les Congés et les Heures.
 *
 * Il ne connaît ni congé ni feuille d'heures : il travaille sur n'importe quel
 * modèle utilisant HasTwoStepValidation. Les deux modules y déposent la même
 * question — « qui doit valider, et cette personne a-t-elle le droit d'agir
 * maintenant ? » — et reçoivent la même réponse.
 *
 * Trois garanties sont tenues ici, et pas dans les contrôleurs :
 *
 *   1. L'ORDRE. Le niveau 2 n'est atteignable qu'après une décision de niveau 1.
 *      Aucune route ne permet de sauter une étape, puisque la transition part
 *      toujours de l'état lu en base sous verrou.
 *   2. LE PÉRIMÈTRE. Un valideur n'agit que sur les objets dont il est le
 *      valideur figé, au rang qui est le sien. Être Valideur 1 d'un groupe ne
 *      donne aucun droit sur les membres d'un autre groupe.
 *   3. L'UNICITÉ. La ligne est verrouillée et son état relu à l'intérieur de la
 *      transaction : deux clics simultanés ne produisent qu'une transition.
 */
class TwoStepValidationService
{
    public function __construct(private readonly ValidationGroupService $validationGroups) {}

    /**
     * Fige les valideurs sur l'objet et le place en attente du Valideur 1.
     *
     * Le premier valideur peut venir d'ailleurs que du groupe : les Congés
     * conservent leur cascade historique (valideur par utilisateur, puis par
     * secteur, puis administrateur) pour les utilisateurs qui n'ont pas encore
     * rejoint un groupe. C'est le rôle de $fallbackValidatorResolver, appelé
     * seulement si le groupe ne fournit pas de Valideur 1.
     *
     * Le second niveau, lui, n'a pas d'équivalent historique : sans Valideur 2
     * dans le groupe, le circuit reste à un seul niveau — le comportement
     * d'avant cette évolution, et non un blocage silencieux.
     *
     * @param  (callable(): ?User)|null  $fallbackValidatorResolver
     */
    public function assign(Model $subject, User $targetUser, ?callable $fallbackValidatorResolver = null): void
    {
        $group = $this->validationGroups->groupFor($targetUser);

        $validator1 = $this->usableValidator($group?->validator1);
        $validator2 = $this->usableValidator($group?->validator2);

        if (! $validator1 && $fallbackValidatorResolver !== null) {
            $validator1 = $fallbackValidatorResolver();
        }

        // Un groupe dont seul le Valideur 2 est exploitable ne doit pas laisser
        // la demande sans personne à l'étape 1 : le second prend alors le
        // premier rang, et le circuit devient à un niveau.
        if (! $validator1 && $validator2) {
            $validator1 = $validator2;
            $validator2 = null;
        }

        // Un même utilisateur aux deux rangs ferait valider deux fois la même
        // personne : le second niveau n'aurait aucun sens.
        if ($validator1 && $validator2 && (int) $validator1->id === (int) $validator2->id) {
            $validator2 = null;
        }

        $subject->validation_group_id = $group?->id;
        $subject->validation_group_name = $group?->name;
        $subject->validator_1_id = $validator1?->id;
        $subject->validator_1_label = $validator1 ? $this->userLabel($validator1) : null;
        $subject->validator_2_id = $validator2?->id;
        $subject->validator_2_label = $validator2 ? $this->userLabel($validator2) : null;
        $subject->validator_1_decided_at = null;
        $subject->validator_1_decided_by_id = null;
        $subject->validator_2_decided_at = null;
        $subject->validator_2_decided_by_id = null;
        $subject->status = ValidationStage::PENDING_VALIDATOR_1;
    }

    /**
     * Niveau auquel cet utilisateur peut agir sur cet objet, ou null.
     *
     * Le niveau renvoyé est toujours celui de l'étape courante : rien ne permet
     * d'obtenir 2 tant que l'objet est à l'étape 1.
     */
    public function authorizedLevelFor(Model $subject, ?User $actor): ?int
    {
        if (! $actor) {
            return null;
        }

        $level = ValidationStage::levelFor($subject->status);

        if ($level === null) {
            return null;
        }

        // Les administrateurs conservent la main qu'ils avaient déjà sur les
        // demandes — mais à l'étape courante, sans court-circuiter l'ordre.
        if ($actor->hasRole('admin')) {
            return $level;
        }

        $expectedValidatorId = $subject->currentValidatorId();

        return $expectedValidatorId !== null && $expectedValidatorId === (int) $actor->id
            ? $level
            : null;
    }

    public function canDecide(Model $subject, ?User $actor): bool
    {
        return $this->authorizedLevelFor($subject, $actor) !== null;
    }

    /**
     * Valide l'étape courante.
     *
     * Étape 1 avec un second niveau prévu → passe au Valideur 2.
     * Étape 1 sans second niveau, ou étape 2 → validation définitive.
     */
    public function approve(Model $subject, User $actor): ValidationTransition
    {
        return $this->transition($subject, $actor, function (Model $subject, int $level, User $actor): string {
            if ($level === 1) {
                $subject->validator_1_decided_at = now();
                $subject->validator_1_decided_by_id = (int) $actor->id;

                return $subject->hasSecondValidationLevel()
                    ? ValidationStage::PENDING_VALIDATOR_2
                    : ValidationStage::APPROVED;
            }

            $subject->validator_2_decided_at = now();
            $subject->validator_2_decided_by_id = (int) $actor->id;

            return ValidationStage::APPROVED;
        });
    }

    /**
     * Refuse à l'étape courante : le circuit s'arrête là, quel que soit le
     * niveau. Un refus de niveau 1 n'atteint jamais le Valideur 2.
     */
    public function refuse(Model $subject, User $actor): ValidationTransition
    {
        return $this->transition($subject, $actor, function (Model $subject, int $level, User $actor): string {
            if ($level === 1) {
                $subject->validator_1_decided_at = now();
                $subject->validator_1_decided_by_id = (int) $actor->id;
            } else {
                $subject->validator_2_decided_at = now();
                $subject->validator_2_decided_by_id = (int) $actor->id;
            }

            return ValidationStage::REFUSED;
        });
    }

    /**
     * Enregistre une décision de niveau 1 déjà acquise et fait avancer l'objet.
     *
     * Sert au cas de la contre-proposition acceptée par le demandeur : le
     * valideur qui a proposé la nouvelle période a de fait donné son accord sur
     * celle-ci, mais son accord ne vaut que pour son propre niveau. La demande
     * repart donc vers le niveau suivant au lieu d'être définitivement validée,
     * ce qui fermait auparavant le circuit d'un coup.
     */
    public function completeLevelAfterAgreement(Model $subject, int $level, ?User $decidedBy): string
    {
        if ($level === 1) {
            $subject->validator_1_decided_at = now();
            $subject->validator_1_decided_by_id = $decidedBy?->id;
            $subject->status = $subject->hasSecondValidationLevel()
                ? ValidationStage::PENDING_VALIDATOR_2
                : ValidationStage::APPROVED;

            return $subject->status;
        }

        $subject->validator_2_decided_at = now();
        $subject->validator_2_decided_by_id = $decidedBy?->id;
        $subject->status = ValidationStage::APPROVED;

        return $subject->status;
    }

    /**
     * Applique une transition sous verrou.
     *
     * L'état est relu depuis la base à l'intérieur de la transaction. Si un
     * autre onglet — ou l'autre valideur — a déjà fait avancer l'objet, la
     * transition est abandonnée proprement au lieu d'être rejouée.
     *
     * @param  callable(Model, int, User): string  $decide
     */
    private function transition(Model $subject, User $actor, callable $decide): ValidationTransition
    {
        return DB::transaction(function () use ($subject, $actor, $decide): ValidationTransition {
            /** @var Model|null $locked */
            $locked = $subject->newQuery()
                ->whereKey($subject->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return ValidationTransition::skipped((string) $subject->status);
            }

            $level = $this->authorizedLevelFor($locked, $actor);

            if ($level === null) {
                // L'objet a changé d'état entre l'affichage et le clic. Ce
                // n'est pas une erreur d'autorisation : l'appelant a vérifié
                // les droits avant d'arriver ici.
                $subject->setRawAttributes($locked->getAttributes(), true);

                return ValidationTransition::skipped((string) $locked->status);
            }

            $from = (string) $locked->status;
            $to = $decide($locked, $level, $actor);
            $locked->status = $to;
            $locked->save();

            $subject->setRawAttributes($locked->getAttributes(), true);

            return ValidationTransition::applied(
                $from,
                $to,
                $level,
                ValidationStage::isTerminal($to),
            );
        });
    }

    /**
     * Un compte désactivé ne peut plus se connecter : le désigner comme
     * valideur reviendrait à envoyer la demande dans le vide.
     */
    private function usableValidator(?User $user): ?User
    {
        if (! $user) {
            return null;
        }

        return (bool) $user->is_active ? $user : null;
    }

    private function userLabel(User $user): string
    {
        $fullName = trim(
            collect([$user->first_name, $user->last_name])
                ->filter()
                ->implode(' ')
        );

        return $fullName !== '' ? $fullName : ((string) ($user->name ?: $user->email));
    }

    /**
     * Groupe actuellement applicable à un utilisateur — utile aux écrans qui
     * annoncent le circuit avant toute soumission.
     */
    public function previewFor(User $targetUser): ?ValidationGroup
    {
        return $this->validationGroups->groupFor($targetUser);
    }
}
