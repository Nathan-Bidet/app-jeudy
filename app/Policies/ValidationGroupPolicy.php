<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ValidationGroup;

/**
 * Les groupes de validation vivent dans ADMIN - CONGÉS, page réservée aux
 * administrateurs. La Policy reprend cette règle telle quelle plutôt que
 * d'introduire une permission parallèle.
 *
 * Le Gate::before d'AppServiceProvider laisse déjà passer les administrateurs ;
 * ces méthodes servent donc surtout à refuser explicitement les autres.
 */
class ValidationGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, ValidationGroup $group): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, ValidationGroup $group): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, ValidationGroup $group): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return (bool) $user->hasRole('admin');
    }
}
