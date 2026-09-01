<?php

namespace App\Http\Controllers;

use App\Http\Requests\Maintenance\MaintenanceTaskRequest;
use App\Models\Depot;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Services\Maintenance\MaintenanceService;
use App\Support\Access\AccessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly MaintenanceNotifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MaintenanceTask::class);

        $filters = $this->validateFilters($request);
        $focusTaskId = $this->focusTaskId($request);

        if ($focusTaskId !== null) {
            $filters['pointed_filter'] = 'all';
        }

        $result = $this->service->getGroupedTasks($filters, $request->user());

        return Inertia::render('Maintenance/Index', [
            'groups' => $result['groups'],
            'meta' => $result['meta'],
            'filters' => $filters,
            'reference' => $this->referenceData(),
            'permissions' => $this->permissionFlags($request->user()),
            'focus_task_id' => $focusTaskId,
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

        // Écriture isolée : les notifications ne partent qu'une fois la tâche
        // réellement enregistrée.
        $task = DB::transaction(function () use ($request, $actor, $origin): MaintenanceTask {
            $task = new MaintenanceTask;
            $task->fill($request->taskAttributes());
            $task->origin = $origin;
            $task->created_by_user_id = $actor->id;
            $task->requested_by_user_id = $origin === MaintenanceTask::ORIGIN_REQUEST ? $actor->id : null;
            $task->updated_by_user_id = $actor->id;
            $task->save();

            return $task;
        });

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

        if ($origin === MaintenanceTask::ORIGIN_REQUEST) {
            $this->notifier->requestSubmitted($task, $actor);
        }

        $this->notifier->taskAssigned($task, $actor->id);

        return back()->with('status', 'Tâche Maintenance enregistrée.');
    }

    public function update(MaintenanceTaskRequest $request, MaintenanceTask $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $before = $this->auditSnapshot($task);
        $actor = $request->user();

        $attributes = $request->taskAttributes();

        // Un commentaire masqué que l'auteur de la modification n'a pas le droit
        // de lire ne lui a pas été transmis : il ne doit pas pouvoir l'écraser
        // avec un champ vide. On conserve l'existant tel quel.
        if ($task->comment_hidden && ! $this->service->canSeeHiddenComments($actor)) {
            unset($attributes['comment'], $attributes['comment_hidden']);
        }

        // Transformer une demande en tâche, c'est convertir la même ligne :
        // aucune seconde entrée n'est créée, le demandeur et l'origine sont
        // conservés, seul converted_at fait sortir la tâche des demandes en
        // attente.
        $converting = $request->wantsConversion();

        if ($converting) {
            $this->authorize('convert', $task);
        }

        DB::transaction(function () use ($task, $attributes, $actor, $converting): void {
            $task->fill($attributes);
            $task->updated_by_user_id = $actor->id;

            if ($converting) {
                $task->converted_at = now();
                $task->converted_by_user_id = $actor->id;
            }

            $task->save();
        });

        $after = $this->auditSnapshot($task);

        $this->auditLogService->log([
            'action' => 'update_maintenance_task',
            'module' => self::LOG_MODULE,
            'description' => sprintf('Mise à jour tâche Maintenance #%d', (int) $task->id),
            'payload' => [
                'task_id' => $task->id,
                'before' => $before,
                'after' => $after,
            ],
        ]);

        // Trace dédiée : un changement de personne affectée se cherche dans les
        // logs sans avoir à comparer deux instantanés de modification.
        if (($before['assignee_user_id'] ?? null) !== ($after['assignee_user_id'] ?? null)
            || ($before['assignee_label_free'] ?? null) !== ($after['assignee_label_free'] ?? null)) {
            $this->auditLogService->log([
                'action' => 'reassign_maintenance_task',
                'module' => self::LOG_MODULE,
                'description' => sprintf(
                    'Changement d\'affectation tâche Maintenance #%d (%s → %s)',
                    (int) $task->id,
                    $this->assigneeLabelForLog($before),
                    $this->assigneeLabelForLog($after),
                ),
                'payload' => [
                    'task_id' => $task->id,
                    'before_assignee_user_id' => $before['assignee_user_id'] ?? null,
                    'after_assignee_user_id' => $after['assignee_user_id'] ?? null,
                    'before_assignee_label_free' => $before['assignee_label_free'] ?? null,
                    'after_assignee_label_free' => $after['assignee_label_free'] ?? null,
                ],
            ]);
        }

        $notified = $this->notifier->taskUpdated($task, $before, $after, $actor->id);

        if ($converting) {
            $this->auditLogService->log([
                'action' => 'convert_maintenance_request',
                'module' => self::LOG_MODULE,
                'description' => sprintf(
                    'Demande Maintenance #%d transformée en tâche',
                    (int) $task->id
                ),
                'payload' => [
                    'task_id' => $task->id,
                    'requested_by_user_id' => $task->requested_by_user_id,
                    'before' => $before,
                    'after' => $after,
                ],
            ]);

            // Le demandeur est prévenu que sa demande est prise en charge, sauf
            // s'il vient déjà d'être notifié comme affecté à la tâche.
            $this->notifier->requestConverted($task, $actor->id, $notified);
        }

        return back()->with(
            'status',
            $converting ? 'Demande transformée en tâche.' : 'Tâche Maintenance mise à jour.'
        );
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

        // Les deux pointages sont indépendants : le définitif peut précéder ou
        // suivre le partiel, et ne l'efface pas. Le dépointage ne remet à zéro
        // que ses propres traces techniques.
        $task->forceFill([
            'pointed' => $pointed,
            'pointed_at' => $pointed ? now() : null,
            'pointed_by_user_id' => $pointed ? $request->user()?->id : null,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        if ($pointed) {
            $task->stampFirstPointingDate();
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
        // Règle d'identité vérifiée hors du Gate : le Gate::before accorde tout
        // aux administrateurs, ce qui ouvrirait le pointage partiel à un autre
        // que la personne affectée.
        abort_unless($task->isPartialPointableBy($request->user()), 403);

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
            $task->stampFirstPointingDate();
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
     * Correction manuelle de la date métier du premier pointage. Les
     * horodatages techniques des deux pointages ne sont pas touchés : la
     * traçabilité de qui a pointé et quand reste intacte.
     */
    public function updatePointingDate(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $this->authorize('updatePointingDate', $task);

        $validated = $request->validate([
            'first_pointed_on' => ['present', 'nullable', 'date'],
        ]);

        $before = $this->auditSnapshot($task);
        $newDate = $validated['first_pointed_on'] ?? null;

        $task->forceFill([
            'first_pointed_on' => $newDate,
            // Une fois posée à la main, la date échappe définitivement au
            // calcul automatique, y compris si elle est vidée.
            'first_pointed_on_manual' => true,
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        $this->auditLogService->log([
            'action' => 'update_maintenance_pointing_date',
            'module' => self::LOG_MODULE,
            'description' => sprintf(
                'Date du premier pointage de la tâche Maintenance #%d fixée manuellement (%s → %s)',
                (int) $task->id,
                $before['first_pointed_on'] ?? 'vide',
                $task->first_pointed_on?->toDateString() ?? 'vide'
            ),
            'payload' => [
                'task_id' => $task->id,
                'before' => $before,
                'after' => $this->auditSnapshot($task),
            ],
        ]);

        return back()->with('status', 'Date du premier pointage mise à jour.');
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
            'focus_task_id' => ['nullable', 'integer'],
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
     * Une notification pointe vers une tâche précise : on élargit le filtre à
     * tous les états pour qu'elle soit visible quel que soit son pointage.
     */
    private function focusTaskId(Request $request): ?int
    {
        $value = $request->query('focus_task_id');

        return is_numeric($value) ? (int) $value : null;
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
            // Dépôt de rattachement du demandeur : le serveur le connaît, le
            // formulaire n'a pas à le deviner.
            'current_user_depot_id' => request()->user()?->depot_id,
            'assignee_users' => $users,
            'depots' => $depots,
            'depot_place_map' => $depotPlaceMap,
            'depot_name_suggestions' => array_values(array_keys($depotPlaceMap)),
            'place_suggestions' => $this->service->placeSuggestions(),
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
     * @param  array<string, mixed>  $snapshot
     */
    private function assigneeLabelForLog(array $snapshot): string
    {
        if (! empty($snapshot['assignee_user_id'])) {
            return 'utilisateur #'.$snapshot['assignee_user_id'];
        }

        $free = trim((string) ($snapshot['assignee_label_free'] ?? ''));

        return $free !== '' ? $free : 'non affectée';
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
            'first_pointed_on_manual' => (bool) $task->first_pointed_on_manual,
        ];
    }
}
