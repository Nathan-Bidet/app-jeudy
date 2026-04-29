<?php

namespace App\Support\Access;

use App\Models\AccessException;
use App\Models\SectorPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccessManager
{
    /**
     * @var array<int, array<string, true>>
     */
    private array $sectorAbilityCache = [];

    /**
     * @var array<int, bool>
     */
    private array $sectorHasDefaultsCache = [];

    public function can(User $user, string $ability, ?int $targetSectorId = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        $effectiveSectorId = $targetSectorId ?? $user->sector_id;

        if (! $effectiveSectorId || ! $user->sector_id) {
            return false;
        }

        $overrides = $this->resolveOverrides($user, $ability, (int) $effectiveSectorId);

        if ($overrides['deny']) {
            return false;
        }

        if ($overrides['allow']) {
            return true;
        }

        if ((int) $user->sector_id !== (int) $effectiveSectorId) {
            return false;
        }

        $hasRolePermission = $user->can($ability);

        if (! $this->sectorHasDefaults((int) $effectiveSectorId)) {
            // Fallback backward-compatible: if no sector defaults are configured,
            // role/permission logic remains unchanged.
            return $hasRolePermission;
        }

        // Sector defaults are additive to role permissions.
        // This allows granting a permission via sector scope (or explicit overrides)
        // even if the base role does not include it.
        return $hasRolePermission || $this->sectorAllowsAbility((int) $effectiveSectorId, $ability);
    }

    public function replaceGlobalOverrides(User $user, array $allowAbilities, array $denyAbilities, ?int $actorId = null): void
    {
        $normalizedAllow = $this->normalizeAbilities($allowAbilities);
        $normalizedDeny = $this->normalizeAbilities($denyAbilities);

        AccessException::query()
            ->where('user_id', $user->id)
            ->whereNull('sector_id')
            ->delete();

        $rows = [];

        foreach ($normalizedAllow as $ability) {
            $rows[] = [
                'user_id' => $user->id,
                'sector_id' => null,
                'ability' => $ability,
                'effect' => 'allow',
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach ($normalizedDeny as $ability) {
            $rows[] = [
                'user_id' => $user->id,
                'sector_id' => null,
                'ability' => $ability,
                'effect' => 'deny',
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            AccessException::query()->insert($rows);
        }
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    public function normalizeAbilities(array $abilities): array
    {
        return array_values(array_unique(array_filter(array_map(function ($ability): ?string {
            $candidate = trim((string) $ability);

            return $candidate === '' ? null : $candidate;
        }, $abilities))));
    }

    /**
     * @return array{allow: bool, deny: bool}
     */
    private function resolveOverrides(User $user, string $ability, int $sectorId): array
    {
        $matched = AccessException::query()
            ->where('user_id', $user->id)
            ->where('ability', $ability)
            ->where(function (Builder $query) use ($sectorId): void {
                $query
                    ->whereNull('sector_id')
                    ->orWhere('sector_id', $sectorId);
            })
            ->get();

        return [
            'allow' => $this->containsEffect($matched, 'allow'),
            'deny' => $this->containsEffect($matched, 'deny'),
        ];
    }

    /**
     * @param  Collection<int, AccessException>  $exceptions
     */
    private function containsEffect(Collection $exceptions, string $effect): bool
    {
        return $exceptions->contains(static fn (AccessException $exception): bool => $exception->effect === $effect);
    }

    private function sectorHasDefaults(int $sectorId): bool
    {
        $this->loadSectorDefaults($sectorId);

        return $this->sectorHasDefaultsCache[$sectorId] ?? false;
    }

    private function sectorAllowsAbility(int $sectorId, string $ability): bool
    {
        $this->loadSectorDefaults($sectorId);

        return isset($this->sectorAbilityCache[$sectorId][$ability]);
    }

    private function loadSectorDefaults(int $sectorId): void
    {
        if (array_key_exists($sectorId, $this->sectorAbilityCache)) {
            return;
        }

        $abilities = SectorPermission::query()
            ->where('sector_id', $sectorId)
            ->pluck('ability')
            ->all();

        $this->sectorAbilityCache[$sectorId] = array_fill_keys($abilities, true);
        $this->sectorHasDefaultsCache[$sectorId] = $abilities !== [];
    }
}
