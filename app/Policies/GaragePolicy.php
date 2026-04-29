<?php

namespace App\Policies;

use App\Models\Garage;
use App\Models\User;
use App\Support\Access\AccessManager;

class GaragePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Garage $garage): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Garage $garage): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Garage $garage): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        $access = app(AccessManager::class);

        return $access->can($user, 'admin.entities.view') || $access->can($user, 'admin.entities.manage');
    }

    private function canManage(User $user): bool
    {
        return app(AccessManager::class)->can($user, 'admin.entities.manage');
    }
}
