<?php

namespace App\Http\Controllers;

use App\Models\TaskFuelDelivery;
use App\Models\TaskFuelOption;
use App\Models\TaskTiersRecord;
use App\Models\TaskTiersImportConfig;
use App\Support\Access\AccessManager;
use App\Support\Tiers\TiersColumnFormat;
use App\Support\Tiers\TiersSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $filters = (array) $request->query('filters', []);
        $sort = (array) $request->query('sort', []);

        $deliveriesQuery = TaskFuelDelivery::query()
            ->with('createdBy:id,name');

        $this->applyFuelSearch($deliveriesQuery, $search);
        $this->applyFuelFilters($deliveriesQuery, $filters);
        $this->applyFuelSort($deliveriesQuery, $sort);

        $deliveries = $deliveriesQuery
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Tasks/Fuel/Index', [
            'permissions' => [
                'can_update' => (bool) ($user && $this->accessManager->can($user, 'task.fuel.update')),
                'can_delete' => (bool) ($user && $this->accessManager->can($user, 'task.fuel.delete')),
            ],
            'deliveries' => [
                'data' => $deliveries->getCollection()
                    ->map(fn (TaskFuelDelivery $delivery): array => $this->fuelDeliveryPayload($delivery))
                    ->values(),
                'meta' => [
                    'current_page' => $deliveries->currentPage(),
                    'from' => $deliveries->firstItem(),
                    'last_page' => $deliveries->lastPage(),
                    'per_page' => $deliveries->perPage(),
                    'to' => $deliveries->lastItem(),
                    'total' => $deliveries->total(),
                ],
            ],
            'options' => $this->fuelOptionsPayload(),
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

    public function storeOption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:site,product_type'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $option = TaskFuelOption::query()->create([
            'kind' => $validated['kind'],
            'label' => trim((string) $validated['label']),
            'active' => true,
            'sort_order' => (int) TaskFuelOption::query()->where('kind', $validated['kind'])->max('sort_order') + 1,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Valeur ajoutée.',
            'option' => $this->fuelOptionPayload($option),
        ], 201);
    }

    public function updateOption(Request $request, TaskFuelOption $option): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);

        $option->forceFill([
            'label' => trim((string) $validated['label']),
            'active' => (bool) $validated['active'],
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

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
            'created_at_label' => $delivery->created_at?->format('d/m/Y H:i') ?? '',
            'created_by' => $delivery->createdBy?->name ?? '',
            'urgent' => (bool) $delivery->urgent,
        ];
    }

    private function applyFuelSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $needle = '%'.$this->escapeLike(Str::lower($search)).'%';

        $query->where(function (Builder $searchQuery) use ($needle): void {
            $searchQuery
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

    private function fuelOptionsPayload(): array
    {
        $this->ensureDefaultFuelOptions();

        $options = TaskFuelOption::query()
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
        return [
            'id' => $option->id,
            'kind' => $option->kind,
            'label' => $option->label,
            'active' => (bool) $option->active,
            'sort_order' => $option->sort_order,
        ];
    }

    private function ensureDefaultFuelOptions(): void
    {
        if (TaskFuelOption::query()->exists()) {
            return;
        }

        $defaults = [
            TaskFuelOption::KIND_PRODUCT_TYPE => ['GNR', 'Gazole', 'Fuel', 'AdBlue'],
            TaskFuelOption::KIND_SITE => ['Site défini plus tard', 'Dépôt défini plus tard'],
        ];

        foreach ($defaults as $kind => $labels) {
            foreach ($labels as $index => $label) {
                TaskFuelOption::query()->create([
                    'kind' => $kind,
                    'label' => $label,
                    'active' => true,
                    'sort_order' => $index + 1,
                ]);
            }
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

    private function normalizeKey(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
