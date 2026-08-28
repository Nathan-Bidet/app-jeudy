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
     * Créer directement donne la main sur toutes les tâches.
     * Ne disposer que du droit de demander ne permet de reprendre que ses
     * propres tâches, et seulement tant qu'elles ne sont pas pointées
     * définitivement.
     */
    public function update(User $user, MaintenanceTask $task): bool
    {
        if ($this->create($user)) {
            return true;
        }

        if (! $this->requestTask($user)) {
            return false;
        }

        if ($task->pointed) {
            return false;
        }

        return (int) $task->created_by_user_id === (int) $user->id;
    }

    public function delete(User $user, MaintenanceTask $task): bool
    {
        return $this->update($user, $task);
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
     * Pointage partiel : ouvert à tout utilisateur du module, tant que la tâche
     * n'a pas été verrouillée par un pointage définitif.
     */
    public function partialPoint(User $user, MaintenanceTask $task): bool
    {
        if ($task->pointed) {
            return false;
        }

        return $this->can($user, 'maintenance.view');
    }

    private function can(User $user, string $ability): bool
    {
        return app(AccessManager::class)->can($user, $ability);
    }
}
