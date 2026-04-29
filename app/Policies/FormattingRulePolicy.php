<?php

namespace App\Policies;

use App\Models\FormattingRule;
use App\Models\User;
use App\Support\Access\AccessManager;

class FormattingRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, FormattingRule $formattingRule): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, FormattingRule $formattingRule): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, FormattingRule $formattingRule): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        return app(AccessManager::class)->can($user, 'task.formatting.view');
    }

    private function canManage(User $user): bool
    {
        return app(AccessManager::class)->can($user, 'task.formatting.manage');
    }
}
