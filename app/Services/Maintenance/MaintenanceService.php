<?php

namespace App\Services\Maintenance;

use App\Models\MaintenanceTask;
use App\Models\User;
use App\Policies\MaintenanceTaskPolicy;
use App\Services\Visibility\DateRestrictionScope;
use App\Support\Access\AccessManager;
use Illuminate\Database\Eloquent\Builder;

class MaintenanceService
{
    public const MODULE_KEY = 'maintenance';

    public function __construct(private readonly AccessManager $accessManager) {}

    /**
     * Le viewer décide de ce qui sort d'ici : sans la permission
     * maintenance.comment_hidden.view, un commentaire masqué n'est ni renvoyé
     * dans le payload, ni interrogeable via la recherche.
     *
     * @param  array<string, mixed>  $filters
     * @return array{groups: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getGroupedTasks(array $filters, ?User $viewer): array
    {
        $canSeeHiddenComments = $this->canSeeHiddenComments($viewer);
        // Les habilitations du lecteur ne dépendent pas de la tâche : on les
        // résout une fois pour toute la liste. Les interroger par tâche via le
        // Gate coûtait une dizaine de requêtes SQL par ligne, AccessManager
        // n'étant pas partagé entre deux résolutions.
        $abilities = $this->viewerAbilities($viewer);

        $query = MaintenanceTask::query()->with([
            'assigneeUser:id,name,first_name,last_name,sector_id,email,phone,mobile_phone,internal_number',
            'depot:id,name,address_line1,address_line2,postal_code,city,country,gps_lat,gps_lng',
            'createdBy:id,name,first_name,last_name',
            'requestedBy:id,name,first_name,last_name',
            'updatedBy:id,name,first_name,last_name',
            'pointedBy:id,name,first_name,last_name',
            'partiallyPointedBy:id,name,first_name,last_name',
        ]);

        if ($viewer) {
            DateRestrictionScope::apply($query, $viewer, self::MODULE_KEY);
        }

        $this->applyFilters($query, $filters, $canSeeHiddenComments);

        $tasks = $query
            ->orderBy('date')
            ->orderBy('assignee_user_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($tasks as $task) {
            $groupKey = $this->groupKey($task);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'date' => $task->date?->toDateString(),
                    'date_label' => $task->date?->translatedFormat('l d/m/Y') ?? $task->date?->toDateString(),
                    'assignee' => $this->assigneeMeta($task),
                    'tasks' => [],
                ];
            }

            $groups[$groupKey]['tasks'][] = $this->mapTask($task, $canSeeHiddenComments, $viewer, $abilities);
        }

        $sortedGroups = array_values($groups);

        usort($sortedGroups, function (array $left, array $right): int {
            return [$left['date'], $left['assignee']['name']] <=> [$right['date'], $right['assignee']['name']];
        });

        return [
            'groups' => $sortedGroups,
            'meta' => [
                'count_groups' => count($sortedGroups),
                'count_tasks' => array_sum(array_map(static fn (array $g): int => count($g['tasks']), $sortedGroups)),
                'can_view_hidden_comments' => $canSeeHiddenComments,
            ],
        ];
    }

    /**
     * Adresses libres déjà saisies, pour l'autocomplétion du formulaire.
     * Chaque ligne compte séparément, comme dans À Prévoir.
     *
     * @return array<int, string>
     */
    public function placeSuggestions(): array
    {
        return MaintenanceTask::query()
            ->whereNotNull('address_free')
            ->orderByDesc('id')
            ->limit(3000)
            ->pluck('address_free')
            ->flatMap(static function (?string $value): array {
                $lines = preg_split('/\r\n|\r|\n/', (string) $value) ?: [];

                return array_values(array_filter(array_map('trim', $lines)));
            })
            ->unique(static fn (string $line): string => mb_strtolower($line))
            ->take(500)
            ->values()
            ->all();
    }

    /**
     * @return array{create: bool, request: bool, point: bool}
     */
    private function viewerAbilities(?User $viewer): array
    {
        if ($viewer === null) {
            return ['create' => false, 'request' => false, 'point' => false];
        }

        return [
            'create' => $this->accessManager->can($viewer, 'maintenance.create'),
            'request' => $this->accessManager->can($viewer, 'maintenance.request'),
            'point' => $this->accessManager->can($viewer, 'maintenance.point'),
        ];
    }

    public function canSeeHiddenComments(?User $viewer): bool
    {
        return $viewer !== null
            && $this->accessManager->can($viewer, 'maintenance.comment_hidden.view');
    }

    /**
     * Payload public d'une tâche. Seul point de sortie des données vers Inertia
     * ou JSON : la clé `comment` est purement absente lorsque le commentaire est
     * masqué et que le lecteur n'a pas la permission de le voir.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, bool>|null  $abilities  habilitations du lecteur déjà résolues
     */
    public function mapTask(
        MaintenanceTask $task,
        bool $canSeeHiddenComments,
        ?User $viewer = null,
        ?array $abilities = null,
    ): array {
        $abilities ??= $this->viewerAbilities($viewer);
        $canUpdate = $viewer !== null && MaintenanceTaskPolicy::decideUpdate(
            $abilities['create'],
            $abilities['request'],
            $task,
            (int) $viewer->id,
        );
        $commentIsWithheld = $task->comment_hidden && ! $canSeeHiddenComments;

        $payload = [
            'id' => $task->id,
            'origin' => $task->origin,
            'is_request' => $task->isRequest(),
            'date' => $task->date?->toDateString(),
            'date_label' => $task->date?->format('d/m/Y'),
            'fin_date' => $task->fin_date?->toDateString(),
            'fin_label' => $task->fin_date?->format('d/m/Y'),
            'due_date' => $task->due_date?->toDateString(),
            'due_label' => $task->due_date?->format('d/m/Y'),
            'task' => $task->task,
            // Pour qui n'a pas le droit de lire les commentaires masqués, une
            // tâche qui en porte un doit être indiscernable d'une tâche sans
            // commentaire : ni le contenu, ni le fait qu'il existe, ni un
            // drapeau inspectable dans les props ne doivent sortir d'ici.
            'comment_hidden' => $canSeeHiddenComments && (bool) $task->comment_hidden,
            // Retenu, le commentaire vaut null — exactement comme une tâche qui
            // n'en a pas. Omettre la clé rendrait l'absence elle-même parlante.
            'comment' => $commentIsWithheld ? null : $task->comment,
            'assignee' => $this->assigneeMeta($task),
            'depot' => $task->depot ? [
                'id' => $task->depot->id,
                'name' => $task->depot->name,
                'address' => $this->depotAddress($task),
                'gps' => $task->depot->gps_lat !== null && $task->depot->gps_lng !== null
                    ? ['lat' => (float) $task->depot->gps_lat, 'lng' => (float) $task->depot->gps_lng]
                    : null,
            ] : null,
            'address_free' => $task->address_free,
            'place' => $this->placeLabel($task),
            // Vrai quand l'adresse libre complète un dépôt : elle s'affiche
            // alors en texte, sans lien GPS propre.
            'address_free_is_detail' => $task->depot_id !== null && trim((string) $task->address_free) !== '',
            'partially_pointed' => (bool) $task->partially_pointed,
            'partially_pointed_at' => $task->partially_pointed_at?->toIso8601String(),
            'partially_pointed_at_label' => $task->partially_pointed_at?->format('d/m/Y H:i'),
            'partially_pointed_by' => $this->personName($task->partiallyPointedBy),
            'pointed' => (bool) $task->pointed,
            'pointed_at' => $task->pointed_at?->toIso8601String(),
            'pointed_at_label' => $task->pointed_at?->format('d/m/Y H:i'),
            'pointed_by' => $this->personName($task->pointedBy),
            'first_pointed_on' => $task->first_pointed_on?->toDateString(),
            'first_pointed_on_label' => $task->first_pointed_on?->format('d/m/Y'),
            'first_pointed_on_manual' => (bool) $task->first_pointed_on_manual,
            'position' => (int) $task->position,
            'created_by' => $this->personName($task->createdBy),
            'requested_by' => $this->personName($task->requestedBy),
            'updated_by' => $this->personName($task->updatedBy),
            // Droits calculés par la Policy, tâche par tâche : le frontend
            // n'a jamais à rejouer la règle métier.
            // delete suit exactement update, comme dans la Policy.
            'can_update' => $canUpdate,
            'can_delete' => $canUpdate,
            // Pointage partiel : règle d'identité pure, évaluée hors du Gate
            // pour rester vraie même pour un administrateur.
            'can_partial_point' => $task->isPartialPointableBy($viewer),
            'can_point' => $abilities['point'],
            'can_edit_pointing_date' => $abilities['point'],
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, bool $canSeeHiddenComments): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (! empty($filters['assignee_user_id'])) {
            $query->where('assignee_user_id', (int) $filters['assignee_user_id']);
        }

        if (! empty($filters['depot_id'])) {
            $query->where('depot_id', (int) $filters['depot_id']);
        }

        if (! empty($filters['origin'])) {
            $query->where('origin', (string) $filters['origin']);
        }

        $pointedFilter = $filters['pointed_filter'] ?? 'all';

        if ($pointedFilter === 'pointed') {
            $query->where('pointed', true);
        } elseif ($pointedFilter === 'unpointed') {
            $query->where('pointed', false);
        } elseif ($pointedFilter === 'partial') {
            $query->where('pointed', false)->where('partially_pointed', true);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function (Builder $sub) use ($like, $canSeeHiddenComments): void {
            $sub->where('task', 'like', $like)
                ->orWhere('assignee_label_free', 'like', $like)
                ->orWhere('address_free', 'like', $like)
                ->orWhereHas('depot', fn (Builder $depot) => $depot->where('name', 'like', $like))
                ->orWhereHas('assigneeUser', function (Builder $user) use ($like): void {
                    $user->where('name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });

            // Un commentaire masqué ne doit pas être devinable par recherche.
            if ($canSeeHiddenComments) {
                $sub->orWhere('comment', 'like', $like);
            } else {
                $sub->orWhere(function (Builder $visible) use ($like): void {
                    $visible->where('comment_hidden', false)
                        ->where('comment', 'like', $like);
                });
            }
        });
    }

    private function groupKey(MaintenanceTask $task): string
    {
        $date = $task->date?->toDateString() ?? '0000-00-00';

        return match ($task->assigneeType()) {
            'user' => $date.'|user:'.$task->assignee_user_id,
            'free' => $date.'|free:'.mb_strtolower(trim((string) $task->assignee_label_free)),
            default => $date.'|none',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function assigneeMeta(MaintenanceTask $task): array
    {
        $type = $task->assigneeType();

        if ($type === 'free') {
            return [
                'id' => null,
                'type' => 'free',
                'name' => trim((string) $task->assignee_label_free),
                'phone' => null,
                'email' => null,
            ];
        }

        if ($type === 'none') {
            return [
                'id' => null,
                'type' => 'none',
                'name' => 'Non affectée',
                'phone' => null,
                'email' => null,
            ];
        }

        $user = $task->assigneeUser;

        return [
            'id' => (int) $task->assignee_user_id,
            'type' => 'user',
            'name' => $this->personName($user) ?? ('Utilisateur #'.$task->assignee_user_id),
            'phone' => $user?->mobile_phone ?: $user?->phone,
            'email' => $user?->email,
        ];
    }

    private function depotAddress(MaintenanceTask $task): ?string
    {
        $depot = $task->depot;

        if (! $depot) {
            return null;
        }

        $parts = array_filter([
            $depot->address_line1,
            $depot->address_line2,
            trim(implode(' ', array_filter([$depot->postal_code, $depot->city]))),
            $depot->country,
        ]);

        $address = trim(implode(', ', $parts));

        return $address !== '' ? $address : null;
    }

    /**
     * Destination unique de l'ouverture GPS.
     *
     * Un dépôt lié fournit une adresse géolocalisée : c'est elle la
     * destination, et l'adresse libre n'est qu'une précision de site
     * (« Bâtiment B ») affichée à côté. Sans dépôt, l'adresse libre devient
     * elle-même la destination.
     *
     * Renvoyer les deux lignes ensemble ferait ouvrir les coordonnées du dépôt
     * en cliquant sur la précision de site, ce qui est trompeur.
     */
    private function placeLabel(MaintenanceTask $task): ?string
    {
        $place = trim((string) ($this->depotAddress($task) ?? $task->depot?->name ?? $task->address_free));

        return $place !== '' ? $place : null;
    }

    private function personName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $full = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

        if ($full !== '') {
            return $full;
        }

        $name = trim((string) $user->name);

        return $name !== '' ? $name : null;
    }
}
