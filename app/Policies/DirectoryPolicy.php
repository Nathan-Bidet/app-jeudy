<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFile;

class DirectoryPolicy
{
    public function viewAny(User $authUser): bool
    {
        return true;
    }

    public function view(User $authUser, User $targetUser): bool
    {
        return true;
    }

    public function attachFile(User $authUser, User $targetUser): bool
    {
        return $authUser->hasRole('admin');
    }

    public function deleteFile(User $authUser, UserFile $userFile): bool
    {
        return $authUser->hasRole('admin');
    }

    public function renameFile(User $authUser, UserFile $userFile): bool
    {
        if ($authUser->hasRole('admin')) {
            return true;
        }

        return (int) $authUser->id === (int) $userFile->user_id;
    }

    public function update(User $authUser, User $targetUser): bool
    {
        if ($authUser->hasRole('admin')) {
            return true;
        }

        return (int) $authUser->id === (int) $targetUser->id;
    }
}
