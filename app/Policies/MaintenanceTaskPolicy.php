<?php

namespace App\Policies;

use App\Models\MaintenanceTask;
use App\Models\User;
use App\Support\Access\AccessManager;

/**
 * Toutes les règles délèguent à AccessManager : la Policy n'introduit aucun
 * système d'autorisation parallèle, elle centralise seulement les règles
 * métier qui combinent plusieurs permissions ou dépendent de la tâche.
 *
 * Les administrateurs passent en amont par le Gate::before d'AppServiceProvider.
 */
class MaintenanceTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'maintenance.view');
    }

    public function view(User $user, MaintenanceTask $task): bool
    {
        return $this->can($user, 'maintenance.view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'maintenance.create');
    }

    public function requestTask(User $user): bool
    {
        return $this->can($user, 'maintenance.request');
    }

    /**
     * Peut soumettre une tâche, quelle qu'en soit l'origine.
     */
    public function submit(User $user): bool
    {
        return $this->create($user) || $this->requestTask($user);
    }

    /**
     * Modification des informations d'une tâche.
     *
     * Une tâche réellement créée n'est plus modifiable : le contenu est figé
     * une fois la tâche entrée dans le circuit. Seule une demande encore en
     * attente reste amendable, et par son seul demandeur.
     *
     * Les actions de pointage ne passent pas par ici : elles ont leurs propres
     * règles (point, partialPoint, updatePointingDate) et restent ouvertes.
     */
    public function update(User $user, MaintenanceTask $task): bool
    {
        return self::decideUpdate($task, (int) $user->id);
    }

    /**
     * Règles isolées des lectures de permissions, pour qu'une liste résolve les
     * habilitations une seule fois puis tranche tâche par tâche sans repasser
     * en base. Unique expression des règles : les méthodes de Policy ci-dessus
     * s'en servent aussi.
     */
    public static function decideUpdate(MaintenanceTask $task, int $userId): bool
    {
        return $task->isPendingRequest()
            && (int) $task->requested_by_user_id === $userId;
    }

    /**
     * Suppression : réservée aux demandes encore en attente. Leur demandeur
     * peut retirer la sienne, et qui sait créer les tâches peut écarter une
     * demande qu'il ne traitera pas. Une tâche réelle ne se supprime plus.
     */
    public function delete(User $user, MaintenanceTask $task): bool
    {
        return self::decideDelete($this->create($user), $task, (int) $user->id);
    }

    public static function decideDelete(bool $canCreate, MaintenanceTask $task, int $userId): bool
    {
        if (! $task->isPendingRequest()) {
            return false;
        }

        return $canCreate || (int) $task->requested_by_user_id === $userId;
    }

    public function viewHiddenComment(User $user): bool
    {
        return $this->can($user, 'maintenance.comment_hidden.view');
    }

    /**
     * Pointage définitif : permission dédiée.
     */
    public function point(User $user, MaintenanceTask $task): bool
    {
        return $this->can($user, 'maintenance.point');
    }

    /**
     * Transformer une demande en tâche réelle exige le droit de créer, pas
     * seulement celui de demander : un demandeur ne valide pas sa propre
     * demande. L'action n'a de sens que sur une demande encore en attente.
     */
    public function convert(User $user, MaintenanceTask $task): bool
    {
        return $task->isPendingRequest() && $this->create($user);
    }

    /**
     * Modification manuelle de la date métier du premier pointage : réservée
     * aux détenteurs du pointage définitif.
     */
    public function updatePointingDate(User $user, MaintenanceTask $task): bool
    {
        return $this->can($user, 'maintenance.point');
    }

    /**
     * Pointage partiel : réservé à la personne affectée.
     *
     * Attention — cette règle est délibérément hors du Gate dans le contrôleur
     * (voir MaintenanceTask::isPartialPointableBy) : le Gate::before accorde
     * tout aux administrateurs, ce qui contredirait la règle d'identité. La
     * méthode reste ici pour rester conforme à l'usage du projet, mais elle
     * n'est pas la barrière effective.
     */
    public function partialPoint(User $user, MaintenanceTask $task): bool
    {
        return $task->isPartialPointableBy($user);
    }

    private function can(User $user, string $ability): bool
    {
        return app(AccessManager::class)->can($user, $ability);
    }
}
