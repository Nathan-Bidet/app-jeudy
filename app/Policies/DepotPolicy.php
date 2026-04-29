<?php

namespace App\Policies;

use App\Models\Depot;
use App\Models\User;
use App\Support\Access\AccessManager;

class DepotPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Depot $depot): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Depot $depot): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Depot $depot): bool
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
