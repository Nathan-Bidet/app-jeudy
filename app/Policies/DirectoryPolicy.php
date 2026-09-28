<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFile;
use App\Support\Access\AccessManager;

/**
 * Droits de l'annuaire. Deux permissions indépendantes, résolues par
 * l'AccessManager (rôle, défauts de secteur, exceptions utilisateur) :
 *  - directory.files.create : ajouter une pièce jointe à une fiche ;
 *  - directory.update       : éditer la fiche d'un autre utilisateur, ainsi
 *    que les champs d'organisation, de validité et de liens de toute fiche.
 * Aucune des deux n'implique l'autre. Les administrateurs passent par le
 * Gate::before d'AppServiceProvider.
 */
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
        return app(AccessManager::class)->can($authUser, 'directory.files.create');
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
        // Chacun conserve l'édition (champs limités) de sa propre fiche.
        if ((int) $authUser->id === (int) $targetUser->id) {
            return true;
        }

        return app(AccessManager::class)->can($authUser, 'directory.update');
    }

    /**
     * Champs « Organisation », « Dates de validité » et « Liens & infos
     * diverses ». Réservés à directory.update : éditer sa propre fiche sans
     * cette permission ne donne accès qu'aux coordonnées et à la photo.
     */
    public function updateManagedFields(User $authUser, User $targetUser): bool
    {
        return app(AccessManager::class)->can($authUser, 'directory.update');
    }
}
