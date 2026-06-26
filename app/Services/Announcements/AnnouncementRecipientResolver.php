<?php

namespace App\Services\Announcements;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Résout dynamiquement la liste des utilisateurs destinataires d'une
 * annonce à partir de secteurs/utilisateurs sélectionnés et d'exclusions.
 * Utilisé à l'envoi (immédiat ou différé) : la résolution se fait toujours
 * sur la composition des secteurs au moment de l'envoi, pas au moment de
 * la création de l'annonce. Les exclusions sont toujours prioritaires.
 */
class AnnouncementRecipientResolver
{
    /**
     * @param  array<int, int>|null  $sectorIds
     * @param  array<int, int>|null  $userIds
     * @param  array<int, int>|null  $excludedUserIds
     * @return Collection<int, User>
     */
    public function resolve(?array $sectorIds, ?array $userIds, ?array $excludedUserIds): Collection
    {
        $sectorIds = $this->normalizeIds($sectorIds);
        $userIds = $this->normalizeIds($userIds);
        $excludedUserIds = $this->normalizeIds($excludedUserIds);

        if ($sectorIds === [] && $userIds === []) {
            return new Collection();
        }

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($sectorIds, $userIds): void {
                if ($sectorIds !== []) {
                    $query->orWhereIn('sector_id', $sectorIds);
                }

                if ($userIds !== []) {
                    $query->orWhereIn('id', $userIds);
                }
            })
            ->when($excludedUserIds !== [], fn ($query) => $query->whereNotIn('id', $excludedUserIds))
            ->get();
    }

    /**
     * @param  array<int, mixed>|null  $ids
     * @return array<int, int>
     */
    private function normalizeIds(?array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids ?? []))));
    }
}
