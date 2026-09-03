<?php

namespace App\Services\Validation;

use App\Models\User;
use App\Models\ValidationGroup;
use App\Support\Validation\ValidationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de double validation, partagé par les Congés et les Heures.
 *
 * Il ne connaît ni congé ni feuille d'heures : il travaille sur n'importe quel
 * modèle utilisant HasTwoStepValidation.
 *
 * Les deux valideurs sont INDÉPENDANTS. Dès la création, l'un et l'autre
 * peuvent valider ou refuser, dans n'importe quel ordre. L'objet n'est
 * définitivement validé que lorsque tous les accords attendus sont réunis ;
 * un seul refus suffit en revanche à clore le circuit.
 *
 * Trois garanties sont tenues ici, et pas dans les contrôleurs :
 *
 *   1. LE PÉRIMÈTRE. Un valideur n'agit que sur les objets où il est désigné,
 *      au rang qui est le sien. Être valideur d'un groupe ne donne aucun droit
 *      sur les membres d'un autre groupe.
 *   2. L'UNICITÉ DE LA DÉCISION. Un valideur qui a déjà tranché ne peut plus
 *      rien sur cet objet : son rang porte déjà une décision.
 *   3. LA CONCURRENCE. La ligne est verrouillée et relue dans la transaction :
 *      deux clics simultanés ne produisent qu'une décision, et deux valideurs
 *      qui agissent en même temps ne s'écrasent pas.
 */
class TwoStepValidationService
{
    public function __construct(private readonly ValidationGroupService $validationGroups) {}

    /**
     * Fige les valideurs sur l'objet et le place en attente.
     *
     * Le premier valideur peut venir d'ailleurs que du groupe : les Congés
     * conservent leur cascade historique (valideur par utilisateur, puis par
     * secteur, puis administrateur) pour les utilisateurs qui n'ont pas encore
     * rejoint un groupe. C'est le rôle de $fallbackValidatorResolver, appelé
     * seulement si le groupe ne fournit pas de premier valideur.
     *
     * Sans second valideur, un seul accord suffit — le comportement d'avant
     * l'introduction de la double validation, et non un blocage silencieux.
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

        // Un groupe dont seul le second valideur est exploitable ne doit pas
        // laisser le rang 1 vacant : le second prend alors le premier rang.
        if (! $validator1 && $validator2) {
            $validator1 = $validator2;
            $validator2 = null;
        }

        // Même personne aux deux rangs : elle validerait deux fois la même
        // demande. L'administration l'interdit déjà (règle `different` sur le
        // groupe) ; ce garde-fou couvre les configurations héritées et les
        // replis ci-dessus.
        if ($validator1 && $validator2 && (int) $validator1->id === (int) $validator2->id) {
            $validator2 = null;
        }

        $subject->validation_group_id = $group?->id;
        $subject->validation_group_name = $group?->name;
        $subject->validator_1_id = $validator1?->id;
        $subject->validator_1_label = $validator1 ? $this->userLabel($validator1) : null;
        $subject->validator_2_id = $validator2?->id;
        $subject->validator_2_label = $validator2 ? $this->userLabel($validator2) : null;
        $subject->validator_1_decision = null;
        $subject->validator_1_decided_at = null;
        $subject->validator_1_decided_by_id = null;
        $subject->validator_2_decision = null;
        $subject->validator_2_decided_at = null;
        $subject->validator_2_decided_by_id = null;
        $subject->status = ValidationStage::PENDING;
    }

    /**
     * Rangs sur lesquels cet utilisateur peut encore agir, maintenant.
     *
     * Vide si l'objet est clos, si l'utilisateur n'y est pas désigné, ou s'il
     * a déjà tranché. Aucun rang n'est conditionné à la décision de l'autre.
     *
     * @return array<int, int>
     */
    public function decidableLevelsFor(Model $subject, ?User $actor): array
    {
        if (! $actor || ! ValidationStage::isOpen($subject->status)) {
            return [];
        }

        $ownLevels = $subject->undecidedLevelsFor($actor);

        if ($ownLevels !== []) {
            return $ownLevels;
        }

        // Les administrateurs gardent la main qu'ils avaient déjà sur les
        // demandes. N'étant désignés à aucun rang, leur décision porte sur tous
        // les rangs encore ouverts : elle tranche la demande, comme avant
        // l'introduction de la double validation. Un administrateur qui EST
        // par ailleurs valideur passe, lui, par la branche ci-dessus et ne
        // décide que pour son propre rang.
        if ($actor->hasRole('admin')) {
            return $subject->undecidedLevels();
        }

        return [];
    }

    public function canDecide(Model $subject, ?User $actor): bool
    {
        return $this->decidableLevelsFor($subject, $actor) !== [];
    }

    /**
     * Enregistre l'accord de cet utilisateur.
     *
     * L'objet ne devient définitivement validé que si l'accord manquant était
     * le dernier ; sinon il reste en attente de l'autre valideur.
     */
    public function approve(Model $subject, User $actor): ValidationTransition
    {
        return $this->decide($subject, $actor, ValidationStage::DECISION_APPROVED);
    }

    /**
     * Enregistre le refus de cet utilisateur : le circuit s'arrête là, sans
     * attendre l'autre valideur.
     */
    public function refuse(Model $subject, User $actor): ValidationTransition
    {
        return $this->decide($subject, $actor, ValidationStage::DECISION_REFUSED);
    }

    /**
     * Enregistre un accord déjà acquis sur un rang donné, sans passer par les
     * contrôles d'autorisation.
     *
     * Sert au cas de la contre-proposition acceptée par le demandeur : le
     * valideur qui a proposé la nouvelle période a de fait donné son accord
     * sur celle-ci. Son accord ne vaut que pour SON rang — l'autre valideur
     * doit toujours se prononcer.
     */
    public function recordAgreement(Model $subject, int $level, ?User $decidedBy): string
    {
        $this->writeDecision($subject, $level, ValidationStage::DECISION_APPROVED, $decidedBy?->id);
        $subject->status = $subject->resolveGlobalStatus();

        return $subject->status;
    }

    /**
     * Applique une décision sous verrou.
     *
     * L'état est relu depuis la base à l'intérieur de la transaction. Si le
     * rang a déjà été tranché — second onglet, double-clic — ou si l'autre
     * valideur a entre-temps refusé, la décision est abandonnée proprement au
     * lieu d'être rejouée.
     */
    private function decide(Model $subject, User $actor, string $decision): ValidationTransition
    {
        return DB::transaction(function () use ($subject, $actor, $decision): ValidationTransition {
            /** @var Model|null $locked */
            $locked = $subject->newQuery()
                ->whereKey($subject->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return ValidationTransition::skipped((string) $subject->status);
            }

            $levels = $this->decidableLevelsFor($locked, $actor);

            if ($levels === []) {
                // Rien à faire : l'objet a bougé entre l'affichage et le clic.
                // Ce n'est pas une erreur d'autorisation — l'appelant a vérifié
                // les droits avant d'arriver ici.
                $subject->setRawAttributes($locked->getAttributes(), true);

                return ValidationTransition::skipped((string) $locked->status);
            }

            $from = (string) $locked->status;

            foreach ($levels as $level) {
                $this->writeDecision($locked, $level, $decision, (int) $actor->id);
            }

            $to = $locked->resolveGlobalStatus();
            $locked->status = $to;
            $locked->save();

            $subject->setRawAttributes($locked->getAttributes(), true);

            return ValidationTransition::applied(
                $from,
                $to,
                $levels,
                $decision,
                ValidationStage::isTerminal($to),
            );
        });
    }

    private function writeDecision(Model $subject, int $level, string $decision, ?int $decidedByUserId): void
    {
        if ($level === 1) {
            $subject->validator_1_decision = $decision;
            $subject->validator_1_decided_at = now();
            $subject->validator_1_decided_by_id = $decidedByUserId;

            return;
        }

        $subject->validator_2_decision = $decision;
        $subject->validator_2_decided_at = now();
        $subject->validator_2_decided_by_id = $decidedByUserId;
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
