<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Benne;
use App\Models\Depot;
use App\Models\EnsemblePl;
use App\Models\Garage;
use App\Models\PoidsLourd;
use App\Models\Sector;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EntitiesController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', VehicleType::class);

        return Inertia::render('Admin/Entities/Index', [
            'vehicleTypes' => VehicleType::query()
                ->withCount('vehicles')
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->map(fn (VehicleType $type): array => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'label' => $type->label,
                    'is_active' => (bool) $type->is_active,
                    'sort_order' => (int) $type->sort_order,
                    'vehicles_count' => (int) $type->vehicles_count,
                ])
                ->values(),
            'depots' => Depot::query()
                ->withCount('vehicles')
                ->with(['entityFiles' => fn ($query) => $query->latest()->with('uploader:id,name,first_name,last_name')])
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
                    'gps_url' => $this->depotGpsUrl($depot),
                    'files' => $this->entityFilesPayload($depot, 'depot'),
                    'files_routes' => [
                        'upload' => route('admin.entities.depots.files.store', $depot),
                    ],
                ])
                ->values(),
            'vehicles' => Vehicle::query()
                ->with([
                    'type:id,label,code',
                    'depot:id,name',
                    'sector:id,name',
                    'driver:id,name,first_name,last_name,sector_id',
                    'driverCarb:id,name,first_name,last_name,sector_id',
                    'garage:id,name',
                    'tractor:id,name,registration',
                    'bennes:id,name,registration',
                    'entityFiles' => fn ($query) => $query->latest()->with('uploader:id,name,first_name,last_name'),
                ])
                ->orderBy('vehicle_type_id')
                ->orderBy('name')
                ->orderBy('registration')
                ->get()
                ->map(fn (Vehicle $vehicle): array => [
                    'id' => $vehicle->id,
                    'vehicle_type_id' => $vehicle->vehicle_type_id,
                    'vehicle_type_label' => $vehicle->type?->label,
                    'vehicle_type_code' => $vehicle->type?->code,
                    'mode' => $vehicle->type?->code === 'ensemble_pl' ? 'ensemble_pl' : 'vehicle',
                    'name' => $vehicle->name,
                    'registration' => $vehicle->registration,
                    'code_zeendoc' => $vehicle->code_zeendoc,
                    'driver_user_id' => $vehicle->driver_user_id,
                    'driver_name' => $vehicle->driver?->name,
                    'driver_sector_id' => $vehicle->driver?->sector_id,
                    'driver_carb_user_id' => $vehicle->driver_carb_user_id,
                    'driver_carb_name' => $vehicle->driverCarb?->name,
                    'driver_carb_sector_id' => $vehicle->driverCarb?->sector_id,
                    'depot_id' => $vehicle->depot_id,
                    'depot_name' => $vehicle->depot?->name,
                    'sector_id' => $vehicle->sector_id,
                    'sector_name' => $vehicle->sector?->name,
                    'is_rental' => (bool) $vehicle->is_rental,
                    'garage_id' => $vehicle->garage_id,
                    'garage_name' => $vehicle->garage?->name,
                    'tractor_vehicle_id' => $vehicle->tractor_vehicle_id,
                    'tractor_label' => $vehicle->tractor?->name ?: $vehicle->tractor?->registration,
                    'tractor_name' => $vehicle->tractor?->name,
                    'tractor_registration' => $vehicle->tractor?->registration,
                    'benne_ids' => $vehicle->bennes->pluck('id')->all(),
                    'bennes' => $vehicle->bennes
                        ->map(fn (Vehicle $benne): array => [
                            'id' => $benne->id,
                            'name' => $benne->name,
                            'registration' => $benne->registration,
                            'label' => $benne->name ?: $benne->registration ?: 'Véhicule #'.$benne->id,
                        ])
                        ->values()
                        ->all(),
                    'is_active' => (bool) $vehicle->is_active,
                    'files' => $this->entityFilesPayload($vehicle, 'vehicle'),
                    'files_routes' => [
                        'upload' => route('admin.entities.vehicles.files.store', $vehicle),
                    ],
                ])
                ->values(),
            'garages' => Garage::query()
                ->withCount('vehicles')
                ->with(['entityFiles' => fn ($query) => $query->latest()->with('uploader:id,name,first_name,last_name')])
                ->orderBy('name')
                ->get()
                ->map(fn (Garage $garage): array => [
                    'id' => $garage->id,
                    'name' => $garage->name,
                    'address_line1' => $garage->address_line1,
                    'address_line2' => $garage->address_line2,
                    'postal_code' => $garage->postal_code,
                    'city' => $garage->city,
                    'country' => $garage->country,
                    'phone' => $garage->phone,
                    'email' => $garage->email,
                    'is_active' => (bool) $garage->is_active,
                    'vehicles_count' => (int) $garage->vehicles_count,
                    'files' => $this->entityFilesPayload($garage, 'garage'),
                    'files_routes' => [
                        'upload' => route('admin.entities.garages.files.store', $garage),
                    ],
                ])
                ->values(),
            'lookups' => [
                'vehicle_types' => VehicleType::query()
                    ->orderBy('sort_order')
                    ->orderBy('label')
                    ->get(['id', 'label', 'code', 'is_active']),
                'depots' => Depot::query()->orderBy('name')->get(['id', 'name', 'is_active']),
                'garages' => Garage::query()->orderBy('name')->get(['id', 'name', 'is_active']),
                'sectors' => Sector::query()->orderBy('name')->get(['id', 'name']),
                'users' => User::query()
                    ->with('sector:id,name')
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'first_name',
                        'last_name',
                        'email',
                        'phone',
                        'mobile_phone',
                        'internal_number',
                        'depot_address',
                        'photo_path',
                        'sector_id',
                    ])
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'mobile_phone' => $user->mobile_phone,
                        'internal_number' => $user->internal_number,
                        'depot_address' => $user->depot_address,
                        'photo_path' => $user->photo_path,
                        'sector_id' => $user->sector_id,
                        'sector_name' => $user->sector?->name,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function storePoidsLourd(Request $request): RedirectResponse
    {
        PoidsLourd::query()->create($this->validatedAssetBasePayload($request, 'poids_lourds'));

        return back()->with('status', 'Poids lourd enregistré.');
    }

    public function updatePoidsLourd(Request $request, PoidsLourd $poidsLourd): RedirectResponse
    {
        $poidsLourd->update($this->validatedAssetBasePayload($request, 'poids_lourds', $poidsLourd->id));

        return back()->with('status', 'Poids lourd enregistré.');
    }

    public function destroyPoidsLourd(PoidsLourd $poidsLourd): RedirectResponse
    {
        $poidsLourd->delete();

        return back()->with('status', 'Poids lourd supprimé.');
    }

    public function storeBenne(Request $request): RedirectResponse
    {
        Benne::query()->create($this->validatedAssetBasePayload($request, 'bennes'));

        return back()->with('status', 'Benne enregistrée.');
    }

    public function updateBenne(Request $request, Benne $benne): RedirectResponse
    {
        $benne->update($this->validatedAssetBasePayload($request, 'bennes', $benne->id));

        return back()->with('status', 'Benne enregistrée.');
    }

    public function destroyBenne(Benne $benne): RedirectResponse
    {
        $benne->delete();

        return back()->with('status', 'Benne supprimée.');
    }

    public function storeEnsemblePl(Request $request): RedirectResponse
    {
        EnsemblePl::query()->create($this->validatedAssetBasePayload($request, 'ensemble_pls'));

        return back()->with('status', 'Ensemble PL enregistré.');
    }

    public function updateEnsemblePl(Request $request, EnsemblePl $ensemblePl): RedirectResponse
    {
        $ensemblePl->update($this->validatedAssetBasePayload($request, 'ensemble_pls', $ensemblePl->id));

        return back()->with('status', 'Ensemble PL enregistré.');
    }

    public function destroyEnsemblePl(EnsemblePl $ensemblePl): RedirectResponse
    {
        $ensemblePl->delete();

        return back()->with('status', 'Ensemble PL supprimé.');
    }

    public function storeVehicleType(Request $request): RedirectResponse
    {
        $this->authorize('create', VehicleType::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:vehicle_types,code'],
            'label' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        VehicleType::query()->create([
            'code' => strtolower((string) $validated['code']),
            'label' => $validated['label'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return back()->with('status', 'Type véhicule enregistré.');
    }

    public function updateVehicleType(Request $request, VehicleType $vehicleType): RedirectResponse
    {
        $this->authorize('update', $vehicleType);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:80',
                'alpha_dash',
                Rule::unique('vehicle_types', 'code')->ignore($vehicleType->id),
            ],
            'label' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $vehicleType->update([
            'code' => strtolower((string) $validated['code']),
            'label' => $validated['label'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return back()->with('status', 'Type véhicule enregistré.');
    }

    public function destroyVehicleType(VehicleType $vehicleType): RedirectResponse
    {
        $this->authorize('delete', $vehicleType);

        if ($vehicleType->vehicles()->exists()) {
            return back()->withErrors([
                'vehicle_type' => 'Type de véhicule utilisé par un ou plusieurs véhicules.',
            ]);
        }

        $vehicleType->delete();

        return back()->with('status', 'Type véhicule supprimé.');
    }

    public function storeDepot(Request $request): RedirectResponse
    {
        $this->authorize('create', Depot::class);

        $depot = Depot::query()->create($this->validatedDepotPayload($request));

        return back()->with('status', 'Dépôt enregistré.');
    }

    public function updateDepot(Request $request, Depot $depot): RedirectResponse
    {
        $this->authorize('update', $depot);

        $depot->update($this->validatedDepotPayload($request));

        return back()->with('status', 'Dépôt enregistré.');
    }

    public function destroyDepot(Depot $depot): RedirectResponse
    {
        $this->authorize('delete', $depot);

        if ($depot->vehicles()->exists()) {
            return back()->withErrors([
                'depot' => 'Dépôt utilisé par un ou plusieurs véhicules.',
            ]);
        }

        $depot->delete();

        return back()->with('status', 'Dépôt supprimé.');
    }

    public function storeGarage(Request $request): RedirectResponse
    {
        $this->authorize('create', Garage::class);

        Garage::query()->create($this->validatedGaragePayload($request));

        return back()->with('status', 'Garage enregistré.');
    }

    public function updateGarage(Request $request, Garage $garage): RedirectResponse
    {
        $this->authorize('update', $garage);

        $garage->update($this->validatedGaragePayload($request));

        return back()->with('status', 'Garage enregistré.');
    }

    public function destroyGarage(Garage $garage): RedirectResponse
    {
        $this->authorize('delete', $garage);

        if ($garage->vehicles()->exists()) {
            return back()->withErrors([
                'garage' => 'Garage utilisé par un ou plusieurs véhicules.',
            ]);
        }

        $garage->delete();

        return back()->with('status', 'Garage supprimé.');
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $this->authorize('create', Vehicle::class);

        $data = $this->validatedVehiclePayload($request);
        $vehicle = Vehicle::query()->create($data['vehicle']);
        $vehicle->bennes()->sync($data['benne_ids']);

        return back()->with('status', 'Véhicule enregistré.');
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        $data = $this->validatedVehiclePayload($request, $vehicle);
        $vehicle->update($data['vehicle']);
        $vehicle->bennes()->sync($data['benne_ids']);

        return back()->with('status', 'Véhicule enregistré.');
    }

    public function destroyVehicle(Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('delete', $vehicle);

        $vehicle->delete();

        return back()->with('status', 'Véhicule supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDepotPayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $validated['name'],
            'address_line1' => $this->nullableString($validated['address_line1'] ?? null),
            'address_line2' => $this->nullableString($validated['address_line2'] ?? null),
            'postal_code' => $this->nullableString($validated['postal_code'] ?? null),
            'city' => $this->nullableString($validated['city'] ?? null),
            'country' => strtoupper((string) ($validated['country'] ?? 'FR')),
            'phone' => $this->nullableString($validated['phone'] ?? null),
            'email' => $this->nullableString($validated['email'] ?? null),
            'gps_lat' => Arr::exists($validated, 'gps_lat') ? $validated['gps_lat'] : null,
            'gps_lng' => Arr::exists($validated, 'gps_lng') ? $validated['gps_lng'] : null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedGaragePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $validated['name'],
            'address_line1' => $this->nullableString($validated['address_line1'] ?? null),
            'address_line2' => $this->nullableString($validated['address_line2'] ?? null),
            'postal_code' => $this->nullableString($validated['postal_code'] ?? null),
            'city' => $this->nullableString($validated['city'] ?? null),
            'country' => strtoupper((string) ($validated['country'] ?? 'FR')),
            'phone' => $this->nullableString($validated['phone'] ?? null),
            'email' => $this->nullableString($validated['email'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }

    /**
     * @return array{vehicle: array<string, mixed>, benne_ids: array<int>}
     */
    private function validatedVehiclePayload(Request $request, ?Vehicle $currentVehicle = null): array
    {
        $validated = $request->validate([
            'vehicle_mode' => ['required', 'string', Rule::in(['vehicle', 'ensemble_pl'])],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:vehicle_types,id'],
            'name' => ['nullable', 'string', 'max:150'],
            'registration' => ['nullable', 'string', 'max:50'],
            'code_zeendoc' => ['nullable', 'string', 'max:120'],
            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'driver_carb_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
            'is_rental' => ['nullable', 'boolean'],
            'garage_id' => ['nullable', 'integer', 'exists:garages,id'],
            'tractor_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'benne_ids' => ['nullable', 'array'],
            'benne_ids.*' => ['integer', 'exists:vehicles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $mode = $validated['vehicle_mode'];
        $isVehicleMode = $mode === 'vehicle';
        $isRental = (bool) ($validated['is_rental'] ?? false);
        $benneIds = collect($validated['benne_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $vehicleTypeId = null;

        if ($isVehicleMode) {
            if (empty($validated['vehicle_type_id'])) {
                throw ValidationException::withMessages([
                    'vehicle_type_id' => 'Le type est obligatoire pour un véhicule.',
                ]);
            }

            $selectedType = VehicleType::query()->find((int) $validated['vehicle_type_id']);

            if ($selectedType?->code === 'ensemble_pl') {
                throw ValidationException::withMessages([
                    'vehicle_type_id' => 'Utilisez le mode "Ensemble PL" pour ce type.',
                ]);
            }

            $vehicleTypeId = (int) $validated['vehicle_type_id'];
            $benneIds = [];

            if ($isRental && empty($validated['garage_id'])) {
                throw ValidationException::withMessages([
                    'garage_id' => 'Le garage est obligatoire si le véhicule est en location.',
                ]);
            }
        } else {
            $vehicleTypeId = (int) (VehicleType::query()->where('code', 'ensemble_pl')->value('id') ?? 0);

            if ($vehicleTypeId === 0) {
                throw ValidationException::withMessages([
                    'vehicle_mode' => 'Le type véhicule "ensemble_pl" doit exister.',
                ]);
            }
        }

        $driver = ! empty($validated['driver_user_id'])
            ? User::query()->select(['id', 'sector_id'])->find((int) $validated['driver_user_id'])
            : null;
        $driverCarb = ! empty($validated['driver_carb_user_id'])
            ? User::query()->select(['id', 'sector_id'])->find((int) $validated['driver_carb_user_id'])
            : null;

        if ($driver && $driverCarb && $driver->sector_id && $driverCarb->sector_id && $driver->sector_id !== $driverCarb->sector_id) {
            throw ValidationException::withMessages([
                'driver_carb_user_id' => 'Le chauffeur carb doit être du même secteur que le chauffeur.',
            ]);
        }

        $sectorId = $driver?->sector_id ?? $driverCarb?->sector_id ?? null;
        $tractorVehicleId = Arr::exists($validated, 'tractor_vehicle_id') ? ($validated['tractor_vehicle_id'] ?: null) : null;

        if ($currentVehicle && $tractorVehicleId && (int) $tractorVehicleId === (int) $currentVehicle->id) {
            throw ValidationException::withMessages([
                'tractor_vehicle_id' => 'Un ensemble ne peut pas se référencer lui-même comme camion.',
            ]);
        }

        if ($currentVehicle && in_array((int) $currentVehicle->id, $benneIds, true)) {
            throw ValidationException::withMessages([
                'benne_ids' => 'Un ensemble ne peut pas se référencer lui-même comme semi-remorque.',
            ]);
        }

        $vehiclePayload = [
            'vehicle_type_id' => $vehicleTypeId,
            'name' => $this->nullableString($validated['name'] ?? null),
            'registration' => $isVehicleMode ? $this->nullableString($validated['registration'] ?? null) : null,
            'code_zeendoc' => $isVehicleMode ? $this->nullableString($validated['code_zeendoc'] ?? null) : null,
            'driver_user_id' => Arr::exists($validated, 'driver_user_id') ? ($validated['driver_user_id'] ?: null) : null,
            'driver_carb_user_id' => Arr::exists($validated, 'driver_carb_user_id') ? ($validated['driver_carb_user_id'] ?: null) : null,
            'depot_id' => Arr::exists($validated, 'depot_id') ? ($validated['depot_id'] ?: null) : null,
            'sector_id' => $sectorId,
            'is_rental' => $isVehicleMode ? $isRental : false,
            'garage_id' => $isVehicleMode && $isRental && Arr::exists($validated, 'garage_id') ? ($validated['garage_id'] ?: null) : null,
            'tractor_vehicle_id' => $isVehicleMode ? null : $tractorVehicleId,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];

        return [
            'vehicle' => $vehiclePayload,
            'benne_ids' => $isVehicleMode ? [] : $benneIds,
        ];
    }

    private function decodeAttributesJson(?string $json): array|false|null
    {
        $json = $this->nullableString($json);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || (! is_array($decoded) && $decoded !== null)) {
            return false;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mappedAssetBases($rows): array
    {
        return $rows->map(function ($row): array {
            return [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'registration' => $row->registration,
                'depot_id' => $row->depot_id,
                'depot_name' => $row->depot?->name,
                'sector_id' => $row->sector_id,
                'sector_name' => $row->sector?->name,
                'is_active' => (bool) $row->is_active,
                'attributes' => $row->attributes ?? [],
                'attributes_json' => $row->attributes
                    ? json_encode($row->attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : '',
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAssetBasePayload(Request $request, string $table, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:80',
                'alpha_dash',
                Rule::unique($table, 'code')->ignore($ignoreId),
            ],
            'name' => ['nullable', 'string', 'max:150'],
            'registration' => ['nullable', 'string', 'max:50'],
            'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'is_active' => ['nullable', 'boolean'],
            'attributes_json' => ['nullable', 'string'],
        ]);

        $attributes = $this->decodeAttributesJson($validated['attributes_json'] ?? null);

        if ($attributes === false) {
            throw ValidationException::withMessages([
                'asset_attributes_json' => 'Le JSON des attributs est invalide.',
            ]);
        }

        return [
            'code' => $this->nullableString(isset($validated['code']) ? strtolower((string) $validated['code']) : null),
            'name' => $this->nullableString($validated['name'] ?? null),
            'registration' => $this->nullableString($validated['registration'] ?? null),
            'depot_id' => Arr::exists($validated, 'depot_id') ? ($validated['depot_id'] ?: null) : null,
            'sector_id' => Arr::exists($validated, 'sector_id') ? ($validated['sector_id'] ?: null) : null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'attributes' => $attributes,
        ];
    }

    private function nullableString(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function depotGpsUrl(Depot $depot): ?string
    {
        if ($depot->gps_lat !== null && $depot->gps_lng !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='.$depot->gps_lat.','.$depot->gps_lng;
        }

        $parts = array_filter([
            $depot->address_line1,
            $depot->address_line2,
            trim((string) (($depot->postal_code ?? '').' '.($depot->city ?? ''))),
            $depot->country,
        ]);

        if ($parts === []) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode(implode(', ', $parts));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entityFilesPayload(Model $entity, string $entityKey): array
    {
        if (! method_exists($entity, 'entityFiles')) {
            return [];
        }

        $files = $entity->relationLoaded('entityFiles')
            ? $entity->getRelation('entityFiles')
            : $entity->entityFiles()->latest()->with('uploader:id,name,first_name,last_name')->get();

        return $files->map(function ($file) use ($entity, $entityKey): array {
            $uploaderName = trim((string) (($file->uploader?->first_name ?? '').' '.($file->uploader?->last_name ?? '')));

            return [
                'id' => $file->id,
                'name' => $file->display_name ?: $file->original_name,
                'original_name' => $file->original_name,
                'size_bytes' => (int) $file->size_bytes,
                'mime_type' => $file->mime_type,
                'extension' => $file->extension,
                'created_at' => $file->created_at?->toIso8601String(),
                'created_at_label' => $file->created_at?->format('d/m/Y H:i'),
                'uploader' => $uploaderName !== '' ? $uploaderName : ($file->uploader?->name ?? 'Inconnu'),
                'preview_url' => route("admin.entities.{$entityKey}s.files.preview", [$entityKey => $entity, 'entityFile' => $file]),
                'download_url' => route("admin.entities.{$entityKey}s.files.download", [$entityKey => $entity, 'entityFile' => $file]),
                'delete_url' => route("admin.entities.{$entityKey}s.files.destroy", [$entityKey => $entity, 'entityFile' => $file]),
            ];
        })->values()->all();
    }
}
