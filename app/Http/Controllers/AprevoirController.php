<?php

namespace App\Http\Controllers;

use App\Events\AprevoirTaskChanged;
use App\Events\AprevoirTaskUpdated;
use App\Models\AprevoirTask;
use App\Models\Depot;
use App\Models\EngraisTask;
use App\Models\TaskGroupOrder;
use App\Models\Transporter;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Aprevoir\AprevoirService;
use App\Services\AuditLogService;
use App\Services\Engrais\EngraisService;
use App\Support\Access\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AprevoirController extends Controller
{
    public function __construct(
        private readonly AprevoirService $service,
        private readonly EngraisService $engraisService,
        private readonly AuditLogService $auditLogService,
    )
    {
    }

    public function index(Request $request): Response
    {
        $config = $this->moduleConfig($request);
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'assignee_type' => ['nullable', Rule::in(['user', 'transporter', 'depot', 'free'])],
            'assignee_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'only_boursagri' => ['nullable', 'boolean'],
            'boursagri_contract_number' => ['nullable', 'string', 'max:100'],
            'pointed_filter' => ['nullable', Rule::in(['all', 'pointed', 'unpointed'])],
            'color_filter' => ['nullable', Rule::in(['all', 'colored', 'unstyled'])],
            'focus_task_id' => ['nullable', 'integer'],
        ]);

        $payload = [
            ...$validated,
            'user' => $request->user(),
            'only_boursagri' => filter_var($validated['only_boursagri'] ?? false, FILTER_VALIDATE_BOOL),
            'pointed_filter' => array_key_exists('pointed_filter', $validated)
                ? ($validated['pointed_filter'] ?? 'all')
                : 'unpointed',
        ];

        $result = $config['service']->getGroupedTasks($payload);

        $props = [
            'groups' => $result['groups'],
            'meta' => $result['meta'],
            'filters' => [
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'search' => $validated['search'] ?? '',
                'assignee_type' => $validated['assignee_type'] ?? '',
                'assignee_id' => $validated['assignee_id'] ?? '',
                'vehicle_id' => $validated['vehicle_id'] ?? '',
                'only_boursagri' => (bool) ($payload['only_boursagri'] ?? false),
                'boursagri_contract_number' => $validated['boursagri_contract_number'] ?? '',
                'pointed_filter' => $payload['pointed_filter'],
                'color_filter' => $validated['color_filter'] ?? 'all',
            ],
            'reference' => $this->referenceData($config),
            'permissions' => [
                'can_create' => $request->user() ? app(AccessManager::class)->can($request->user(), $config['permission_prefix'].'.create') : false,
                'can_update' => $request->user() ? app(AccessManager::class)->can($request->user(), $config['permission_prefix'].'.update') : false,
                'can_delete' => $request->user() ? app(AccessManager::class)->can($request->user(), $config['permission_prefix'].'.delete') : false,
                'can_point' => $request->user() ? app(AccessManager::class)->can($request->user(), $config['permission_prefix'].'.point') : false,
            ],
            'focus_task_id' => ! empty($validated['focus_task_id']) ? (int) $validated['focus_task_id'] : null,
            'moduleConfig' => [
                'key' => $config['key'],
                'title' => $config['title'],
                'show_book' => $config['projects_to_ldt'],
                'realtime_channel' => $config['realtime_channel'],
                'routes' => [
                    'index' => route($config['route_prefix'].'.index'),
                    'store' => route($config['route_prefix'].'.tasks.store'),
                    'update' => $config['route_prefix'].'.tasks.update',
                    'destroy' => $config['route_prefix'].'.tasks.destroy',
                    'point' => $config['route_prefix'].'.tasks.point',
                    'position' => $config['route_prefix'].'.tasks.position',
                    'group_position' => route($config['route_prefix'].'.groups.position'),
                    'data' => $config['route_prefix'].'.tasks.data',
                ],
            ],
        ];

        return Inertia::render($config['page'], $props);
    }

    public function tasksData(Request $request): JsonResponse
    {
        $config = $this->moduleConfig($request);
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'assignee_type' => ['nullable', Rule::in(['user', 'transporter', 'depot', 'free'])],
            'assignee_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'only_boursagri' => ['nullable', 'boolean'],
            'boursagri_contract_number' => ['nullable', 'string', 'max:100'],
            'pointed_filter' => ['nullable', Rule::in(['all', 'pointed', 'unpointed'])],
            'color_filter' => ['nullable', Rule::in(['all', 'colored', 'unstyled'])],
        ]);

        $payload = [
            ...$validated,
            'user' => $request->user(),
            'only_boursagri' => filter_var($validated['only_boursagri'] ?? false, FILTER_VALIDATE_BOOL),
            'pointed_filter' => $validated['pointed_filter'] ?? 'unpointed',
        ];

        $result = $config['service']->getGroupedTasks($payload);

        return response()->json([
            'groups' => $result['groups'],
            'meta' => $result['meta'],
            'pointed_filter' => $payload['pointed_filter'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $config = $this->moduleConfig($request);
        $validated = $this->validateTask($request, null);
        $actor = $request->user();

        $taskModelClass = $config['task_model'];
        $task = new $taskModelClass();
        $this->fillTask($task, $validated, $actor, $config);
        $task->created_by_user_id = $actor->id;
        $task->save();
        $afterAudit = $this->auditSnapshot($task);

        $this->auditLogService->log([
            'action' => $config['actions']['create'],
            'module' => $config['log_module'],
            'description' => sprintf('Création tâche %s #%d', $config['title'], (int) $task->id),
            'payload' => [
                'task_id' => $task->id,
                'after' => $afterAudit,
            ],
        ]);

        $this->dispatchTaskChange($config, 'created', $task, null);

        return back()->with('status', $config['title'].' task created.');
    }

    public function update(Request $request, mixed $task): RedirectResponse
    {
        $config = $this->moduleConfig($request);
        $task = $this->resolveTask($config, $task);
        $beforeGroup = $this->taskSnapshot($task);
        $beforeAudit = $this->auditSnapshot($task);
        $validated = $this->validateTask($request, $task);
        $actor = $request->user();

        $this->fillTask($task, $validated, $actor, $config);
        $task->updated_by_user_id = $actor->id;
        $task->save();
        $afterGroup = $this->taskSnapshot($task);
        $afterAudit = $this->auditSnapshot($task);

        $this->auditLogService->log([
            'action' => $config['actions']['update'],
            'module' => $config['log_module'],
            'description' => sprintf('Mise à jour tâche %s #%d', $config['title'], (int) $task->id),
            'payload' => [
                'task_id' => $task->id,
                'before' => $beforeAudit,
                'after' => $afterAudit,
            ],
        ]);

        $this->dispatchTaskChange(
            $config,
            'updated',
            $task,
            $beforeGroup,
            $request->input('client_mutation_id'),
        );

        return back()->with('status', $config['title'].' task updated.');
    }

    public function destroy(Request $request, mixed $task): RedirectResponse
    {
        $config = $this->moduleConfig($request);
        $task = $this->resolveTask($config, $task);
        $beforeGroup = $this->taskSnapshot($task);
        $beforeAudit = $this->auditSnapshot($task);
        $taskId = $task->id;
        $task->delete();

        $this->auditLogService->log([
            'action' => $config['actions']['delete'],
            'module' => $config['log_module'],
            'description' => sprintf('Suppression tâche %s #%d', $config['title'], (int) $taskId),
            'payload' => [
                'task_id' => $taskId,
                'before' => $beforeAudit,
            ],
        ]);

        if ($config['projects_to_ldt']) {
            AprevoirTaskChanged::dispatch('deleted', $taskId, $beforeGroup, null);
            $this->broadcastTaskUpdate('deleted', $taskId, $beforeGroup);
        }

        return back()->with('status', $config['title'].' task deleted.');
    }

    public function point(Request $request, mixed $task): RedirectResponse
    {
        $config = $this->moduleConfig($request);
        $task = $this->resolveTask($config, $task);
        $beforeGroup = $this->taskSnapshot($task);
        $beforeAudit = $this->auditSnapshot($task);
        $validated = $request->validate([
            'pointed' => ['required', 'boolean'],
        ]);

        $pointed = (bool) $validated['pointed'];

        $task->forceFill([
            'pointed' => $pointed,
            'pointed_at' => $pointed ? now() : null,
            'pointed_by_user_id' => $pointed ? $request->user()?->id : null,
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        $afterGroup = $this->taskSnapshot($task);
        $afterAudit = $this->auditSnapshot($task);
        $beforePointed = (bool) ($beforeAudit['pointed'] ?? false);
        $afterPointed = (bool) ($afterAudit['pointed'] ?? false);

        $this->auditLogService->log([
            'action' => $config['actions']['point'],
            'module' => $config['log_module'],
            'description' => sprintf(
                'Pointage tâche #%d (%s → %s)',
                (int) $task->id,
                $beforePointed ? 'pointé' : 'non pointé',
                $afterPointed ? 'pointé' : 'non pointé'
            ),
            'payload' => [
                'task_id' => $task->id,
                'pointed' => $pointed,
                'before_pointed' => $beforePointed,
                'after_pointed' => $afterPointed,
                'before' => $beforeAudit,
                'after' => $afterAudit,
            ],
        ]);

        if ($config['projects_to_ldt']) {
            AprevoirTaskChanged::dispatch(
                'pointed',
                $task->id,
                $beforeGroup,
                $afterGroup,
                ['pointed' => $pointed],
            );
            $this->broadcastTaskUpdate(
                'pointed',
                $task->id,
                $afterGroup,
                $request->input('client_mutation_id')
            );
        }

        return back()->with('status', $config['title'].' task pointed updated.');
    }

    public function updatePosition(Request $request, mixed $task): RedirectResponse
    {
        $config = $this->moduleConfig($request);
        $task = $this->resolveTask($config, $task);
        $beforeGroup = $this->taskSnapshot($task);
        $beforeAudit = $this->auditSnapshot($task);
        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'distinct'],
        ]);

        $taskModelClass = $config['task_model'];
        $groupTasksQuery = $taskModelClass::query()
            ->whereDate('date', $task->date)
            ->orderBy('position')
            ->orderBy('id');

        if ($task->assignee_type === 'free') {
            $groupTasksQuery
                ->where('assignee_type', 'free')
                ->where('assignee_label_free', $task->assignee_label_free);
        } elseif ($task->assignee_type === null) {
            $groupTasksQuery->whereNull('assignee_type')->whereNull('assignee_id');
        } else {
            $groupTasksQuery->where('assignee_type', $task->assignee_type)->where('assignee_id', $task->assignee_id);
        }

        $groupTasks = $groupTasksQuery->get(['id']);

        $groupIds = $groupTasks->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orderedIds = array_map('intval', $validated['ordered_ids']);

        sort($groupIds);
        $sortedOrdered = $orderedIds;
        sort($sortedOrdered);

        if ($groupIds !== $sortedOrdered) {
            return back()->withErrors([
                'ordered_ids' => 'Réordonnancement invalide: la sélection doit correspondre exactement au groupe.',
            ]);
        }

        DB::transaction(function () use ($orderedIds, $request, $taskModelClass): void {
            foreach ($orderedIds as $position => $id) {
                $taskModelClass::query()
                    ->whereKey($id)
                    ->update([
                        'position' => $position,
                        'updated_by_user_id' => $request->user()?->id,
                        'updated_at' => now(),
                    ]);
            }
        });

        $afterGroup = $this->taskSnapshot($task);
        $task->refresh();
        $afterAudit = $this->auditSnapshot($task);

        $this->auditLogService->log([
            'action' => $config['actions']['reorder'],
            'module' => $config['log_module'],
            'description' => 'Réordonnancement des tâches '.$config['title'],
            'payload' => [
                'task_id' => $task->id,
                'group' => [
                    'date' => $task->date?->toDateString(),
                    'assignee_type' => $task->assignee_type,
                    'assignee_id' => $task->assignee_id,
                ],
                'ordered_ids' => $orderedIds,
                'before' => $beforeAudit,
                'after' => $afterAudit,
            ],
        ]);

        if ($config['projects_to_ldt']) {
            AprevoirTaskChanged::dispatch(
                'reordered',
                $task->id,
                $beforeGroup,
                $afterGroup,
            );
            $this->broadcastTaskUpdate('moved', $task->id, $afterGroup);
        }

        return back()->with('status', $config['title'].' tasks reordered.');
    }

    /**
     * Réordonnancement manuel d'un ou plusieurs groupes (chauffeur, dépôt,
     * transporteur) entre eux, pour une date donnée uniquement. Ne touche
     * jamais à `position` (ordre des tâches à l'intérieur d'un groupe).
     */
    public function updateGroupOrder(Request $request): RedirectResponse
    {
        $config = $this->moduleConfig($request);
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'ordered_group_keys' => ['required', 'array', 'min:2'],
            'ordered_group_keys.*' => ['string', 'distinct'],
        ]);

        $date = (string) $validated['date'];
        $orderedKeys = array_values($validated['ordered_group_keys']);

        $actualKeys = $config['service']->groupKeysForDate($date);
        $sortedActual = $actualKeys;
        $sortedOrdered = $orderedKeys;
        sort($sortedActual);
        sort($sortedOrdered);

        if ($sortedActual !== $sortedOrdered) {
            return back()->withErrors([
                'ordered_group_keys' => 'Réordonnancement invalide: la sélection doit correspondre exactement aux groupes de cette date.',
            ]);
        }

        DB::transaction(function () use ($config, $date, $orderedKeys, $request): void {
            TaskGroupOrder::query()
                ->where('module', $config['key'])
                ->whereDate('date', $date)
                ->delete();

            foreach ($orderedKeys as $sortOrder => $groupKey) {
                TaskGroupOrder::query()->create([
                    'module' => $config['key'],
                    'date' => $date,
                    'group_key' => $groupKey,
                    'sort_order' => $sortOrder,
                    'updated_by_user_id' => $request->user()?->id,
                ]);
            }
        });

        $this->auditLogService->log([
            'action' => $config['actions']['reorder_groups'],
            'module' => $config['log_module'],
            'description' => 'Réordonnancement des groupes '.$config['title'].' du '.$date,
            'payload' => [
                'date' => $date,
                'ordered_group_keys' => $orderedKeys,
            ],
        ]);

        return back()->with('status', $config['title'].' groups reordered.');
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceData(array $config): array
    {
        $defaultEnsembleByUser = [];

        Vehicle::query()
            ->with('type:id,code')
            ->whereHas('type', fn ($query) => $query->where('code', 'ensemble_pl'))
            ->where(function ($query): void {
                $query->whereNotNull('driver_user_id')
                    ->orWhereNotNull('driver_carb_user_id');
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('registration')
            ->get(['id', 'name', 'registration', 'vehicle_type_id', 'driver_user_id', 'driver_carb_user_id', 'is_active'])
            ->each(function (Vehicle $vehicle) use (&$defaultEnsembleByUser): void {
                $label = trim(($vehicle->name ?: 'Sans nom').($vehicle->registration ? ' • '.$vehicle->registration : ''));

                if ($vehicle->driver_user_id && ! isset($defaultEnsembleByUser[$vehicle->driver_user_id])) {
                    $defaultEnsembleByUser[$vehicle->driver_user_id] = [
                        'id' => $vehicle->id,
                        'label' => $label,
                    ];
                }

                if ($vehicle->driver_carb_user_id && ! isset($defaultEnsembleByUser[$vehicle->driver_carb_user_id])) {
                    $defaultEnsembleByUser[$vehicle->driver_carb_user_id] = [
                        'id' => $vehicle->id,
                        'label' => $label,
                    ];
                }
            });

        $users = User::query()
            ->with('sector:id,name')
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'last_name', 'sector_id'])
            ->map(function (User $user) use ($defaultEnsembleByUser): array {
                $full = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

                return [
                    'id' => $user->id,
                    'name' => $full !== '' ? $full : $user->name,
                    'sector_id' => $user->sector_id,
                    'sector_name' => $user->sector?->name,
                    'default_vehicle_id' => $defaultEnsembleByUser[$user->id]['id'] ?? null,
                    'default_vehicle_label' => $defaultEnsembleByUser[$user->id]['label'] ?? null,
                ];
            })
            ->values()
            ->all();

        $transporters = Transporter::query()
            ->orderByRaw('CASE WHEN display_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('display_order')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('company_name')
            ->get(['id', 'first_name', 'last_name', 'company_name', 'display_order', 'is_active'])
            ->map(function (Transporter $transporter): array {
                $full = trim((string) (($transporter->first_name ?? '').' '.($transporter->last_name ?? '')));

                return [
                    'id' => $transporter->id,
                    'first_name' => $transporter->first_name,
                    'last_name' => $transporter->last_name,
                    'company_name' => $transporter->company_name,
                    'name' => $full !== '' ? $full : null,
                    'display_order' => $transporter->display_order,
                    'is_active' => (bool) $transporter->is_active,
                ];
            })
            ->values()
            ->all();

        $depotRecords = Depot::query()
            ->orderBy('name')
            ->get(['id', 'name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country']);

        $depots = $depotRecords
            ->map(fn (Depot $depot) => ['id' => $depot->id, 'name' => $depot->name])
            ->values()
            ->all();

        $depotNameSuggestions = $depotRecords
            ->map(static fn (Depot $depot): string => trim((string) $depot->name))
            ->filter()
            ->unique(static fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();

        $depotPlaceMap = $depotRecords
            ->reduce(function (array $carry, Depot $depot): array {
                $name = trim((string) $depot->name);
                if ($name === '') {
                    return $carry;
                }

                $addressParts = array_filter([
                    $depot->address_line1,
                    $depot->address_line2,
                    trim(implode(' ', array_filter([$depot->postal_code, $depot->city]))),
                    $depot->country,
                ]);

                $address = trim(implode(', ', $addressParts));
                if ($address !== '') {
                    $carry[$name] = $address;
                }

                return $carry;
            }, []);

        $vehicles = Vehicle::query()
            ->with('type:id,code')
            ->orderBy('name')
            ->orderBy('registration')
            ->get(['id', 'name', 'registration', 'vehicle_type_id'])
            ->map(fn (Vehicle $vehicle) => [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'registration' => $vehicle->registration,
                'type_code' => $vehicle->type?->code,
                'mode' => $vehicle->type?->code === 'ensemble_pl' ? 'ensemble_pl' : 'vehicle',
                'label' => trim(($vehicle->name ?: 'Sans nom').($vehicle->registration ? ' • '.$vehicle->registration : '')),
            ])
            ->values()
            ->all();

        $remorques = Vehicle::query()
            ->with('type:id,code')
            ->whereHas('type', fn ($query) => $query->where('code', 'benne'))
            ->orderBy('name')
            ->orderBy('registration')
            ->get(['id', 'name', 'registration', 'vehicle_type_id'])
            ->map(fn (Vehicle $vehicle) => [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'registration' => $vehicle->registration,
                'label' => trim(($vehicle->name ?: 'Sans nom').($vehicle->registration ? ' • '.$vehicle->registration : '')),
            ])
            ->values()
            ->all();

        $taskModelClass = $config['task_model'];
        $placeSuggestions = $taskModelClass::query()
            ->where(function ($query): void {
                $query->whereNotNull('loading_place')
                    ->orWhereNotNull('delivery_place');
            })
            ->orderByDesc('id')
            ->limit(3000)
            ->get(['loading_place', 'delivery_place'])
            ->flatMap(function (Model $task): array {
                $values = [];

                foreach ([$task->loading_place, $task->delivery_place] as $field) {
                    foreach (preg_split('/\r\n|\r|\n/', (string) $field) ?: [] as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $values[] = $line;
                        }
                    }
                }

                return $values;
            })
            ->reduce(function (array $carry, string $line): array {
                $key = mb_strtolower($line);
                if (! isset($carry[$key])) {
                    $carry[$key] = $line;
                }

                return $carry;
            }, []);

        $placeSuggestions = collect(array_merge(
            $depotNameSuggestions,
            array_values($depotPlaceMap),
            array_values($placeSuggestions)
        ))
            ->map(static fn ($line): string => trim((string) $line))
            ->filter()
            ->unique(static fn (string $line): string => mb_strtolower($line))
            ->values()
            ->all();

        usort(
            $placeSuggestions,
            static fn (string $a, string $b): int => strcasecmp($a, $b)
        );

        return [
            'assignee_users' => $users,
            'assignee_transporters' => $transporters,
            'assignee_depots' => $depots,
            'vehicles' => $vehicles,
            'remorques' => $remorques,
            'place_suggestions' => $placeSuggestions,
            'depot_place_suggestions' => $depotNameSuggestions,
            'depot_place_map' => $depotPlaceMap,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fillTask(Model $task, array $validated, ?User $actor, array $config): void
    {
        $detected = $this->service->autoDetectFlags(
            (string) ($validated['task'] ?? ''),
            $validated['comment'] ?? null
        );

        $isBoursagri = (bool) ($validated['is_boursagri'] ?? false) || $detected['is_boursagri'];
        $isDirect = (bool) ($validated['is_direct'] ?? false) || $detected['is_direct'];

        $task->date = $validated['date'];
        $task->fin_date = $validated['fin_date'] ?? null;
        $task->assignee_type = $validated['assignee_type'] ?? null;
        $task->assignee_id = isset($validated['assignee_id']) ? (int) $validated['assignee_id'] : null;
        $task->assignee_label_free = $validated['assignee_label_free'] ?? null;
        $task->vehicle_id = ! empty($validated['vehicle_id']) ? (int) $validated['vehicle_id'] : null;
        $selectedVehicle = null;
        if ($task->vehicle_id) {
            $selectedVehicle = Vehicle::query()
                ->with('type:id,code')
                ->find($task->vehicle_id);
        }
        $task->remorque_id = ! empty($validated['remorque_id']) ? (int) $validated['remorque_id'] : null;
        if (($selectedVehicle?->type?->code ?? null) === 'ensemble_pl') {
            $task->remorque_id = null;
        }
        $task->task = trim((string) $validated['task']);
        $task->loading_place = filled($validated['loading_place'] ?? null) ? trim((string) $validated['loading_place']) : null;
        $task->delivery_place = filled($validated['delivery_place'] ?? null) ? trim((string) $validated['delivery_place']) : null;
        $task->comment = filled($validated['comment'] ?? null) ? trim((string) $validated['comment']) : null;
        $task->is_direct = $isDirect;
        $task->is_boursagri = $isBoursagri;
        $task->boursagri_contract_number = $isBoursagri && filled($validated['boursagri_contract_number'] ?? null)
            ? trim((string) $validated['boursagri_contract_number'])
            : null;

        $task->indicators = array_merge(
            is_array($task->indicators) ? $task->indicators : [],
            [
                'auto_detected' => [
                    'direct' => $detected['is_direct'],
                    'boursagri' => $detected['is_boursagri'],
                ],
            ]
        );

        if (! $task->exists) {
            $task->position = $this->nextPosition(
                $config,
                (string) $validated['date'],
                $validated['assignee_type'] ?? null,
                isset($validated['assignee_id']) ? (int) $validated['assignee_id'] : null,
                $validated['assignee_label_free'] ?? null,
            );
            $task->pointed = false;
        }

        if ($actor) {
            $task->updated_by_user_id = $actor->id;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTask(Request $request, ?Model $task): array
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'fin_date' => ['nullable', 'date'],
            'assignee_type' => ['nullable', Rule::in(['user', 'transporter', 'depot', 'free'])],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
            'assignee_label_free' => ['nullable', 'string', 'max:255'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'remorque_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'task' => ['required', 'string', 'max:5000'],
            'loading_place' => ['nullable', 'string', 'max:5000'],
            'delivery_place' => ['nullable', 'string', 'max:5000'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'is_direct' => ['nullable', 'boolean'],
            'is_boursagri' => ['nullable', 'boolean'],
            'boursagri_contract_number' => ['nullable', 'string', 'max:120'],
            'indicators' => ['nullable', 'array'],
        ]);

        if (! empty($validated['fin_date']) && ! empty($validated['date']) && $validated['fin_date'] < $validated['date']) {
            return throw \Illuminate\Validation\ValidationException::withMessages([
                'fin_date' => 'La date de fin doit être supérieure ou égale à la date.',
            ]);
        }

        $freeLabel = trim((string) ($validated['assignee_label_free'] ?? ''));
        $hasFreeLabel = $freeLabel !== '';
        $hasType = filled($validated['assignee_type'] ?? null);
        $hasId = filled($validated['assignee_id'] ?? null);

        if (($validated['assignee_type'] ?? null) === 'free' && ! $hasFreeLabel) {
            return throw \Illuminate\Validation\ValidationException::withMessages([
                'assignee_label_free' => 'Renseignez un nom de chauffeur libre.',
            ]);
        }

        if (($validated['assignee_type'] ?? null) !== 'free' && ($hasType xor $hasId)) {
            return throw \Illuminate\Validation\ValidationException::withMessages([
                'assignee_id' => 'Renseignez un type et un assignataire, ou laissez les deux vides.',
            ]);
        }

        if (($validated['assignee_type'] ?? null) !== 'free' && $hasType && $hasId) {
            $exists = match ($validated['assignee_type']) {
                'user' => User::query()->whereKey((int) $validated['assignee_id'])->exists(),
                'transporter' => Transporter::query()->whereKey((int) $validated['assignee_id'])->exists(),
                'depot' => Depot::query()->whereKey((int) $validated['assignee_id'])->exists(),
                default => false,
            };

            if (! $exists) {
                return throw \Illuminate\Validation\ValidationException::withMessages([
                    'assignee_id' => 'Assignataire invalide.',
                ]);
            }
        }

        if ($hasFreeLabel) {
            $validated['assignee_type'] = 'free';
            $validated['assignee_id'] = null;
            $validated['assignee_label_free'] = $freeLabel;
        } else {
            $validated['assignee_label_free'] = null;
        }

        $vehicle = null;
        if (! empty($validated['vehicle_id'])) {
            $vehicle = Vehicle::query()
                ->with('type:id,code')
                ->find((int) $validated['vehicle_id']);

            if (! $vehicle) {
                return throw \Illuminate\Validation\ValidationException::withMessages([
                    'vehicle_id' => 'Véhicule invalide.',
                ]);
            }
        }

        if (! empty($validated['remorque_id'])) {
            $remorque = Vehicle::query()
                ->with('type:id,code')
                ->find((int) $validated['remorque_id']);

            if (! $remorque || $remorque->type?->code !== 'benne') {
                return throw \Illuminate\Validation\ValidationException::withMessages([
                    'remorque_id' => 'La remorque doit être un véhicule de type benne.',
                ]);
            }

            if (($vehicle?->type?->code ?? null) === 'ensemble_pl') {
                return throw \Illuminate\Validation\ValidationException::withMessages([
                    'remorque_id' => 'Aucune remorque manuelle pour un véhicule de type ensemble PL.',
                ]);
            }
        }

        return $validated;
    }

    /**
     * @return array<string,mixed>
     */
    private function auditSnapshot(Model $task): array
    {
        return [
            'id' => (int) $task->id,
            'date' => $task->date?->toDateString(),
            'fin_date' => $task->fin_date?->toDateString(),
            'assignee_type' => $task->assignee_type,
            'assignee_id' => $task->assignee_id,
            'assignee_label_free' => $task->assignee_label_free,
            'vehicle_id' => $task->vehicle_id,
            'remorque_id' => $task->remorque_id,
            'task' => $task->task,
            'loading_place' => $task->loading_place,
            'delivery_place' => $task->delivery_place,
            'comment' => $task->comment,
            'is_direct' => (bool) $task->is_direct,
            'is_boursagri' => (bool) $task->is_boursagri,
            'boursagri_contract_number' => $task->boursagri_contract_number,
            'pointed' => (bool) $task->pointed,
            'position' => (int) ($task->position ?? 0),
        ];
    }

    private function nextPosition(array $config, string $date, ?string $assigneeType, ?int $assigneeId, ?string $assigneeLabelFree): int
    {
        $taskModelClass = $config['task_model'];
        $query = $taskModelClass::query()->whereDate('date', $date);

        if ($assigneeType === 'free') {
            $query
                ->where('assignee_type', 'free')
                ->where('assignee_label_free', $assigneeLabelFree);
        } elseif ($assigneeType === null || $assigneeId === null) {
            $query->whereNull('assignee_type')->whereNull('assignee_id');
        } else {
            $query->where('assignee_type', $assigneeType)->where('assignee_id', $assigneeId);
        }

        return (int) $query->max('position') + 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleConfig(Request $request): array
    {
        $isEngrais = str_starts_with((string) $request->route()?->getName(), 'engrais.');

        if ($isEngrais) {
            return [
                'key' => 'engrais',
                'title' => 'Engrais',
                'page' => 'Engrais/Index',
                'route_prefix' => 'engrais',
                'permission_prefix' => 'engrais',
                'log_module' => 'engrais',
                'task_model' => EngraisTask::class,
                'service' => $this->engraisService,
                'projects_to_ldt' => false,
                'realtime_channel' => null,
                'actions' => [
                    'create' => 'create_engrais_task',
                    'update' => 'update_engrais_task',
                    'delete' => 'delete_engrais_task',
                    'point' => 'point_engrais_task',
                    'reorder' => 'reorder_engrais_task',
                    'reorder_groups' => 'reorder_engrais_groups',
                ],
            ];
        }

        return [
            'key' => 'a_prevoir',
            'title' => 'À Prévoir',
            'page' => 'Aprevoir/Index',
            'route_prefix' => 'a_prevoir',
            'permission_prefix' => 'a_prevoir',
            'log_module' => 'a_prevoir',
            'task_model' => AprevoirTask::class,
            'service' => $this->service,
            'projects_to_ldt' => true,
            'realtime_channel' => 'aprevoir.global',
            'actions' => [
                'create' => 'create_task',
                'update' => 'update_task',
                'delete' => 'delete_task',
                'point' => 'point_task',
                'reorder' => 'reorder_task',
                'reorder_groups' => 'reorder_task_groups',
            ],
        ];
    }

    private function resolveTask(array $config, mixed $task): Model
    {
        $taskModelClass = $config['task_model'];

        if ($task instanceof $taskModelClass) {
            return $task;
        }

        return $taskModelClass::query()->findOrFail((int) $task);
    }

    /**
     * @return array{date:string|null,assignee_type:string|null,assignee_id:int|null,assignee_label_free:string|null}
     */
    private function taskSnapshot(Model $task): array
    {
        return [
            'date' => $task->date?->toDateString(),
            'assignee_type' => $task->assignee_type,
            'assignee_id' => $task->assignee_id !== null ? (int) $task->assignee_id : null,
            'assignee_label_free' => $task->assignee_label_free,
        ];
    }

    private function dispatchTaskChange(
        array $config,
        string $action,
        Model $task,
        ?array $beforeGroup,
        ?string $clientMutationId = null,
    ): void {
        if (! $config['projects_to_ldt']) {
            return;
        }

        $afterGroup = $this->taskSnapshot($task);
        AprevoirTaskChanged::dispatch($action, $task->id, $beforeGroup, $afterGroup);
        $this->broadcastTaskUpdate($action, $task->id, $afterGroup, $clientMutationId);
    }

    /**
     * @param  array{date:string,assignee_type:?string,assignee_id:?int}|null  $snapshot
     */
    private function broadcastTaskUpdate(string $action, ?int $taskId, ?array $snapshot, ?string $clientMutationId = null): void
    {
        try {
            AprevoirTaskUpdated::dispatch(
                (int) ($taskId ?? 0),
                $snapshot['date'] ?? null,
                $snapshot['assignee_type'] ?? null,
                isset($snapshot['assignee_id']) ? (int) $snapshot['assignee_id'] : null,
                $action,
                $clientMutationId,
            );
        } catch (Throwable $exception) {
            Log::warning('Aprevoir realtime broadcast failed.', [
                'action' => $action,
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
