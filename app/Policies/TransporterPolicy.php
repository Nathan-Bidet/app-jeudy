<?php

namespace App\Policies;

use App\Models\Transporter;
use App\Models\User;
use App\Support\Access\AccessManager;

class TransporterPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Transporter $transporter): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Transporter $transporter): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Transporter $transporter): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        $access = app(AccessManager::class);

        return $access->can($user, 'task.data.transporters.view')
            || $access->can($user, 'task.data.transporters.manage');
    }

    private function canManage(User $user): bool
    {
        return app(AccessManager::class)->can($user, 'task.data.transporters.manage');
    }
}

