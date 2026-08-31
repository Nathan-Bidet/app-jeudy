<?php

namespace App\Services\Maintenance;

use App\Models\User;
use App\Support\Access\AccessManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Détermine les destinataires d'une notification à partir des permissions,
 * jamais d'une liste d'utilisateurs codée en dur.
 *
 * AccessManager superpose quatre sources (rôle Spatie, permission par défaut du
 * secteur, exception individuelle allow/deny, rôle admin). On présélectionne
 * donc largement en SQL, puis on tranche utilisateur par utilisateur avec
 * AccessManager, seul à connaître les refus explicites.
 */
class MaintenanceRecipientResolver
{
    public function __construct(private readonly AccessManager $accessManager) {}

    /**
     * Utilisateurs actifs disposant réellement de l'habilitation donnée.
     *
     * @return Collection<int, User>
     */
    public function usersWithAbility(string $ability, array $excludeUserIds = []): Collection
    {
        $candidates = User::query()
            ->where('is_active', true)
            ->when($excludeUserIds !== [], fn (Builder $query) => $query->whereNotIn('id', $excludeUserIds))
            ->where(function (Builder $query) use ($ability): void {
                $query
                    // Permission portée par un rôle de l'utilisateur.
                    ->whereHas('roles.permissions', fn (Builder $p) => $p->where('name', $ability))
                    // Permission accordée directement à l'utilisateur.
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $ability))
                    // Rôle administrateur : accès global via Gate::before.
                    ->orWhereHas('roles', fn (Builder $r) => $r->where('name', 'admin'))
                    // Permission par défaut du secteur.
                    ->orWhereIn('sector_id', function ($sub) use ($ability): void {
                        $sub->select('sector_id')
                            ->from('sector_permissions')
                            ->where('ability', $ability);
                    })
                    // Exception individuelle accordant l'habilitation.
                    ->orWhereExists(function ($sub) use ($ability): void {
                        $sub->select('id')
                            ->from('access_exceptions')
                            ->whereColumn('access_exceptions.user_id', 'users.id')
                            ->where('ability', $ability)
                            ->where('effect', 'allow');
                    });
            })
            ->get();

        // Second passage : AccessManager applique les refus explicites et la
        // règle de secteur, que le SQL ci-dessus ne sait pas exprimer.
        return $candidates
            ->filter(fn (User $user): bool => $this->accessManager->can($user, $ability))
            ->values();
    }
}
