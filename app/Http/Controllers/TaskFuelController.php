<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\TaskFuelDelivery;
use App\Models\TaskFuelNewClient;
use App\Models\TaskFuelOption;
use App\Models\TaskFuelRecurring;
use App\Models\TaskTiersRecord;
use App\Models\TaskTiersImportConfig;
use App\Models\User;
use Carbon\Carbon;
use App\Support\Access\AccessManager;
use App\Support\Tiers\TiersColumnFormat;
use App\Support\Tiers\TiersSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TaskFuelController extends Controller
{
    public function __construct(private readonly AccessManager $accessManager)
    {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $filters = $request->query->has('filters')
            ? (array) $request->query('filters', [])
            : $this->defaultFuelFilters();
        $sort = (array) $request->query('sort', []);

        $deliveriesQuery = TaskFuelDelivery::query()
            ->with([
                'createdBy:id,name',
                'deliveryDriver:id,name',
                'deliveryPointedBy:id,name',
            ]);

        $this->applyFuelSearch($deliveriesQuery, $search);
        $this->applyFuelFilters($deliveriesQuery, $filters);
        $this->applyFuelSort($deliveriesQuery, $sort);

        $this->generateRecurringDeliveries();

        $deliveries = $deliveriesQuery->get();

        return Inertia::render('Tasks/Fuel/Index', [
            'permissions' => [
                'can_update' => (bool) ($user && $this->accessManager->can($user, 'task.fuel.update')),
                'can_delete' => (bool) ($user && $this->accessManager->can($user, 'task.fuel.delete')),
                'can_manage_new_clients' => (bool) ($user
                    && $this->accessManager->can($user, 'task.fuel.update')
                    && $this->accessManager->can($user, 'task.tiers.update')),
                'can_manage_recurrings' => (bool) ($user && $this->accessManager->can($user, 'task.fuel.update')),
            ],
            'deliveries' => [
                'data' => $deliveries
                    ->map(fn (TaskFuelDelivery $delivery): array => $this->fuelDeliveryPayload($delivery))
                    ->values(),
                'meta' => [
                    'total' => $deliveries->count(),
                ],
            ],
            'options' => $this->fuelOptionsPayload(),
            'depots' => Depot::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Depot $depot): array => ['id' => $depot->id, 'name' => $depot->name])
                ->values(),
            'fuelDrivers' => $this->fuelDriversPayload(),
            'recurrings' => ($user && $this->accessManager->can($user, 'task.fuel.update'))
                ? $this->recurringsPayload()
                : [],
            'monthlyStats' => $this->computeMonthlyStats(),
            'newClients' => ($user
                && $this->accessManager->can($user, 'task.fuel.update')
                && $this->accessManager->can($user, 'task.tiers.update'))
                ? $this->pendingFuelNewClientsPayload()
                : [],
            'query' => [
                'search' => $search,
                'filters' => $this->normalizeFuelFiltersForFrontend($filters),
                'sort' => [
                    'key' => (string) ($sort['key'] ?? ''),
                    'direction' => in_array(($sort['direction'] ?? ''), ['asc', 'desc'], true) ? $sort['direction'] : '',
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tiers_record_id' => ['nullable', 'integer', 'exists:task_tiers_records,id'],
            'client' => ['required', 'array'],
            'client.code' => ['nullable', 'string', 'max:255'],
            'client.name' => ['nullable', 'string', 'max:255'],
            'client.phone' => ['nullable', 'string', 'max:255'],
            'client.address' => ['nullable', 'string', 'max:5000'],
            'client.postal_code' => ['nullable', 'string', 'max:255'],
            'client.city' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.fuel_type' => ['nullable', 'string', 'max:255'],
            'orders.*.volume' => ['nullable', 'integer', 'min:0'],
            'orders.*.urgent' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'max:50000'],
        ]);

        $client = (array) $validated['client'];
        $orders = collect((array) $validated['orders'])
            ->filter(fn (array $order): bool => trim((string) ($order['fuel_type'] ?? '')) !== '' || ($order['volume'] ?? null) !== null)
            ->values();

        if ($orders->isEmpty()) {
            return response()->json([
                'message' => 'Ajoutez au moins une commande carburant.',
            ], 422);
        }

        $user = $request->user();

        $deliveries = $orders
            ->map(function (array $order) use ($client, $validated, $user): TaskFuelDelivery {
                return TaskFuelDelivery::query()->create([
                    'delivery_date' => null,
                    'site' => trim((string) ($validated['site'] ?? '')) ?: null,
                    'tiers_record_id' => $validated['tiers_record_id'] ?? null,
                    'code_tiers' => trim((string) ($client['code'] ?? '')) ?: null,
                    'client_name' => trim((string) ($client['name'] ?? '')) ?: null,
                    'phone' => trim((string) ($client['phone'] ?? '')) ?: null,
                    'address' => trim((string) ($client['address'] ?? '')) ?: null,
                    'postal_code' => trim((string) ($client['postal_code'] ?? '')) ?: null,
                    'city' => trim((string) ($client['city'] ?? '')) ?: null,
                    'fuel_type' => trim((string) ($order['fuel_type'] ?? '')) ?: null,
                    'volume_liters' => isset($order['volume']) ? (int) $order['volume'] : null,
                    'comment' => trim((string) ($validated['comment'] ?? '')) ?: null,
                    'urgent' => (bool) ($order['urgent'] ?? false),
                    'created_by_user_id' => $user?->id,
                    'updated_by_user_id' => $user?->id,
                ]);
            })
            ->each(fn (TaskFuelDelivery $delivery) => $delivery->setRelation('createdBy', $user))
            ->map(fn (TaskFuelDelivery $delivery): array => $this->fuelDeliveryPayload($delivery))
            ->values();

        return response()->json([
            'message' => 'Livraison carburant ajoutée.',
            'deliveries' => $deliveries,
        ], 201);
    }

    public function update(Request $request, TaskFuelDelivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'delivery_date' => ['nullable', 'date'],
            'site' => ['nullable', 'string', 'max:255'],
            'code_tiers' => ['nullable', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'fuel_type' => ['nullable', 'string', 'max:255'],
            'volume_liters' => ['nullable', 'integer', 'min:0'],
            'comment' => ['nullable', 'string', 'max:50000'],
            'urgent' => ['nullable', 'boolean'],
        ]);

        $updates = [];

        if (array_key_exists('delivery_date', $validated)) {
            $updates['delivery_date'] = $validated['delivery_date'] ?: null;
        }

        foreach (['site', 'code_tiers', 'client_name', 'phone', 'address', 'postal_code', 'city', 'fuel_type', 'comment'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = trim((string) ($validated[$field] ?? '')) ?: null;
            }
        }

        if (array_key_exists('volume_liters', $validated)) {
            $updates['volume_liters'] = $validated['volume_liters'] !== null ? (int) $validated['volume_liters'] : null;
        }

        if (array_key_exists('urgent', $validated)) {
            $updates['urgent'] = (bool) $validated['urgent'];
        }

        if ($updates !== []) {
            $updates['updated_by_user_id'] = $request->user()?->id;
            $delivery->forceFill($updates)->save();
        }

        $delivery->load('createdBy:id,name');

        return response()->json([
            'message' => 'Livraison carburant mise à jour.',
            'delivery' => $this->fuelDeliveryPayload($delivery),
        ]);
    }

    public function destroy(Request $request, TaskFuelDelivery $delivery): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $this->accessManager->can($user, 'task.fuel.delete'),
            403,
            'Vous n\'avez pas la permission de supprimer cette livraison.'
        );

        $delivery->delete();

        return response()->json([
            'message' => 'Livraison supprimée.',
        ]);
    }

    public function point(Request $request, TaskFuelDelivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'actual_delivery_date' => ['required', 'date'],
            'delivered_driver_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $delivery->forceFill([
            'actual_delivery_date' => $validated['actual_delivery_date'],
            'delivered_driver_user_id' => (int) $validated['delivered_driver_user_id'],
            'delivered_at' => now(),
            'delivered_pointed_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        $delivery->load([
            'createdBy:id,name',
            'deliveryDriver:id,name',
            'deliveryPointedBy:id,name',
        ]);

        return response()->json([
            'message' => 'Livraison pointée.',
            'delivery' => $this->fuelDeliveryPayload($delivery),
        ]);
    }

    public function tiersSearch(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }

        $terms = collect(preg_split('/\s+/', TiersSearchText::normalize($search)) ?: [])
            ->map(fn (string $term): string => trim($term))
            ->filter()
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            return response()->json([]);
        }

        $records = TaskTiersRecord::query();

        $terms->each(function (string $term) use ($records): void {
            $needle = '%'.$this->escapeLike($term).'%';
            $records->where(function (Builder $query) use ($needle): void {
                $query
                    ->whereRaw("LOWER(COALESCE(primary_identifier, '')) LIKE ?", [$needle])
                    ->orWhereRaw("LOWER(COALESCE(reference_value, '')) LIKE ?", [$needle])
                    ->orWhereRaw("COALESCE(search_text, '') LIKE ?", [$needle])
                    ->orWhere(function (Builder $fallback) use ($needle): void {
                        $fallback
                            ->where(function (Builder $emptySearchText): void {
                                $emptySearchText
                                    ->whereNull('search_text')
                                    ->orWhere('search_text', '');
                            })
                            ->whereRaw("LOWER(CAST(data AS CHAR)) LIKE ?", [$needle]);
                    });
            });
        });

        $records = $records
            ->latest('id')
            ->limit(12)
            ->get(['id', 'primary_identifier', 'reference_value', 'data']);

        return response()->json(
            $records
                ->map(fn (TaskTiersRecord $record): array => $this->fuelTiersResult($record))
                ->values()
        );
    }

    public function storeNewClient(Request $request): JsonResponse
    {
        abort_unless(
            $request->user() && $this->accessManager->can($request->user(), 'task.tiers.update'),
            403,
            'Vous n\'avez pas la permission de créer un client Tiers.'
        );

        $validated = $request->validate([
            'client' => ['required', 'array'],
            'client.code' => ['nullable', 'string', 'max:255'],
            'client.name' => ['nullable', 'string', 'max:255'],
            'client.phone' => ['nullable', 'string', 'max:255'],
            'client.address' => ['nullable', 'string', 'max:5000'],
            'client.postal_code' => ['nullable', 'string', 'max:255'],
            'client.city' => ['nullable', 'string', 'max:255'],
        ]);

        $client = $this->normalizeFuelClientPayload((array) $validated['client']);

        if (! collect($client)->filter(fn (string $value): bool => $value !== '')->isNotEmpty()) {
            return response()->json(['message' => 'Renseignez au moins une information client.'], 422);
        }

        if ($client['code'] !== '' && $this->fuelClientCodeExists($client['code'])) {
            return response()->json(['message' => 'Ce code tiers existe déjà dans les Tiers.'], 422);
        }

        if ($this->fuelClientIdentityExists($client)) {
            return response()->json(['message' => 'Un client avec le même nom, la même adresse et le même code postal existe déjà dans les Tiers.'], 422);
        }

        $record = DB::transaction(function () use ($request, $client): TaskTiersRecord {
            $config = $this->writableFuelTiersConfig($request);
            $this->ensureFuelClientTiersColumns($config);

            $data = [
                'code_tiers' => $client['code'],
                'nom_raison_sociale' => $client['name'],
                'telephone' => $client['phone'],
                'adresse' => $client['address'],
                'code_postal' => $client['postal_code'],
                'commune' => $client['city'],
            ];

            $record = TaskTiersRecord::query()->create([
                'import_config_id' => $config->id,
                'source_row_hash' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'primary_identifier' => $client['code'] ?: null,
                'reference_value' => $client['name'] ?: null,
                'data' => $data,
                'search_text' => TiersSearchText::build($data, $client['code'], $client['name']),
                'imported_by_user_id' => $request->user()?->id,
                'imported_at' => now(),
            ]);

            TaskFuelNewClient::query()->create([
                'task_tiers_record_id' => $record->id,
                'created_by_user_id' => $request->user()?->id,
            ]);

            return $record;
        });

        return response()->json([
            'message' => 'Client créé.',
            'tiers' => $this->fuelTiersResult($record),
            'new_clients' => $this->pendingFuelNewClientsPayload(),
        ], 201);
    }

    public function newClients(Request $request): JsonResponse
    {
        abort_unless(
            $request->user() && $this->accessManager->can($request->user(), 'task.tiers.update'),
            403,
            'Vous n\'avez pas la permission de consulter les nouveaux clients.'
        );

        return response()->json([
            'new_clients' => $this->pendingFuelNewClientsPayload(),
        ]);
    }

    public function validateNewClient(Request $request, TaskFuelNewClient $newClient): JsonResponse
    {
        abort_unless(
            $request->user() && $this->accessManager->can($request->user(), 'task.tiers.update'),
            403,
            'Vous n\'avez pas la permission de valider ce client.'
        );

        $newClient->forceFill([
            'validated_at' => now(),
            'validated_by_user_id' => $request->user()?->id,
        ])->save();

        return response()->json([
            'message' => 'Client validé.',
            'new_clients' => $this->pendingFuelNewClientsPayload(),
        ]);
    }

    public function storeOption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:site,product_type'],
            'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['kind'] === TaskFuelOption::KIND_SITE) {
            $depotId = $validated['depot_id'] ?? null;

            if (! $depotId) {
                return response()->json(['message' => 'Veuillez sélectionner un dépôt.'], 422);
            }

            if (TaskFuelOption::query()->where('kind', TaskFuelOption::KIND_SITE)->where('depot_id', $depotId)->exists()) {
                return response()->json(['message' => 'Ce dépôt est déjà dans la liste des sites.'], 422);
            }

            $depot = Depot::query()->findOrFail($depotId);

            $option = TaskFuelOption::query()->create([
                'kind' => TaskFuelOption::KIND_SITE,
                'depot_id' => $depot->id,
                'label' => $depot->name,
                'active' => true,
                'sort_order' => (int) TaskFuelOption::query()->where('kind', TaskFuelOption::KIND_SITE)->max('sort_order') + 1,
                'created_by_user_id' => $request->user()?->id,
                'updated_by_user_id' => $request->user()?->id,
            ]);

            $this->loadSiteRelations($option);
        } else {
            $label = trim((string) ($validated['label'] ?? ''));

            if ($label === '') {
                return response()->json(['message' => 'Le libellé est requis.'], 422);
            }

            $option = TaskFuelOption::query()->create([
                'kind' => $validated['kind'],
                'label' => $label,
                'active' => true,
                'sort_order' => (int) TaskFuelOption::query()->where('kind', $validated['kind'])->max('sort_order') + 1,
                'created_by_user_id' => $request->user()?->id,
                'updated_by_user_id' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'message' => 'Valeur ajoutée.',
            'option' => $this->fuelOptionPayload($option),
        ], 201);
    }

    public function updateOption(Request $request, TaskFuelOption $option): JsonResponse
    {
        $isSite = $option->kind === TaskFuelOption::KIND_SITE;

        $validated = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);

        $updates = [
            'active' => (bool) $validated['active'],
            'updated_by_user_id' => $request->user()?->id,
        ];

        if (! $isSite && isset($validated['label'])) {
            $updates['label'] = trim((string) $validated['label']);
        }

        $option->forceFill($updates)->save();

        if ($isSite) {
            $this->loadSiteRelations($option);
        }

        return response()->json([
            'message' => 'Valeur mise à jour.',
            'option' => $this->fuelOptionPayload($option),
        ]);
    }

    public function destroyOption(TaskFuelOption $option): JsonResponse
    {
        $option->delete();

        return response()->json([
            'message' => 'Valeur supprimée.',
        ]);
    }

    public function storeRecurring(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && $this->accessManager->can($user, 'task.fuel.update'), 403);

        $validated = $request->validate([
            'client_name' => ['nullable', 'string', 'max:255'],
            'code_tiers' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'fuel_type' => ['nullable', 'string', 'max:255'],
            'volume_liters' => ['nullable', 'integer', 'min:0'],
            'urgent' => ['boolean'],
            'comment' => ['nullable', 'string', 'max:50000'],
            'first_delivery_date' => ['required', 'date'],
            'recurrence_type' => ['required', 'string', 'in:daily,weekly,weekdays,monthly'],
            'recurrence_config' => ['nullable', 'array'],
            'recurrence_config.interval' => ['nullable', 'integer', 'min:1', 'max:365'],
            'recurrence_config.days' => ['nullable', 'array'],
            'recurrence_config.days.*' => ['integer', 'min:0', 'max:6'],
            'days_before' => ['integer', 'min:0', 'max:365'],
            'active' => ['boolean'],
        ]);

        $recurring = TaskFuelRecurring::create([
            'client_name' => trim((string) ($validated['client_name'] ?? '')) ?: null,
            'code_tiers' => trim((string) ($validated['code_tiers'] ?? '')) ?: null,
            'phone' => trim((string) ($validated['phone'] ?? '')) ?: null,
            'address' => trim((string) ($validated['address'] ?? '')) ?: null,
            'postal_code' => trim((string) ($validated['postal_code'] ?? '')) ?: null,
            'city' => trim((string) ($validated['city'] ?? '')) ?: null,
            'site' => trim((string) ($validated['site'] ?? '')) ?: null,
            'fuel_type' => trim((string) ($validated['fuel_type'] ?? '')) ?: null,
            'volume_liters' => isset($validated['volume_liters']) ? (int) $validated['volume_liters'] : null,
            'urgent' => (bool) ($validated['urgent'] ?? false),
            'comment' => trim((string) ($validated['comment'] ?? '')) ?: null,
            'first_delivery_date' => $validated['first_delivery_date'],
            'recurrence_type' => $validated['recurrence_type'],
            'recurrence_config' => $validated['recurrence_config'] ?? null,
            'days_before' => (int) ($validated['days_before'] ?? 0),
            'active' => (bool) ($validated['active'] ?? true),
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

        $this->generateRecurringDeliveries();

        return response()->json([
            'message' => 'Récurrence créée.',
            'recurring' => $this->recurringPayload($recurring),
        ], 201);
    }

    public function updateRecurring(Request $request, TaskFuelRecurring $recurring): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && $this->accessManager->can($user, 'task.fuel.update'), 403);

        $validated = $request->validate([
            'client_name' => ['nullable', 'string', 'max:255'],
            'code_tiers' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'fuel_type' => ['nullable', 'string', 'max:255'],
            'volume_liters' => ['nullable', 'integer', 'min:0'],
            'urgent' => ['boolean'],
            'comment' => ['nullable', 'string', 'max:50000'],
            'first_delivery_date' => ['required', 'date'],
            'recurrence_type' => ['required', 'string', 'in:daily,weekly,weekdays,monthly'],
            'recurrence_config' => ['nullable', 'array'],
            'recurrence_config.interval' => ['nullable', 'integer', 'min:1', 'max:365'],
            'recurrence_config.days' => ['nullable', 'array'],
            'recurrence_config.days.*' => ['integer', 'min:0', 'max:6'],
            'days_before' => ['integer', 'min:0', 'max:365'],
            'active' => ['boolean'],
        ]);

        $recurring->forceFill([
            'client_name' => trim((string) ($validated['client_name'] ?? '')) ?: null,
            'code_tiers' => trim((string) ($validated['code_tiers'] ?? '')) ?: null,
            'phone' => trim((string) ($validated['phone'] ?? '')) ?: null,
            'address' => trim((string) ($validated['address'] ?? '')) ?: null,
            'postal_code' => trim((string) ($validated['postal_code'] ?? '')) ?: null,
            'city' => trim((string) ($validated['city'] ?? '')) ?: null,
            'site' => trim((string) ($validated['site'] ?? '')) ?: null,
            'fuel_type' => trim((string) ($validated['fuel_type'] ?? '')) ?: null,
            'volume_liters' => isset($validated['volume_liters']) ? (int) $validated['volume_liters'] : null,
            'urgent' => (bool) ($validated['urgent'] ?? false),
            'comment' => trim((string) ($validated['comment'] ?? '')) ?: null,
            'first_delivery_date' => $validated['first_delivery_date'],
            'recurrence_type' => $validated['recurrence_type'],
            'recurrence_config' => $validated['recurrence_config'] ?? null,
            'days_before' => (int) ($validated['days_before'] ?? 0),
            'active' => (bool) ($validated['active'] ?? true),
            'updated_by_user_id' => $user->id,
        ])->save();

        $this->generateRecurringDeliveries();

        return response()->json([
            'message' => 'Récurrence mise à jour.',
            'recurring' => $this->recurringPayload($recurring->fresh()),
        ]);
    }

    public function destroyRecurring(Request $request, TaskFuelRecurring $recurring): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && $this->accessManager->can($user, 'task.fuel.delete'), 403);

        $recurring->delete();

        return response()->json([
            'message' => 'Récurrence supprimée.',
        ]);
    }

    private function generateRecurringDeliveries(): void
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(60);

        TaskFuelRecurring::where('active', true)->get()->each(function (TaskFuelRecurring $recurring) use ($today, $horizon): void {
            $computeFrom = $recurring->first_delivery_date->gte($today->copy()->subDays(30))
                ? $recurring->first_delivery_date->copy()
                : $today->copy()->subDays(30);

            $computeTo = $horizon;

            $occurrences = $this->computeOccurrences($recurring, $computeFrom, $computeTo);

            foreach ($occurrences as $occurrence) {
                $displayDate = $occurrence->copy()->subDays($recurring->days_before);
                if ($displayDate->gt($today)) {
                    continue;
                }

                $occurrenceStr = $occurrence->format('Y-m-d');

                $exists = TaskFuelDelivery::withTrashed()
                    ->where('recurring_id', $recurring->id)
                    ->where('recurring_occurrence_date', $occurrenceStr)
                    ->exists();

                if ($exists) {
                    continue;
                }

                TaskFuelDelivery::create([
                    'recurring_id' => $recurring->id,
                    'recurring_occurrence_date' => $occurrenceStr,
                    'delivery_date' => $occurrenceStr,
                    'site' => $recurring->site,
                    'code_tiers' => $recurring->code_tiers,
                    'client_name' => $recurring->client_name,
                    'phone' => $recurring->phone,
                    'address' => $recurring->address,
                    'postal_code' => $recurring->postal_code,
                    'city' => $recurring->city,
                    'fuel_type' => $recurring->fuel_type,
                    'volume_liters' => $recurring->volume_liters,
                    'urgent' => $recurring->urgent,
                    'comment' => $recurring->comment,
                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                ]);
            }
        });
    }

    private function computeOccurrences(TaskFuelRecurring $recurring, Carbon $from, Carbon $to): array
    {
        $occurrences = [];
        $firstDate = Carbon::instance($recurring->first_delivery_date)->startOfDay();
        $config = $recurring->recurrence_config ?? [];
        $interval = max(1, (int) ($config['interval'] ?? 1));

        switch ($recurring->recurrence_type) {
            case 'daily':
                $current = $firstDate->copy();
                while ($current->lte($to)) {
                    if ($current->gte($from)) {
                        $occurrences[] = $current->copy();
                    }
                    $current->addDays($interval);
                }
                break;

            case 'weekly':
                $current = $firstDate->copy();
                while ($current->lte($to)) {
                    if ($current->gte($from)) {
                        $occurrences[] = $current->copy();
                    }
                    $current->addWeeks($interval);
                }
                break;

            case 'weekdays':
                $days = array_map('intval', (array) ($config['days'] ?? []));
                $isoDays = array_map(fn (int $d): int => $d + 1, $days); // 0=Mon→1, 6=Sun→7
                $start = $firstDate->gt($from) ? $firstDate->copy() : $from->copy();
                while ($start->lte($to)) {
                    if (in_array($start->dayOfWeekIso, $isoDays, true)) {
                        $occurrences[] = $start->copy();
                    }
                    $start->addDay();
                }
                break;

            case 'monthly':
                $targetDay = $firstDate->day;
                $current = $firstDate->copy();
                while ($current->lte($to)) {
                    if ($current->gte($from)) {
                        $occurrences[] = $current->copy();
                    }
                    $nextYear = $current->year;
                    $nextMonth = $current->month + $interval;
                    while ($nextMonth > 12) {
                        $nextMonth -= 12;
                        $nextYear++;
                    }
                    $daysInNextMonth = Carbon::create($nextYear, $nextMonth, 1)->daysInMonth;
                    $current = Carbon::create($nextYear, $nextMonth, min($targetDay, $daysInNextMonth))->startOfDay();
                }
                break;
        }

        return $occurrences;
    }

    private function recurringsPayload(): array
    {
        return TaskFuelRecurring::query()
            ->orderByDesc('active')
            ->orderBy('client_name')
            ->orderBy('id')
            ->get()
            ->map(fn (TaskFuelRecurring $r): array => $this->recurringPayload($r))
            ->values()
            ->all();
    }

    private function recurringPayload(TaskFuelRecurring $recurring): array
    {
        $today = now()->startOfDay();
        $nextOccurrence = null;
        if ($recurring->active) {
            $occurrences = $this->computeOccurrences($recurring, $today, $today->copy()->addDays(365));
            $nextOccurrence = $occurrences[0] ?? null;
        }

        return [
            'id' => $recurring->id,
            'client_name' => $recurring->client_name ?? '',
            'code_tiers' => $recurring->code_tiers ?? '',
            'phone' => $recurring->phone ?? '',
            'address' => $recurring->address ?? '',
            'postal_code' => $recurring->postal_code ?? '',
            'city' => $recurring->city ?? '',
            'site' => $recurring->site ?? '',
            'fuel_type' => $recurring->fuel_type ?? '',
            'volume_liters' => $recurring->volume_liters,
            'urgent' => (bool) $recurring->urgent,
            'comment' => $recurring->comment ?? '',
            'first_delivery_date' => $recurring->first_delivery_date?->format('Y-m-d') ?? '',
            'recurrence_type' => $recurring->recurrence_type ?? '',
            'recurrence_config' => $recurring->recurrence_config ?? [],
            'days_before' => $recurring->days_before ?? 0,
            'active' => (bool) $recurring->active,
            'recurrence_label' => $this->recurrenceLabel($recurring),
            'next_occurrence' => $nextOccurrence?->format('Y-m-d'),
            'next_occurrence_label' => $nextOccurrence?->format('d/m/Y'),
        ];
    }

    private function recurrenceLabel(TaskFuelRecurring $recurring): string
    {
        $config = $recurring->recurrence_config ?? [];
        $interval = max(1, (int) ($config['interval'] ?? 1));

        return match ($recurring->recurrence_type) {
            'daily' => $interval === 1 ? 'Tous les jours' : "Tous les {$interval} jours",
            'weekly' => $interval === 1 ? 'Toutes les semaines' : "Toutes les {$interval} semaines",
            'weekdays' => $this->weekdaysLabel((array) ($config['days'] ?? [])),
            'monthly' => $interval === 1 ? 'Tous les mois' : "Tous les {$interval} mois",
            default => $recurring->recurrence_type ?? '',
        };
    }

    private function weekdaysLabel(array $days): string
    {
        $names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        sort($days);
        $labels = array_filter(array_map(fn (int $d): string => $names[$d] ?? '', $days));

        return implode(', ', $labels) ?: 'Aucun jour';
    }

    private function fuelTiersResult(TaskTiersRecord $record): array
    {
        $data = (array) ($record->data ?? []);
        $columns = $this->tiersColumnsByKey();

        $code = trim((string) ($this->firstValueByColumnNeedles($data, $columns, [
            ['code', 'tiers'],
            ['code'],
        ], [
            ['code', 'tiers'],
            ['code_tiers'],
            ['identifiant'],
        ]) ?: $record->primary_identifier));
        $name = trim((string) $this->firstValueByColumnNeedles($data, $columns, [
            ['nom', 'raison', 'sociale'],
            ['raison', 'sociale'],
            ['nom'],
            ['client'],
        ], [
            ['nom_raison_sociale'],
            ['raison_sociale'],
            ['nom'],
            ['client'],
            ['societe'],
            ['company'],
        ], [$code]));
        $referenceValue = trim((string) $record->reference_value);
        if ($name === '' && $referenceValue !== '' && $this->normalizeKey($referenceValue) !== $this->normalizeKey($code)) {
            $name = $referenceValue;
        }
        $postalCode = trim((string) $this->firstValueByColumnNeedles($data, $columns, [
            ['code', 'postal'],
            ['cp'],
        ], [
            ['code', 'postal'],
            ['cp'],
        ]));
        $city = trim((string) $this->firstValueByColumnNeedles($data, $columns, [
            ['commune'],
            ['ville'],
        ], [
            ['commune'],
            ['ville'],
        ]));
        $address = trim((string) $this->firstValueByColumnNeedles($data, $columns, [
            ['adresse'],
            ['rue'],
            ['voie'],
        ], [
            ['adresse'],
            ['rue'],
            ['voie'],
        ]));
        $mobilePhone = trim((string) $this->firstValueByColumnNeedles($data, $columns, [
            ['telephone', 'portable'],
            ['mobile'],
            ['portable'],
        ], [
            ['mobile'],
            ['portable'],
        ]));
        $phone = trim((string) $this->firstValueByColumnNeedles($data, $columns, [
            ['telephone', 'fixe'],
            ['téléphone', 'fixe'],
            ['telephone'],
            ['téléphone'],
            ['tel'],
        ], [
            ['telephone'],
            ['téléphone'],
            ['tel'],
        ], [$mobilePhone]));

        return [
            'id' => $record->id,
            'code' => $code,
            'name' => $name !== '' ? $name : 'Tiers #'.$record->id,
            'phone' => $mobilePhone !== '' ? $mobilePhone : $phone,
            'mobile_phone' => $mobilePhone,
            'fixed_phone' => $phone,
            'address' => $address,
            'postal_code' => $postalCode,
            'city' => $city,
        ];
    }

    private function fuelDeliveryPayload(TaskFuelDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'delivery_date' => $delivery->delivery_date?->format('d/m/Y') ?? '',
            'delivery_date_value' => $delivery->delivery_date?->format('Y-m-d') ?? '',
            'site' => $delivery->site ?? '',
            'client_name' => $delivery->client_name ?? '',
            'code_tiers' => $delivery->code_tiers ?? '',
            'phone' => $delivery->phone ?? '',
            'address' => $delivery->address ?? '',
            'postal_code' => $delivery->postal_code ?? '',
            'city' => $delivery->city ?? '',
            'delivery_city' => trim(implode(' ', array_filter([$delivery->postal_code, $delivery->city]))),
            'fuel_type' => $delivery->fuel_type ?? '',
            'volume_liters' => $delivery->volume_liters,
            'volume' => $delivery->volume_liters !== null ? $delivery->volume_liters.' L' : '',
            'comment' => $delivery->comment ?? '',
            'created_at_iso' => $delivery->created_at?->toIso8601String() ?? '',
            'created_at_label' => $delivery->created_at?->format('d/m/Y H:i') ?? '',
            'created_by' => $delivery->createdBy?->name ?? '',
            'urgent' => (bool) $delivery->urgent,
            'is_recurring' => $delivery->recurring_id !== null,
            'recurring_id' => $delivery->recurring_id,
            'is_delivered' => $delivery->delivered_at !== null,
            'actual_delivery_date' => $delivery->actual_delivery_date?->format('d/m/Y') ?? '',
            'actual_delivery_date_value' => $delivery->actual_delivery_date?->format('Y-m-d') ?? '',
            'delivered_at_label' => $delivery->delivered_at?->format('d/m/Y H:i') ?? '',
            'delivered_driver_id' => $delivery->delivered_driver_user_id,
            'delivered_driver_name' => $delivery->deliveryDriver?->name ?? '',
            'delivered_pointed_by' => $delivery->deliveryPointedBy?->name ?? '',
        ];
    }

    private function fuelDriversPayload(): array
    {
        return User::query()
            ->with(['depot:id,name', 'depots:id,name'])
            ->whereHas('sector', fn (Builder $query) => $query->whereRaw("LOWER(name) = 'chauffeur carb'"))
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('is_active')
                    ->orWhere('is_active', true);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('depot_id')
                    ->orWhereHas('depots');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'depot_id'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'depot_id' => $user->depot_id,
                'depot_ids' => collect([$user->depot_id])
                    ->merge($user->depots->pluck('id'))
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
                'depot_name' => $user->depot?->name ?? '',
            ])
            ->values()
            ->all();
    }

    private function pendingFuelNewClientsPayload(): array
    {
        return TaskFuelNewClient::query()
            ->with([
                'tiersRecord:id,data,primary_identifier,reference_value',
                'createdBy:id,name',
            ])
            ->whereNull('validated_at')
            ->latest('created_at')
            ->get()
            ->map(fn (TaskFuelNewClient $newClient): array => $this->fuelNewClientPayload($newClient))
            ->values()
            ->all();
    }

    private function fuelNewClientPayload(TaskFuelNewClient $newClient): array
    {
        $record = $newClient->tiersRecord;
        $data = (array) ($record?->data ?? []);

        return [
            'id' => $newClient->id,
            'tiers_record_id' => $record?->id,
            'code' => (string) ($data['code_tiers'] ?? $record?->primary_identifier ?? ''),
            'name' => (string) ($data['nom_raison_sociale'] ?? $record?->reference_value ?? ''),
            'phone' => (string) ($data['telephone'] ?? ''),
            'address' => (string) ($data['adresse'] ?? ''),
            'postal_code' => (string) ($data['code_postal'] ?? ''),
            'city' => (string) ($data['commune'] ?? ''),
            'created_at' => $newClient->created_at?->format('d/m/Y H:i') ?? '',
            'created_by' => $newClient->createdBy?->name ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array{code: string, name: string, phone: string, address: string, postal_code: string, city: string}
     */
    private function normalizeFuelClientPayload(array $client): array
    {
        return [
            'code' => trim((string) ($client['code'] ?? '')),
            'name' => trim((string) ($client['name'] ?? '')),
            'phone' => trim((string) ($client['phone'] ?? '')),
            'address' => trim((string) ($client['address'] ?? '')),
            'postal_code' => trim((string) ($client['postal_code'] ?? '')),
            'city' => trim((string) ($client['city'] ?? '')),
        ];
    }

    private function fuelClientCodeExists(string $code): bool
    {
        $normalizedCode = TiersSearchText::normalize($code);

        return TaskTiersRecord::query()
            ->where(function (Builder $query) use ($code, $normalizedCode): void {
                $query
                    ->whereRaw('LOWER(COALESCE(primary_identifier, \'\')) = ?', [Str::lower($code)])
                    ->orWhereRaw('COALESCE(search_text, \'\') LIKE ?', ['%'.$this->escapeLike($normalizedCode).'%'])
                    ->orWhereRaw('LOWER(CAST(data AS CHAR)) LIKE ?', ['%'.$this->escapeLike(Str::lower($code)).'%']);
            })
            ->get(['id', 'primary_identifier', 'data'])
            ->contains(function (TaskTiersRecord $record) use ($normalizedCode): bool {
                $result = $this->fuelTiersResult($record);
                return TiersSearchText::normalize($result['code'] ?? '') === $normalizedCode;
            });
    }

    /**
     * @param  array{code: string, name: string, phone: string, address: string, postal_code: string, city: string}  $client
     */
    private function fuelClientIdentityExists(array $client): bool
    {
        if ($client['name'] === '' || $client['address'] === '' || $client['postal_code'] === '') {
            return false;
        }

        $name = TiersSearchText::normalize($client['name']);
        $address = TiersSearchText::normalize($client['address']);
        $postalCode = TiersSearchText::normalize($client['postal_code']);

        return TaskTiersRecord::query()
            ->where('search_text', 'LIKE', '%'.$this->escapeLike($name).'%')
            ->where('search_text', 'LIKE', '%'.$this->escapeLike($address).'%')
            ->where('search_text', 'LIKE', '%'.$this->escapeLike($postalCode).'%')
            ->limit(25)
            ->get(['id', 'primary_identifier', 'reference_value', 'data'])
            ->contains(function (TaskTiersRecord $record) use ($name, $address, $postalCode): bool {
                $result = $this->fuelTiersResult($record);

                return TiersSearchText::normalize($result['name'] ?? '') === $name
                    && TiersSearchText::normalize($result['address'] ?? '') === $address
                    && TiersSearchText::normalize($result['postal_code'] ?? '') === $postalCode;
            });
    }

    private function writableFuelTiersConfig(Request $request): TaskTiersImportConfig
    {
        $config = TaskTiersImportConfig::query()->latest('id')->first();

        if ($config) {
            return $config;
        }

        return TaskTiersImportConfig::query()->create([
            'name' => 'Configuration Tiers manuelle',
            'original_filename' => null,
            'columns' => [],
            'identification_column' => null,
            'reference_column' => null,
            'options' => [
                'status' => 'manual',
                'manual_columns' => [],
            ],
            'created_by_user_id' => $request->user()?->id,
        ]);
    }

    private function ensureFuelClientTiersColumns(TaskTiersImportConfig $config): void
    {
        $requiredColumns = [
            ['key' => 'code_tiers', 'label' => 'Code tiers'],
            ['key' => 'nom_raison_sociale', 'label' => 'Nom / Raison sociale'],
            ['key' => 'telephone', 'label' => 'Téléphone'],
            ['key' => 'adresse', 'label' => 'Adresse'],
            ['key' => 'code_postal', 'label' => 'Code postal'],
            ['key' => 'commune', 'label' => 'Commune'],
        ];

        $options = (array) ($config->options ?? []);
        $manualColumns = collect((array) ($options['manual_columns'] ?? []));
        $existingKeys = $manualColumns
            ->pluck('key')
            ->merge(collect((array) ($config->columns ?? []))->map(fn (array $column): string => $this->tiersColumnKey($column)))
            ->filter()
            ->map(fn ($key): string => (string) $key)
            ->all();

        foreach ($requiredColumns as $column) {
            if (in_array($column['key'], $existingKeys, true)) {
                continue;
            }

            $manualColumns->push([
                'key' => $column['key'],
                'label' => $column['label'],
                'format' => $column['key'] === 'code_postal' ? 'postal_code' : '',
            ]);
            $existingKeys[] = $column['key'];
        }

        $options['manual_columns'] = $manualColumns->values()->all();
        $options['deleted_columns'] = collect((array) ($options['deleted_columns'] ?? []))
            ->reject(fn ($key): bool => in_array((string) $key, collect($requiredColumns)->pluck('key')->all(), true))
            ->values()
            ->all();

        $config->forceFill(['options' => $options])->save();
    }

    private function applyFuelSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $terms = collect(preg_split('/\s+/', Str::lower($search)) ?: [])
            ->map(fn (string $term): string => trim($term))
            ->filter()
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($terms): void {
            $terms->each(function (string $term) use ($searchQuery): void {
                $needle = '%'.$this->escapeLike($term).'%';

                $searchQuery->where(function (Builder $termQuery) use ($needle): void {
                    $termQuery
                        ->whereRaw("LOWER(COALESCE(site, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(client_name, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(code_tiers, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(postal_code, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(city, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(fuel_type, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(comment, '')) LIKE ?", [$needle])
                        ->orWhereHas('createdBy', fn (Builder $userQuery) => $userQuery->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$needle]));
                });
            });
        });
    }

    private function applyFuelFilters(Builder $query, array $filters): void
    {
        $date = (array) ($filters['delivery_date'] ?? []);
        $dateMode = (string) ($date['mode'] ?? '');
        if ($dateMode === 'exact' && ! empty($date['value'])) {
            $query->whereDate('delivery_date', $date['value']);
        } elseif ($dateMode === 'before' && ! empty($date['value'])) {
            $query->whereDate('delivery_date', '<=', $date['value']);
        } elseif ($dateMode === 'after' && ! empty($date['value'])) {
            $query->whereDate('delivery_date', '>=', $date['value']);
        } elseif ($dateMode === 'between') {
            if (! empty($date['from'])) {
                $query->whereDate('delivery_date', '>=', $date['from']);
            }
            if (! empty($date['to'])) {
                $query->whereDate('delivery_date', '<=', $date['to']);
            }
        }

        $this->applyNullableExactFilter($query, 'site', $filters['site'] ?? null);
        $this->applyNullableExactFilter($query, 'fuel_type', $filters['fuel_type'] ?? null);
        $this->applyTextFilter($query, ['client_name', 'code_tiers'], $filters['client'] ?? null);
        $this->applyTextFilter($query, ['phone'], $filters['phone'] ?? null);
        $this->applyTextFilter($query, ['postal_code', 'city'], $filters['delivery_city'] ?? null);
        $this->applyTextFilter($query, ['comment'], $filters['comment'] ?? null);

        $volume = (array) ($filters['volume'] ?? []);
        $volumeMode = (string) ($volume['mode'] ?? '');
        if ($volumeMode === 'eq' && $this->isFilledNumeric($volume['value'] ?? null)) {
            $query->where('volume_liters', (int) $volume['value']);
        } elseif ($volumeMode === 'gt' && $this->isFilledNumeric($volume['value'] ?? null)) {
            $query->where('volume_liters', '>=', (int) $volume['value']);
        } elseif ($volumeMode === 'lt' && $this->isFilledNumeric($volume['value'] ?? null)) {
            $query->where('volume_liters', '<=', (int) $volume['value']);
        } elseif ($volumeMode === 'between') {
            if ($this->isFilledNumeric($volume['from'] ?? null)) {
                $query->where('volume_liters', '>=', (int) $volume['from']);
            }
            if ($this->isFilledNumeric($volume['to'] ?? null)) {
                $query->where('volume_liters', '<=', (int) $volume['to']);
            }
        }

        $info = (array) ($filters['info'] ?? []);
        $this->applyTextFilter($query, [], $info['text'] ?? null, true);
        if (($info['urgent'] ?? '') === 'yes') {
            $query->where('urgent', true);
        } elseif (($info['urgent'] ?? '') === 'no') {
            $query->where('urgent', false);
        }

        if (($info['delivered'] ?? '') === 'yes') {
            $query->whereNotNull('delivered_at');
        } elseif (($info['delivered'] ?? '') === 'no') {
            $query->whereNull('delivered_at');
        }
    }

    private function applyFuelSort(Builder $query, array $sort): void
    {
        $key = (string) ($sort['key'] ?? '');
        $direction = (string) ($sort['direction'] ?? '');
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : '';

        if ($key === '' || $direction === '') {
            $query
                ->orderByRaw('CASE WHEN delivery_date IS NULL THEN 0 ELSE 1 END ASC')
                ->orderByRaw('CASE WHEN delivery_date IS NULL THEN created_at ELSE NULL END ASC')
                ->orderBy('delivery_date', 'asc')
                ->orderBy('site', 'asc')
                ->orderBy('created_at', 'asc');
            return;
        }

        match ($key) {
            'delivery_date' => $query->orderBy('delivery_date', $direction)->orderBy('id', 'desc'),
            'site' => $query->orderBy('site', $direction)->orderBy('id', 'desc'),
            'client' => $query->orderBy('client_name', $direction)->orderBy('code_tiers', $direction)->orderBy('id', 'desc'),
            'phone' => $query->orderBy('phone', $direction)->orderBy('id', 'desc'),
            'delivery_city' => $query->orderBy('city', $direction)->orderBy('postal_code', $direction)->orderBy('id', 'desc'),
            'fuel_type' => $query->orderBy('fuel_type', $direction)->orderBy('id', 'desc'),
            'volume' => $query->orderBy('volume_liters', $direction)->orderBy('id', 'desc'),
            'comment' => $query->orderBy('comment', $direction)->orderBy('id', 'desc'),
            'info' => $query->orderBy('urgent', $direction)->orderBy('created_at', $direction)->orderBy('id', 'desc'),
            default => $query->latest('id'),
        };
    }

    private function applyNullableExactFilter(Builder $query, string $column, mixed $value): void
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return;
        }

        $query->where($column, $value);
    }

    private function applyTextFilter(Builder $query, array $columns, mixed $value, bool $includeInfo = false): void
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return;
        }

        $needle = '%'.$this->escapeLike(Str::lower($value)).'%';
        $query->where(function (Builder $textQuery) use ($columns, $needle, $includeInfo): void {
            foreach ($columns as $column) {
                $textQuery->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
            }

            if ($includeInfo) {
                $textQuery
                    ->orWhereRaw("LOWER(DATE_FORMAT(created_at, '%d/%m/%Y %H:%i')) LIKE ?", [$needle])
                    ->orWhereHas('createdBy', fn (Builder $userQuery) => $userQuery->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$needle]));
            }
        });
    }

    private function isFilledNumeric(mixed $value): bool
    {
        return $value !== null && $value !== '' && is_numeric($value);
    }

    private function normalizeFuelFiltersForFrontend(array $filters): array
    {
        return [
            'delivery_date' => (array) ($filters['delivery_date'] ?? []),
            'site' => (string) ($filters['site'] ?? ''),
            'client' => (string) ($filters['client'] ?? ''),
            'phone' => (string) ($filters['phone'] ?? ''),
            'delivery_city' => (string) ($filters['delivery_city'] ?? ''),
            'fuel_type' => (string) ($filters['fuel_type'] ?? ''),
            'volume' => (array) ($filters['volume'] ?? []),
            'comment' => (string) ($filters['comment'] ?? ''),
            'info' => (array) ($filters['info'] ?? []),
        ];
    }

    private function defaultFuelFilters(): array
    {
        return [
            'info' => [
                'delivered' => 'no',
            ],
        ];
    }

    private function fuelOptionsPayload(): array
    {
        $this->ensureDefaultFuelOptions();

        $options = TaskFuelOption::query()
            ->with([
                'depot' => fn ($q) => $q->with([
                    'users' => fn ($uq) => $uq
                        ->whereHas('sector', fn ($sq) => $sq->whereRaw("LOWER(name) = 'chauffeur carb'"))
                        ->select(['users.id', 'users.name', 'users.depot_id']),
                ]),
            ])
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (TaskFuelOption $option): array => $this->fuelOptionPayload($option))
            ->groupBy('kind');

        return [
            'sites' => $options->get(TaskFuelOption::KIND_SITE, collect())->values()->all(),
            'product_types' => $options->get(TaskFuelOption::KIND_PRODUCT_TYPE, collect())->values()->all(),
        ];
    }

    private function fuelOptionPayload(TaskFuelOption $option): array
    {
        $depotLabel = $option->label ?? '';

        if ($option->kind === TaskFuelOption::KIND_SITE && $option->depot !== null) {
            $depotName = $option->depot->name;
            $chauffeurCarb = $option->depot->relationLoaded('users') ? $option->depot->users->first() : null;
            $depotLabel = $chauffeurCarb ? $depotName.' - '.$chauffeurCarb->name : $depotName;
        }

        return [
            'id' => $option->id,
            'kind' => $option->kind,
            'label' => $option->label,
            'depot_id' => $option->depot_id,
            'depot_label' => $depotLabel,
            'active' => (bool) $option->active,
            'sort_order' => $option->sort_order,
        ];
    }

    private function loadSiteRelations(TaskFuelOption $option): void
    {
        $option->load([
            'depot' => fn ($q) => $q->with([
                'users' => fn ($uq) => $uq
                    ->whereHas('sector', fn ($sq) => $sq->whereRaw("LOWER(name) = 'chauffeur carb'"))
                    ->select(['users.id', 'users.name', 'users.depot_id']),
            ]),
        ]);
    }

    private function ensureDefaultFuelOptions(): void
    {
        if (TaskFuelOption::query()->where('kind', TaskFuelOption::KIND_PRODUCT_TYPE)->exists()) {
            return;
        }

        foreach (['GNR', 'Gazole', 'Fuel', 'AdBlue'] as $index => $label) {
            TaskFuelOption::query()->create([
                'kind' => TaskFuelOption::KIND_PRODUCT_TYPE,
                'label' => $label,
                'active' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{key: string, label: string, format: string}>  $columns
     * @param  array<int, array<int, string>>  $labelNeedleGroups
     * @param  array<int, array<int, string>>  $needleGroups
     * @param  array<int, string>  $excludedValues
     */
    private function firstValueByColumnNeedles(array $data, array $columns, array $labelNeedleGroups, array $needleGroups, array $excludedValues = []): string
    {
        $normalizedExcluded = collect($excludedValues)
            ->map(fn ($value): string => $this->normalizeKey((string) $value))
            ->filter()
            ->values()
            ->all();

        $valueFromLabel = $this->firstValueByNeedles($data, $columns, $labelNeedleGroups, 'label', $normalizedExcluded);
        if ($valueFromLabel !== '') {
            return $valueFromLabel;
        }

        return $this->firstValueByNeedles($data, $columns, $needleGroups, 'key', $normalizedExcluded);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{key: string, label: string, format: string}>  $columns
     * @param  array<int, array<int, string>>  $needleGroups
     * @param  array<int, string>  $normalizedExcluded
     */
    private function firstValueByNeedles(array $data, array $columns, array $needleGroups, string $source, array $normalizedExcluded): string
    {
        foreach ($needleGroups as $needles) {
            foreach ($data as $key => $value) {
                $column = $columns[(string) $key] ?? [
                    'key' => (string) $key,
                    'label' => (string) $key,
                    'format' => '',
                ];
                $normalizedKey = $this->normalizeKey((string) ($column[$source] ?? $key));
                $matches = collect($needles)
                    ->every(fn (string $needle): bool => str_contains($normalizedKey, $this->normalizeKey($needle)));

                if (! $matches) {
                    continue;
                }

                $text = trim(TiersColumnFormat::formatForDisplay($value, (string) ($column['format'] ?? '')));
                if ($text === '' || in_array($this->normalizeKey($text), $normalizedExcluded, true)) {
                    continue;
                }

                return $text;
            }
        }

        return '';
    }

    /**
     * @return array<string, array{key: string, label: string, format: string}>
     */
    private function tiersColumnsByKey(): array
    {
        $columns = [];

        TaskTiersImportConfig::query()
            ->latest('id')
            ->limit(50)
            ->get(['columns', 'options'])
            ->each(function (TaskTiersImportConfig $config) use (&$columns): void {
                foreach ((array) ($config->columns ?? []) as $column) {
                    if (! (bool) ($column['import'] ?? false)) {
                        continue;
                    }

                    $key = $this->tiersColumnKey($column);
                    if ($key === '' || isset($columns[$key])) {
                        continue;
                    }

                    $columns[$key] = [
                        'key' => $key,
                        'label' => $this->tiersColumnLabel($column),
                        'format' => (string) ($column['format_model'] ?? ''),
                    ];
                }

                foreach ((array) (($config->options ?? [])['manual_columns'] ?? []) as $manualColumn) {
                    $key = trim((string) ($manualColumn['key'] ?? ''));
                    if ($key === '') {
                        continue;
                    }

                    $columns[$key] = [
                        'key' => $key,
                        'label' => trim((string) ($manualColumn['label'] ?? $key)) ?: $key,
                        'format' => (string) ($manualColumn['format'] ?? ''),
                    ];
                }
            });

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function tiersColumnKey(array $column): string
    {
        $existingColumn = trim((string) ($column['existing_column'] ?? ''));
        if ($existingColumn !== '') {
            return $existingColumn;
        }

        return Str::slug((string) ($column['application_name'] ?? $column['source_name'] ?? ''), '_');
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function tiersColumnLabel(array $column): string
    {
        $applicationName = trim((string) ($column['application_name'] ?? ''));
        if ($applicationName !== '') {
            return $applicationName;
        }

        $sourceName = trim((string) ($column['source_name'] ?? ''));

        return $sourceName !== '' ? $sourceName : 'Colonne';
    }

    private function computeMonthlyStats(): array
    {
        $now = Carbon::now();

        // Current month (M0)
        $currentStart = $now->copy()->startOfMonth()->toDateString();
        $currentEnd = $now->copy()->endOfMonth()->toDateString();

        // Last completed month (M-1)
        $lastMonthDate = $now->copy()->subMonthNoOverflow();
        $lastStart = $lastMonthDate->copy()->startOfMonth()->toDateString();
        $lastEnd = $lastMonthDate->copy()->endOfMonth()->toDateString();

        // Last 3 completed months: M-3 to M-1
        $threeStart = $now->copy()->subMonths(3)->startOfMonth()->toDateString();

        // Last 6 completed months: M-6 to M-1
        $sixStart = $now->copy()->subMonths(6)->startOfMonth()->toDateString();

        // Prior 3 months: M-6 to M-4 (for trend comparison against the last 3 months)
        $priorThreeEnd = $now->copy()->subMonths(4)->endOfMonth()->toDateString();

        $currentDeliveries = TaskFuelDelivery::query()
            ->whereBetween('delivery_date', [$currentStart, $currentEnd])
            ->get(['fuel_type', 'volume_liters']);

        $lastDeliveries = TaskFuelDelivery::query()
            ->whereBetween('delivery_date', [$lastStart, $lastEnd])
            ->get(['fuel_type', 'volume_liters']);

        $threeMonthsDeliveries = TaskFuelDelivery::query()
            ->whereBetween('delivery_date', [$threeStart, $lastEnd])
            ->get(['fuel_type', 'volume_liters']);

        $sixMonthsDeliveries = TaskFuelDelivery::query()
            ->whereBetween('delivery_date', [$sixStart, $lastEnd])
            ->get(['fuel_type', 'volume_liters']);

        $priorThreeDeliveries = TaskFuelDelivery::query()
            ->whereBetween('delivery_date', [$sixStart, $priorThreeEnd])
            ->get(['fuel_type', 'volume_liters']);

        return [
            'current_month_label' => ucfirst($now->locale('fr')->isoFormat('MMMM YYYY')),
            'last_month_label' => ucfirst($lastMonthDate->locale('fr')->isoFormat('MMMM YYYY')),
            'current' => $this->aggregateMonthlyDeliveries($currentDeliveries),
            'last' => $this->aggregateMonthlyDeliveries($lastDeliveries),
            'three_months' => $this->aggregateMonthlyDeliveries($threeMonthsDeliveries),
            'six_months' => $this->aggregateMonthlyDeliveries($sixMonthsDeliveries),
            'prior_three_months' => $this->aggregateMonthlyDeliveries($priorThreeDeliveries),
        ];
    }

    private function aggregateMonthlyDeliveries(\Illuminate\Support\Collection $deliveries): array
    {
        $byProduct = [];
        $totalVolume = 0;

        foreach ($deliveries as $d) {
            $vol = (int) ($d->volume_liters ?? 0);
            $totalVolume += $vol;
            $type = (string) ($d->fuel_type ?: '(sans type)');
            $byProduct[$type] = ($byProduct[$type] ?? 0) + $vol;
        }

        return [
            'count' => $deliveries->count(),
            'total_volume' => $totalVolume,
            'by_product' => $byProduct,
        ];
    }

    private function normalizeKey(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
