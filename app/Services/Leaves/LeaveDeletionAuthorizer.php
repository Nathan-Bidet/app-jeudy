<?php

namespace App\Services\Leaves;

use App\Models\LeaveAllowedCreatorPair;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Access\AccessManager;
use App\Support\Validation\ValidationStage;

/**
 * Seul point de décision sur la suppression d'une demande de congé ou d'un
 * congé validé.
 *
 * - Administrateur : comportement historique inchangé (toute demande).
 * - Autre utilisateur : il faut À LA FOIS la permission ABILITY (résolue par
 *   l'AccessManager : refus explicite, autorisation explicite, rôle/secteur)
 *   ET que le propriétaire du congé soit un collaborateur qui lui est affecté,
 *   c'est-à-dire un `target_user_id` de LeaveAllowedCreatorPair pour lui
 *   (l'affectation administrée dans « Congés / Heures »). Soi-même n'est pas
 *   une affectation. Seuls les statuts « en attente » et « validé » sont
 *   concernés : un congé refusé n'est pas supprimable par ce biais.
 * Les décisions sont évaluées à chaque appel : jamais d'état périmé après un
 * retrait de permission ou d'affectation.
 */
class LeaveDeletionAuthorizer
{
    public const ABILITY = 'conges_heures.leaves.delete_assigned';

    public function __construct(private readonly AccessManager $accessManager)
    {
    }

    /** Statuts supprimables via la permission (en attente ou validé). */
    public static function deletableStatuses(): array
    {
        return [...LeaveRequest::OPEN_STATUSES, ValidationStage::APPROVED];
    }

    public function hasPermission(User $user): bool
    {
        return $this->accessManager->can($user, self::ABILITY);
    }

    /**
     * @return array<int, int> identifiants des collaborateurs affectés (hors soi-même)
     */
    public function assignedTargetIds(User $user): array
    {
        return LeaveAllowedCreatorPair::query()
            ->where('creator_user_id', (int) $user->id)
            ->where('target_user_id', '!=', (int) $user->id)
            ->pluck('target_user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>|null  $assignedTargetIds  déjà chargés (évite une requête par ligne)
     */
    public function canDelete(User $user, LeaveRequest $leaveRequest, ?array $assignedTargetIds = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! in_array($leaveRequest->status, self::deletableStatuses(), true)) {
            return false;
        }

        if (! $this->hasPermission($user)) {
            return false;
        }

        $assigned = $assignedTargetIds ?? $this->assignedTargetIds($user);

        return in_array((int) $leaveRequest->target_user_id, $assigned, true);
    }
}
