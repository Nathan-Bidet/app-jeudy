<?php

namespace App\Http\Controllers;

use App\Http\Requests\Maintenance\MaintenanceTaskRequest;
use App\Models\Depot;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Maintenance\MaintenanceService;
use App\Support\Access\AccessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    private const LOG_MODULE = 'maintenance';

    public function __construct(
        private readonly MaintenanceService $service,
        private readonly AuditLogService $auditLogService,
        private readonly AccessManager $accessManager,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MaintenanceTask::class);

        $filters = $this->validateFilters($request);
        $result = $this->service->getGroupedTasks($filters, $request->user());

        return Inertia::render('Maintenance/Index', [
            'groups' => $result['groups'],
            'meta' => $result['meta'],
            'filters' => $filters,
            'reference' => $this->referenceData(),
            'permissions' => $this->permissionFlags($request->user()),
        ]);
    }

    public function tasksData(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaintenanceTask::class);

        $filters = $this->validateFilters($request);
        $result = $this->service->getGroupedTasks($filters, $request->user());

        return response()->json([
            'groups' => $result['groups'],
            'meta' => $result['meta'],
        ]);
    }

    public function store(MaintenanceTaskRequest $request): RedirectResponse
    {
        $this->authorize('submit', MaintenanceTask::class);

        $actor = $request->user();
        $origin = $this->resolveOrigin($request, $actor);

        $task = new MaintenanceTask;
        $task->fill($request->taskAttributes());
        $task->origin = $origin;
        $task->created_by_user_id = $actor->id;
        $task->requested_by_user_id = $origin === MaintenanceTask::ORIGIN_REQUEST ? $actor->id : null;
        $task->updated_by_user_id = $actor->id;
        $task->save();

        $this->auditLogService->log([
            'action' => $origin === MaintenanceTask::ORIGIN_REQUEST
                ? 'request_maintenance_task'
                : 'create_maintenance_task',
            'module' => self::LOG_MODULE,
            'description' => sprintf(
                '%s tâche Maintenance #%d',
                $origin === MaintenanceTask::ORIGIN_REQUEST ? 'Demande' : 'Création',
                (int) $task->id
            ),
            'payload' => [
                'task_id' => $task->id,
                'after' => $this->auditSnapshot($task),
            ],
        ]);

        return back()->with('status', 'Tâche Maintenance enregistrée.');
    }

    public function update(MaintenanceTaskRequest $request, MaintenanceTask $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $before = $this->auditSnapshot($task);
        $actor = $request->user();

        $task->fill($request->taskAttributes());
        $task->updated_by_user_id = $actor->id;
        $task->save();

        $this->auditLogService->log([
            'action' => 'update_maintenance_task',
            'module' => self::LOG_MODULE,
            'description' => sprintf('Mise à jour tâche Maintenance #%d', (int) $task->id),
            'payload' => [
                'task_id' => $task->id,
                'before' => $before,
                'after' => $this->auditSnapshot($task),
            ],
        ]);

        return back()->with('status', 'Tâche Maintenance mise à jour.');
    }

    public function destroy(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $before = $this->auditSnapshot($task);
        $taskId = $task->id;
        $task->delete();

        $this->auditLogService->log([
            'action' => 'delete_maintenance_task',
            'module' => self::LOG_MODULE,
            'description' => sprintf('Suppression tâche Maintenance #%d', (int) $taskId),
            'payload' => [
                'task_id' => $taskId,
                'before' => $before,
            ],
        ]);

        return back()->with('status', 'Tâche Maintenance supprimée.');
    }

    public function point(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $this->authorize('point', $task);

        $validated = $request->validate([
            'pointed' => ['required', 'boolean'],
        ]);

        $pointed = (bool) $validated['pointed'];
        $before = $this->auditSnapshot($task);

        $task->forceFill([
            'pointed' => $pointed,
            'pointed_at' => $pointed ? now() : null,
            'pointed_by_user_id' => $pointed ? $request->user()?->id : null,
            'partially_pointed' => false,
            'partially_pointed_at' => null,
            'partially_pointed_by_user_id' => null,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        if ($pointed) {
            $this->stampFirstPointing($task);
        }

        $task->save();

        $this->auditLogService->log([
            'action' => 'point_maintenance_task',
            'module' => self::LOG_MODULE,
            'description' => sprintf(
                'Pointage définitif tâche Maintenance #%d (%s)',
                (int) $task->id,
                $pointed ? 'pointé' : 'dépointé'
            ),
            'payload' => [
                'task_id' => $task->id,
                'pointed' => $pointed,
                'before' => $before,
                'after' => $this->auditSnapshot($task),
            ],
        ]);

        return back()->with('status', 'Pointage définitif mis à jour.');
    }

    public function partialPoint(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $this->authorize('partialPoint', $task);

        $validated = $request->validate([
            'partially_pointed' => ['required', 'boolean'],
        ]);

        $partiallyPointed = (bool) $validated['partially_pointed'];
        $before = $this->auditSnapshot($task);

        $task->forceFill([
            'partially_pointed' => $partiallyPointed,
            'partially_pointed_at' => $partiallyPointed ? now() : null,
            'partially_pointed_by_user_id' => $partiallyPointed ? $request->user()?->id : null,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        if ($partiallyPointed) {
            $this->stampFirstPointing($task);
        }

        $task->save();

        $this->auditLogService->log([
            'action' => 'partial_point_maintenance_task',
            'module' => self::LOG_MODULE,
            'description' => sprintf(
                'Pointage partiel tâche Maintenance #%d (%s)',
                (int) $task->id,
                $partiallyPointed ? 'partiel' : 'annulé'
            ),
            'payload' => [
                'task_id' => $task->id,
                'partially_pointed' => $partiallyPointed,
                'before' => $before,
                'after' => $this->auditSnapshot($task),
            ],
        ]);

        return back()->with('status', 'Pointage partiel mis à jour.');
    }

    /**
     * La date métier du premier pointage n'est écrite qu'une fois et n'est
     * jamais réécrite, y compris si la tâche est dépointée puis repointée.
     */
    private function stampFirstPointing(MaintenanceTask $task): void
    {
        if ($task->first_pointed_on === null) {
            $task->first_pointed_on = now()->toDateString();
        }
    }

    private function resolveOrigin(MaintenanceTaskRequest $request, User $actor): string
    {
        $requested = $request->requestedOrigin();

        if ($requested === MaintenanceTask::ORIGIN_CREATION) {
            $this->authorize('create', MaintenanceTask::class);

            return MaintenanceTask::ORIGIN_CREATION;
        }

        if ($requested === MaintenanceTask::ORIGIN_REQUEST) {
            $this->authorize('requestTask', MaintenanceTask::class);

            return MaintenanceTask::ORIGIN_REQUEST;
        }

        // Origine non précisée : déduite des droits de l'auteur, la création
        // directe primant sur la demande.
        return $actor->can('create', MaintenanceTask::class)
            ? MaintenanceTask::ORIGIN_CREATION
            : MaintenanceTask::ORIGIN_REQUEST;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'assignee_user_id' => ['nullable', 'integer'],
            'depot_id' => ['nullable', 'integer'],
            'origin' => ['nullable', Rule::in([MaintenanceTask::ORIGIN_CREATION, MaintenanceTask::ORIGIN_REQUEST])],
            'pointed_filter' => ['nullable', Rule::in(['all', 'pointed', 'unpointed', 'partial'])],
        ]);

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'search' => $validated['search'] ?? '',
            'assignee_user_id' => $validated['assignee_user_id'] ?? null,
            'depot_id' => $validated['depot_id'] ?? null,
            'origin' => $validated['origin'] ?? null,
            'pointed_filter' => $validated['pointed_filter'] ?? 'unpointed',
        ];
    }

    /**
     * Utilisateurs affectables (annuaire) et dépôts, au format déjà utilisé par
     * les autres modules de tâches.
     *
     * @return array<string, mixed>
     */
    private function referenceData(): array
    {
        $users = User::query()
            ->with('sector:id,name')
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'last_name', 'sector_id'])
            ->map(function (User $user): array {
                $full = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

                return [
                    'id' => $user->id,
                    'name' => $full !== '' ? $full : $user->name,
                    'sector_name' => $user->sector?->name,
                ];
            })
            ->values()
            ->all();

        $depotRecords = Depot::query()
            ->orderBy('name')
            ->get(['id', 'name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country']);

        $depots = $depotRecords
            ->map(fn (Depot $depot): array => ['id' => $depot->id, 'name' => $depot->name])
            ->values()
            ->all();

        $depotPlaceMap = $depotRecords->reduce(function (array $carry, Depot $depot): array {
            $name = trim((string) $depot->name);

            if ($name === '') {
                return $carry;
            }

            $address = trim(implode(', ', array_filter([
                $depot->address_line1,
                $depot->address_line2,
                trim(implode(' ', array_filter([$depot->postal_code, $depot->city]))),
                $depot->country,
            ])));

            if ($address !== '') {
                $carry[$name] = $address;
            }

            return $carry;
        }, []);

        return [
            'assignee_users' => $users,
            'depots' => $depots,
            'depot_place_map' => $depotPlaceMap,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissionFlags(?User $user): array
    {
        if (! $user) {
            return [
                'can_create' => false,
                'can_request' => false,
                'can_point' => false,
                'can_view_hidden_comments' => false,
            ];
        }

        return [
            'can_create' => $this->accessManager->can($user, 'maintenance.create'),
            'can_request' => $this->accessManager->can($user, 'maintenance.request'),
            'can_point' => $this->accessManager->can($user, 'maintenance.point'),
            'can_view_hidden_comments' => $this->accessManager->can($user, 'maintenance.comment_hidden.view'),
        ];
    }

    /**
     * Instantané pour les logs. Le commentaire masqué n'y figure jamais en
     * clair : les logs sont consultables par un autre profil de permissions.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(MaintenanceTask $task): array
    {
        return [
            'origin' => $task->origin,
            'date' => $task->date?->toDateString(),
            'fin_date' => $task->fin_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'assignee_user_id' => $task->assignee_user_id,
            'assignee_label_free' => $task->assignee_label_free,
            'depot_id' => $task->depot_id,
            'address_free' => $task->address_free,
            'task' => $task->task,
            'comment' => $task->comment_hidden ? '[MASQUÉ]' : $task->comment,
            'comment_hidden' => (bool) $task->comment_hidden,
            'partially_pointed' => (bool) $task->partially_pointed,
            'pointed' => (bool) $task->pointed,
            'first_pointed_on' => $task->first_pointed_on?->toDateString(),
        ];
    }
}
