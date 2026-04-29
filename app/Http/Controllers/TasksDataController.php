<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\Transporter;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TasksDataController extends Controller
{
    public function __construct(
        private readonly AccessManager $accessManager,
        private readonly AuditLogService $auditLogService,
    )
    {
    }

    public function index(Request $request): Response
    {
        /** @var User|null $actor */
        $actor = $request->user();
        $hasDisplayOrder = $this->usersHasColumn('display_order');
        $hasOperationsComment = $this->usersHasColumn('operations_comment');
        $hasNacelleValidity = $this->usersHasColumn('nacelle_valid_until');
        $hasEcoValidity = $this->usersHasColumn('eco_conduite_valid_until');
        $hasDepotId = $this->usersHasColumn('depot_id');
        $hasDepotPivot = Schema::hasTable('depot_user');

        $canJeudyView = $this->can($actor, 'task.data.jeudy.view') || $this->can($actor, 'task.data.jeudy.manage');
        $canJeudyManage = $this->can($actor, 'task.data.jeudy.manage');
        $canTransportersView = $this->can($actor, 'task.data.transporters.view') || $this->can($actor, 'task.data.transporters.manage');
        $canTransportersManage = $this->can($actor, 'task.data.transporters.manage');
        $canDepotsView = $this->can($actor, 'task.data.depots.view') || $this->can($actor, 'task.data.depots.manage');
        $canDepotsManage = $this->can($actor, 'task.data.depots.manage');
        $canVehiclesView = $canDepotsView;
        $canVehiclesManage = $canDepotsManage;

        $sections = collect([
            ['key' => 'jeudy', 'label' => 'Personnels Jeudy', 'can_view' => $canJeudyView, 'can_manage' => $canJeudyManage, 'group' => 'core'],
            ['key' => 'transporters', 'label' => 'Transporteurs', 'can_view' => $canTransportersView, 'can_manage' => $canTransportersManage, 'group' => 'core'],
            ['key' => 'depots', 'label' => 'Dépôts', 'can_view' => $canDepotsView, 'can_manage' => $canDepotsManage, 'group' => 'core'],
            ['key' => 'camions', 'label' => 'Camions', 'can_view' => $canVehiclesView, 'can_manage' => $canVehiclesManage, 'group' => 'vehicles'],
            ['key' => 'remorques', 'label' => 'Remorques', 'can_view' => $canVehiclesView, 'can_manage' => $canVehiclesManage, 'group' => 'vehicles'],
            ['key' => 'ensembles_pl', 'label' => 'Ensemble PL', 'can_view' => $canVehiclesView, 'can_manage' => $canVehiclesManage, 'group' => 'vehicles'],
            ['key' => 'vl', 'label' => 'VL', 'can_view' => $canVehiclesView, 'can_manage' => $canVehiclesManage, 'group' => 'vehicles'],
        ])->where('can_view')->values();

        $activeSection = (string) $request->query('section', '');
        if (! $sections->contains(fn (array $section): bool => $section['key'] === $activeSection)) {
            $firstVisibleSection = $sections->first();
            $activeSection = is_array($firstVisibleSection) ? (string) ($firstVisibleSection['key'] ?? '') : '';
        }

        $depotsLookup = Depot::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Depot $depot): array => [
                'id' => $depot->id,
                'name' => $depot->name,
            ])
            ->values();

        $jeudyPersonnel = collect();
        if ($canJeudyView) {
            $jeudyQuery = User::query()
                ->with(['sector:id,name'])
                ->orderByRaw("COALESCE(NULLIF(last_name, ''), NULLIF(name, ''), email) asc")
                ->orderByRaw("COALESCE(NULLIF(first_name, ''), '') asc");

            if ($hasDisplayOrder) {
                $jeudyQuery
                    ->orderByRaw('CASE WHEN display_order IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('display_order');
            }

            if ($hasDepotId) {
                $jeudyQuery->with('depot:id,name');
            }

            if ($hasDepotPivot) {
                $jeudyQuery->with('depots:id,name');
            }

            $select = [
                    'id',
                    'name',
                    'first_name',
                    'last_name',
                    'email',
                    'phone',
                    'mobile_phone',
                    'sector_id',
                    'photo_path',
                ];

            if ($hasDepotId) {
                $select[] = 'depot_id';
            }
            if ($hasNacelleValidity) {
                $select[] = 'nacelle_valid_until';
            }
            if ($hasEcoValidity) {
                $select[] = 'eco_conduite_valid_until';
            }
            if ($hasOperationsComment) {
                $select[] = 'operations_comment';
            }
            if ($hasDisplayOrder) {
                $select[] = 'display_order';
            }

            $jeudyPersonnel = $jeudyQuery
                ->get($select)
                ->map(function (User $user) use ($hasDepotPivot): array {
                    $depots = [];

                    if ($hasDepotPivot && $user->relationLoaded('depots')) {
                        $depots = $user->depots
                            ->map(fn (Depot $depot): array => ['id' => $depot->id, 'name' => $depot->name])
                            ->values()
                            ->all();
                    }

                    if ($depots === [] && $user->relationLoaded('depot') && $user->depot) {
                        $depots[] = [
                            'id' => $user->depot->id,
                            'name' => $user->depot->name,
                        ];
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'mobile_phone' => $user->mobile_phone,
                        'sector_id' => $user->sector_id,
                        'sector_name' => $user->sector?->name,
                        'photo_url' => $this->photoUrl($user->photo_path),
                        'nacelle_valid_until' => $user->nacelle_valid_until?->toDateString() ?? null,
                        'eco_conduite_valid_until' => $user->eco_conduite_valid_until?->toDateString() ?? null,
                        'operations_comment' => $user->operations_comment,
                        'display_order' => $user->display_order,
                        'depots' => $depots,
                        'depot_ids' => array_values(array_unique(array_map(static fn (array $depot): int => (int) $depot['id'], $depots))),
                    ];
                })
                ->values();
        }

        $transporters = collect();
        if ($canTransportersView) {
            $transporters = Transporter::query()
                ->orderByRaw('CASE WHEN display_order IS NULL THEN 1 ELSE 0 END')
                ->orderBy('display_order')
                ->orderByRaw("COALESCE(NULLIF(last_name, ''), NULLIF(company_name, ''), '') asc")
                ->orderByRaw("COALESCE(NULLIF(first_name, ''), '') asc")
                ->get()
                ->map(fn (Transporter $transporter): array => [
                    'id' => $transporter->id,
                    'first_name' => $transporter->first_name,
                    'last_name' => $transporter->last_name,
                    'phone' => $transporter->phone,
                    'email' => $transporter->email,
                    'company_name' => $transporter->company_name,
                    'comment' => $transporter->comment,
                    'display_order' => $transporter->display_order,
                    'is_active' => (bool) $transporter->is_active,
                ])
                ->values();
        }

        $depots = collect();
        if ($canDepotsView) {
            $depots = Depot::query()
                ->withCount(['vehicles', 'users', 'attachedUsers'])
                ->orderBy('name')
                ->get()
                ->map(fn (Depot $depot): array => [
                    'id' => $depot->id,
                    'name' => $depot->name,
                    'address_line1' => $depot->address_line1,
                    'address_line2' => $depot->address_line2,
                    'postal_code' => $depot->postal_code,
                    'city' => $depot->city,
                    'country' => $depot->country,
                    'phone' => $depot->phone,
                    'email' => $depot->email,
                    'gps_lat' => $depot->gps_lat,
                    'gps_lng' => $depot->gps_lng,
                    'is_active' => (bool) $depot->is_active,
                    'vehicles_count' => (int) $depot->vehicles_count,
                    'users_count' => (int) ($depot->users_count + $depot->attached_users_count),
                ])
                ->values();
        }

        $vehicleSections = [
            'camions' => [],
            'remorques' => [],
            'ensembles_pl' => [],
            'vl' => [],
        ];
        $vehicleFormOptions = [
            'type_options' => [
                'camions' => [],
                'remorques' => [],
                'ensembles_pl' => [],
                'vl' => [],
            ],
            'depots' => [],
            'tractor_candidates' => [],
            'remorque_candidates' => [],
        ];

        if ($canVehiclesView) {
            $vehicles = Vehicle::query()
                ->with([
                    'type:id,code,label',
                    'depot:id,name',
                    'garage:id,name',
                    'tractor:id,name,registration',
                    'bennes:id,name,registration',
                ])
                ->orderByRaw('CASE WHEN is_active = 1 THEN 0 ELSE 1 END')
                ->orderByRaw('COALESCE(name, registration, CONCAT("Vehicule #", id)) asc')
                ->get([
                    'id',
                    'vehicle_type_id',
                    'name',
                    'registration',
                    'code_zeendoc',
                    'depot_id',
                    'garage_id',
                    'tractor_vehicle_id',
                    'is_active',
                ])
                ->map(function (Vehicle $vehicle): array {
                    $vehicleName = trim((string) $vehicle->name);
                    $registration = trim((string) $vehicle->registration);
                    $typeCode = strtolower((string) ($vehicle->type?->code ?? ''));
                    $typeLabel = (string) ($vehicle->type?->label ?? '');

                    return [
                        'id' => $vehicle->id,
                        'vehicle_type_id' => $vehicle->vehicle_type_id,
                        'name' => $vehicleName,
                        'registration' => $registration,
                        'display_label' => $vehicleName !== '' ? $vehicleName : ($registration !== '' ? $registration : 'Véhicule #'.$vehicle->id),
                        'type_code' => $typeCode,
                        'type_label' => $typeLabel,
                        'code_zeendoc' => trim((string) $vehicle->code_zeendoc),
                        'mode' => $typeCode === 'ensemble_pl' ? 'ensemble_pl' : 'vehicle',
                        'depot_id' => $vehicle->depot_id,
                        'depot_name' => (string) ($vehicle->depot?->name ?? ''),
                        'garage_name' => (string) ($vehicle->garage?->name ?? ''),
                        'is_active' => (bool) $vehicle->is_active,
                        'tractor_vehicle_id' => $vehicle->tractor_vehicle_id,
                        'benne_ids' => $vehicle->bennes->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                        'tractor_label' => $vehicle->tractor
                            ? trim((string) $vehicle->tractor->name) !== ''
                                ? trim((string) $vehicle->tractor->name).' • '.trim((string) $vehicle->tractor->registration)
                                : trim((string) $vehicle->tractor->registration)
                            : '',
                        'bennes_labels' => $vehicle->bennes
                            ->map(fn (Vehicle $benne): string => trim((string) $benne->name) !== ''
                                ? trim((string) $benne->name).' • '.trim((string) $benne->registration)
                                : trim((string) $benne->registration))
                            ->filter()
                            ->values()
                            ->all(),
                    ];
                })
                ->values();

            $isCamion = static function (array $vehicle): bool {
                $code = strtolower((string) ($vehicle['type_code'] ?? ''));
                $label = strtolower((string) ($vehicle['type_label'] ?? ''));

                return in_array($code, ['tracteur', 'porteur', 'camion'], true)
                    || str_contains($label, 'tracteur')
                    || str_contains($label, 'porteur');
            };

            $isRemorque = static function (array $vehicle): bool {
                $code = strtolower((string) ($vehicle['type_code'] ?? ''));
                $label = strtolower((string) ($vehicle['type_label'] ?? ''));

                return $code === 'benne' || str_contains($label, 'benne') || str_contains($label, 'remorque');
            };

            $isEnsemblePl = static function (array $vehicle): bool {
                $code = strtolower((string) ($vehicle['type_code'] ?? ''));
                $label = strtolower((string) ($vehicle['type_label'] ?? ''));

                return $code === 'ensemble_pl' || str_contains($label, 'ensemble pl');
            };

            $isVl = static function (array $vehicle): bool {
                $code = strtolower((string) ($vehicle['type_code'] ?? ''));
                $label = strtolower((string) ($vehicle['type_label'] ?? ''));

                return $code === 'vl' || $label === 'vl';
            };

            $vehicleSections = [
                'camions' => $vehicles->filter($isCamion)->values()->all(),
                'remorques' => $vehicles->filter($isRemorque)->values()->all(),
                'ensembles_pl' => $vehicles->filter($isEnsemblePl)->values()->all(),
                'vl' => $vehicles->filter($isVl)->values()->all(),
            ];

            $vehicleFormOptions = $this->vehicleFormOptions();
        }

        return Inertia::render('Tasks/Data/Index', [
            'sections' => $sections,
            'active_section' => $activeSection,
            'permissions' => [
                'jeudy_view' => $canJeudyView,
                'jeudy_manage' => $canJeudyManage,
                'transporters_view' => $canTransportersView,
                'transporters_manage' => $canTransportersManage,
                'depots_view' => $canDepotsView,
                'depots_manage' => $canDepotsManage,
                'vehicles_view' => $canVehiclesView,
                'vehicles_manage' => $canVehiclesManage,
            ],
            'lookups' => [
                'depots' => $depotsLookup,
            ],
            'jeudy_personnel' => $jeudyPersonnel,
            'transporters' => $transporters,
            'depots' => $depots,
            'vehicle_sections' => $vehicleSections,
            'vehicle_form_options' => $vehicleFormOptions,
        ]);
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $this->ensureVehicleManagePermission($request->user());

        $validated = $this->validatedVehicleSectionPayload($request);
        $payload = $this->makeVehiclePayloadFromSection($validated);

        $vehicle = Vehicle::query()->create($payload['vehicle']);
        if ($payload['is_ensemble']) {
            $vehicle->bennes()->sync($payload['benne_ids']);
        }

        $this->auditLogService->log([
            'action' => 'create_vehicle',
            'module' => 'task_data',
            'description' => 'Véhicule créé depuis Données > '.$validated['section'],
            'payload' => [
                'vehicle_id' => $vehicle->id,
                'section' => $validated['section'],
            ],
        ]);

        return back()->with('status', 'Véhicule enregistré.');
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->ensureVehicleManagePermission($request->user());

        $validated = $this->validatedVehicleSectionPayload($request, $vehicle);
        $payload = $this->makeVehiclePayloadFromSection($validated, $vehicle);

        $vehicle->update($payload['vehicle']);
        $vehicle->bennes()->sync($payload['is_ensemble'] ? $payload['benne_ids'] : []);

        $this->auditLogService->log([
            'action' => 'update_vehicle',
            'module' => 'task_data',
            'description' => 'Véhicule modifié depuis Données > '.$validated['section'],
            'payload' => [
                'vehicle_id' => $vehicle->id,
                'section' => $validated['section'],
            ],
        ]);

        return back()->with('status', 'Véhicule enregistré.');
    }

    public function updateJeudy(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:60'],
            'mobile_phone' => ['nullable', 'string', 'max:60'],
            'depot_ids' => ['nullable', 'array'],
            'depot_ids.*' => ['integer', Rule::exists('depots', 'id')],
            'operations_comment' => ['nullable', 'string', 'max:3000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $depotIds = collect($validated['depot_ids'] ?? [])
            ->map(static fn ($value): int => (int) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $attributes = [];

        if ($this->usersHasColumn('phone')) {
            $attributes['phone'] = $validated['phone'] ?? null;
        }
        if ($this->usersHasColumn('mobile_phone')) {
            $attributes['mobile_phone'] = $validated['mobile_phone'] ?? null;
        }
        if ($this->usersHasColumn('operations_comment')) {
            $attributes['operations_comment'] = $validated['operations_comment'] ?? null;
        }
        if ($this->usersHasColumn('display_order')) {
            $attributes['display_order'] = array_key_exists('display_order', $validated) ? $validated['display_order'] : null;
        }
        if ($this->usersHasColumn('depot_id')) {
            $attributes['depot_id'] = $depotIds[0] ?? null;
        }

        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }

        if (Schema::hasTable('depot_user')) {
            $user->depots()->sync($depotIds);
        }

        $this->auditLogService->log([
            'action' => 'update_user',
            'module' => 'task_data',
            'description' => 'Mise a jour personnel Jeudy',
            'payload' => [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'mobile_phone' => $user->mobile_phone,
                'display_order' => $user->display_order,
                'depot_ids' => $depotIds,
            ],
        ]);

        return back()->with('status', 'Personnel Jeudy enregistré.');
    }

    public function storeTransporter(Request $request): RedirectResponse
    {
        $this->authorize('create', Transporter::class);

        $validated = $this->validatedTransporterPayload($request);
        $validated['created_by_user_id'] = $request->user()?->id;
        $validated['updated_by_user_id'] = $request->user()?->id;

        $transporter = Transporter::query()->create($validated);

        $this->auditLogService->log([
            'action' => 'create_transporter',
            'module' => 'task_data',
            'description' => 'Transporteur créé',
            'payload' => [
                'transporter_id' => $transporter->id,
                'name' => trim((string) ($transporter->first_name.' '.$transporter->last_name)),
                'company_name' => $transporter->company_name,
            ],
        ]);

        return back()->with('status', 'Transporteur enregistré.');
    }

    public function updateTransporter(Request $request, Transporter $transporter): RedirectResponse
    {
        $this->authorize('update', $transporter);

        $validated = $this->validatedTransporterPayload($request);
        $validated['updated_by_user_id'] = $request->user()?->id;

        $transporter->update($validated);

        $this->auditLogService->log([
            'action' => 'update_transporter',
            'module' => 'task_data',
            'description' => 'Transporteur modifié',
            'payload' => [
                'transporter_id' => $transporter->id,
            ],
        ]);

        return back()->with('status', 'Transporteur enregistré.');
    }

    public function destroyTransporter(Transporter $transporter): RedirectResponse
    {
        $this->authorize('delete', $transporter);

        $transporter->delete();

        $this->auditLogService->log([
            'action' => 'delete_transporter',
            'module' => 'task_data',
            'description' => 'Transporteur supprimé',
            'payload' => [
                'transporter_id' => $transporter->id,
            ],
        ]);

        return back()->with('status', 'Transporteur supprimé.');
    }

    public function storeDepot(Request $request): RedirectResponse
    {
        $this->ensureDepotManagePermission($request->user());

        $depot = Depot::query()->create($this->validatedDepotPayload($request));

        $this->auditLogService->log([
            'action' => 'create_depot',
            'module' => 'task_data',
            'description' => 'Dépôt créé',
            'payload' => [
                'depot_id' => $depot->id,
                'name' => $depot->name,
            ],
        ]);

        return back()->with('status', 'Dépôt enregistré.');
    }

    public function updateDepot(Request $request, Depot $depot): RedirectResponse
    {
        $this->ensureDepotManagePermission($request->user());

        $depot->update($this->validatedDepotPayload($request));

        $this->auditLogService->log([
            'action' => 'update_depot',
            'module' => 'task_data',
            'description' => 'Dépôt modifié',
            'payload' => [
                'depot_id' => $depot->id,
                'name' => $depot->name,
            ],
        ]);

        return back()->with('status', 'Dépôt enregistré.');
    }

    public function destroyDepot(Request $request, Depot $depot): RedirectResponse
    {
        $this->ensureDepotManagePermission($request->user());

        if ($depot->vehicles()->exists() || $depot->users()->exists() || $depot->attachedUsers()->exists()) {
            return back()->withErrors([
                'depot' => 'Impossible de supprimer ce dépôt car il est utilisé.',
            ]);
        }

        $depot->delete();

        $this->auditLogService->log([
            'action' => 'delete_depot',
            'module' => 'task_data',
            'description' => 'Dépôt supprimé',
            'payload' => [
                'depot_id' => $depot->id,
            ],
        ]);

        return back()->with('status', 'Dépôt supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTransporterPayload(Request $request): array
    {
        return $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:180'],
            'comment' => ['nullable', 'string', 'max:3000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDepotPayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:2'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['country'] = strtoupper((string) ($validated['country'] ?? 'FR'));
        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedVehicleSectionPayload(Request $request, ?Vehicle $currentVehicle = null): array
    {
        $validated = $request->validate([
            'section' => ['required', 'string', Rule::in(['camions', 'remorques', 'ensembles_pl', 'vl'])],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:vehicle_types,id'],
            'name' => ['nullable', 'string', 'max:150'],
            'registration' => ['nullable', 'string', 'max:50'],
            'code_zeendoc' => ['nullable', 'string', 'max:120'],
            'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
            'tractor_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'benne_ids' => ['nullable', 'array'],
            'benne_ids.*' => ['integer', 'exists:vehicles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $options = $this->vehicleFormOptions();
        $section = (string) $validated['section'];
        $isEnsemble = $section === 'ensembles_pl';

        if ($isEnsemble) {
            $ensembleTypeId = (int) collect($options['type_options']['ensembles_pl'] ?? [])
                ->pluck('id')
                ->first();

            if ($ensembleTypeId <= 0) {
                throw ValidationException::withMessages([
                    'section' => 'Type "Ensemble PL" introuvable.',
                ]);
            }

            $validated['vehicle_type_id'] = $ensembleTypeId;
            $validated['tractor_vehicle_id'] = ! empty($validated['tractor_vehicle_id']) ? (int) $validated['tractor_vehicle_id'] : null;
            $validated['benne_ids'] = collect($validated['benne_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values()->all();

            if ($validated['tractor_vehicle_id']) {
                $tractorAllowed = collect($options['tractor_candidates'] ?? [])
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->contains((int) $validated['tractor_vehicle_id']);

                if (! $tractorAllowed) {
                    throw ValidationException::withMessages([
                        'tractor_vehicle_id' => 'Le camion sélectionné doit être un tracteur.',
                    ]);
                }
            }

            if (! empty($validated['benne_ids'])) {
                $allowedBenneIds = collect($options['remorque_candidates'] ?? [])
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id);

                foreach ($validated['benne_ids'] as $id) {
                    if (! $allowedBenneIds->contains((int) $id)) {
                        throw ValidationException::withMessages([
                            'benne_ids' => 'Chaque remorque doit être de type semi-remorque.',
                        ]);
                    }
                }
            }

            if ($currentVehicle && $validated['tractor_vehicle_id'] && (int) $validated['tractor_vehicle_id'] === (int) $currentVehicle->id) {
                throw ValidationException::withMessages([
                    'tractor_vehicle_id' => 'Un ensemble ne peut pas se référencer lui-même.',
                ]);
            }

            if ($currentVehicle && in_array((int) $currentVehicle->id, $validated['benne_ids'], true)) {
                throw ValidationException::withMessages([
                    'benne_ids' => 'Un ensemble ne peut pas se référencer lui-même.',
                ]);
            }

            return $validated;
        }

        if (empty($validated['vehicle_type_id'])) {
            throw ValidationException::withMessages([
                'vehicle_type_id' => 'Le type est obligatoire.',
            ]);
        }

        $allowedTypeIds = collect($options['type_options'][$section] ?? [])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if (! $allowedTypeIds->contains((int) $validated['vehicle_type_id'])) {
            throw ValidationException::withMessages([
                'vehicle_type_id' => 'Le type ne correspond pas à cette section.',
            ]);
        }

        $validated['vehicle_type_id'] = (int) $validated['vehicle_type_id'];
        $validated['tractor_vehicle_id'] = null;
        $validated['benne_ids'] = [];

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{vehicle: array<string, mixed>, is_ensemble: bool, benne_ids: array<int>}
     */
    private function makeVehiclePayloadFromSection(array $validated, ?Vehicle $currentVehicle = null): array
    {
        $section = (string) ($validated['section'] ?? '');
        $isEnsemble = $section === 'ensembles_pl';
        $benneIds = collect($validated['benne_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $tractorVehicleId = ! empty($validated['tractor_vehicle_id']) ? (int) $validated['tractor_vehicle_id'] : null;

        if ($currentVehicle && $tractorVehicleId && $tractorVehicleId === (int) $currentVehicle->id) {
            throw ValidationException::withMessages([
                'tractor_vehicle_id' => 'Un ensemble ne peut pas se référencer lui-même.',
            ]);
        }

        if ($currentVehicle && in_array((int) $currentVehicle->id, $benneIds, true)) {
            throw ValidationException::withMessages([
                'benne_ids' => 'Un ensemble ne peut pas se référencer lui-même.',
            ]);
        }

        return [
            'vehicle' => [
                'vehicle_type_id' => (int) $validated['vehicle_type_id'],
                'name' => $this->nullableString($validated['name'] ?? null),
                'registration' => $isEnsemble ? null : $this->nullableString($validated['registration'] ?? null),
                'code_zeendoc' => $isEnsemble ? null : $this->nullableString($validated['code_zeendoc'] ?? null),
                'depot_id' => ! empty($validated['depot_id']) ? (int) $validated['depot_id'] : null,
                'tractor_vehicle_id' => $isEnsemble ? $tractorVehicleId : null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'is_rental' => false,
                'garage_id' => null,
            ],
            'is_ensemble' => $isEnsemble,
            'benne_ids' => $isEnsemble ? $benneIds : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicleFormOptions(): array
    {
        $types = VehicleType::query()
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['id', 'code', 'label'])
            ->map(fn (VehicleType $type): array => [
                'id' => $type->id,
                'code' => strtolower((string) $type->code),
                'label' => (string) $type->label,
            ])
            ->values();

        $depots = Depot::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Depot $depot): array => [
                'id' => $depot->id,
                'name' => (string) $depot->name,
            ])
            ->values()
            ->all();

        $tractorCandidates = Vehicle::query()
            ->with('type:id,code,label')
            ->orderByRaw('COALESCE(name, registration) asc')
            ->get(['id', 'name', 'registration', 'vehicle_type_id'])
            ->filter(function (Vehicle $vehicle): bool {
                return $this->isCamionType((string) ($vehicle->type?->code ?? ''), (string) ($vehicle->type?->label ?? ''))
                    && strtolower((string) ($vehicle->type?->code ?? '')) === 'tracteur';
            })
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'label' => trim((string) $vehicle->name) !== ''
                    ? trim((string) $vehicle->name).(trim((string) $vehicle->registration) !== '' ? ' • '.trim((string) $vehicle->registration) : '')
                    : trim((string) $vehicle->registration),
            ])
            ->values()
            ->all();

        $remorqueCandidates = Vehicle::query()
            ->with('type:id,code,label')
            ->orderByRaw('COALESCE(name, registration) asc')
            ->get(['id', 'name', 'registration', 'vehicle_type_id'])
            ->filter(fn (Vehicle $vehicle): bool => $this->isRemorqueType((string) ($vehicle->type?->code ?? ''), (string) ($vehicle->type?->label ?? '')))
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'label' => trim((string) $vehicle->name) !== ''
                    ? trim((string) $vehicle->name).(trim((string) $vehicle->registration) !== '' ? ' • '.trim((string) $vehicle->registration) : '')
                    : trim((string) $vehicle->registration),
            ])
            ->values()
            ->all();

        $typeOptions = [
            'camions' => $types->filter(fn (array $type): bool => $this->isCamionType((string) ($type['code'] ?? ''), (string) ($type['label'] ?? '')))->values()->all(),
            'remorques' => $types->filter(fn (array $type): bool => $this->isRemorqueType((string) ($type['code'] ?? ''), (string) ($type['label'] ?? '')))->values()->all(),
            'ensembles_pl' => $types->filter(fn (array $type): bool => $this->isEnsembleType((string) ($type['code'] ?? ''), (string) ($type['label'] ?? '')))->values()->all(),
            'vl' => $types->filter(fn (array $type): bool => $this->isVlType((string) ($type['code'] ?? ''), (string) ($type['label'] ?? '')))->values()->all(),
        ];

        return [
            'type_options' => $typeOptions,
            'depots' => $depots,
            'tractor_candidates' => $tractorCandidates,
            'remorque_candidates' => $remorqueCandidates,
        ];
    }

    private function isCamionType(string $code, string $label): bool
    {
        $code = strtolower(trim($code));
        $label = strtolower(trim($label));

        return in_array($code, ['tracteur', 'porteur', 'camion'], true)
            || str_contains($label, 'tracteur')
            || str_contains($label, 'porteur');
    }

    private function isRemorqueType(string $code, string $label): bool
    {
        $code = strtolower(trim($code));
        $label = strtolower(trim($label));

        return $code === 'benne'
            || str_contains($label, 'benne')
            || str_contains($label, 'remorque');
    }

    private function isEnsembleType(string $code, string $label): bool
    {
        $code = strtolower(trim($code));
        $label = strtolower(trim($label));

        return $code === 'ensemble_pl' || str_contains($label, 'ensemble pl');
    }

    private function isVlType(string $code, string $label): bool
    {
        $code = strtolower(trim($code));
        $label = strtolower(trim($label));

        return $code === 'vl' || $label === 'vl';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function ensureDepotManagePermission(?User $user): void
    {
        if (! $user || ! $this->accessManager->can($user, 'task.data.depots.manage')) {
            abort(403);
        }
    }

    private function ensureVehicleManagePermission(?User $user): void
    {
        $this->ensureDepotManagePermission($user);
    }

    private function can(?User $user, string $ability): bool
    {
        return $user instanceof User && $this->accessManager->can($user, $ability);
    }

    private function photoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/'.$path;
    }

    private function usersHasColumn(string $column): bool
    {
        return Schema::hasColumn('users', $column);
    }
}
