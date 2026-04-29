<?php

namespace App\Http\Controllers;

use App\Events\AprevoirTaskChanged;
use App\Events\AprevoirTaskUpdated;
use App\Models\AprevoirTask;
use App\Models\AprevoirArchivedTask;
use App\Models\Depot;
use App\Models\Transporter;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Aprevoir\AprevoirArchiveService;
use App\Services\Aprevoir\AprevoirService;
use App\Services\FormattingRuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ArchiveController extends Controller
{
    public function __construct(
        private readonly AprevoirArchiveService $archiveService,
        private readonly AprevoirService $aprevoirService,
        private readonly FormattingRuleService $formattingRuleService,
    )
    {
    }

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:80'],
            'contract' => ['nullable', 'string', 'max:120'],
            'direct' => ['nullable', 'boolean'],
            'boursagri' => ['nullable', 'boolean'],
            'indicators' => ['nullable', 'array'],
            'indicators.*' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100, 150])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $query = AprevoirArchivedTask::query();
        $directFilter = filter_var($validated['direct'] ?? false, FILTER_VALIDATE_BOOL);
        $boursagriFilter = filter_var($validated['boursagri'] ?? false, FILTER_VALIDATE_BOOL);
        $indicatorFilters = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($validated['indicators'] ?? []),
        )));

        if (! empty($validated['date_from'])) {
            $query->whereDate('date', '>=', (string) $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('date', '<=', (string) $validated['date_to']);
        }

        if (! empty($validated['assignee'])) {
            $this->applyAssigneeFilter($query, (string) $validated['assignee']);
        }

        if ($directFilter) {
            $query->where('is_direct', true);
        }

        if ($boursagriFilter) {
            $query->where('is_boursagri', true);
        }

        if (! empty($validated['contract'])) {
            $query->where('boursagri_contract_number', 'like', '%'.trim((string) $validated['contract']).'%');
        }

        if (! empty($validated['search'])) {
            $this->applySearchFilter($query, trim((string) $validated['search']));
        }

        $indicatorOptions = $this->buildIndicatorOptions($query);

        if ($indicatorFilters !== []) {
            $this->applyIndicatorFilters($query, $indicatorFilters);
        }

        $archives = $query
            ->orderByDesc('date')
            ->orderByDesc('position')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $rows = $archives->getCollection();
        $assigneeLookup = $this->buildAssigneeLookup($rows);
        $vehicleLookup = $this->buildVehicleLookup($rows);
        $formattingRules = $this->formattingRuleService
            ->getActiveRulesForTarget(FormattingRuleService::TARGET_A_PREVOIR)
            ->all();

        $archives->setCollection(
            $rows->map(function (AprevoirArchivedTask $task) use ($assigneeLookup, $vehicleLookup, $formattingRules): array {
                $assigneeType = $task->assignee_type;
                $assigneeId = $task->assignee_id ? (int) $task->assignee_id : null;

                return [
                    'id' => (int) $task->id,
                    'original_task_id' => $task->original_task_id ? (int) $task->original_task_id : null,
                    'date' => $task->date?->toDateString(),
                    'date_label' => $task->date?->format('d/m/Y') ?? '—',
                    'assignee_type' => $assigneeType,
                    'assignee_id' => $assigneeId,
                    'assignee_label_free' => $task->assignee_label_free,
                    'assignee_label' => $this->assigneeLabel($assigneeType, $assigneeId, $task->assignee_label_free, $assigneeLookup),
                    'assignee_meta' => $this->assigneeMeta($assigneeType),
                    'vehicle_label' => $task->vehicle_id ? ($vehicleLookup[(int) $task->vehicle_id] ?? "Véhicule #{$task->vehicle_id}") : null,
                    'task' => (string) ($task->task ?? ''),
                    'comment' => $task->comment,
                    'is_direct' => (bool) $task->is_direct,
                    'is_boursagri' => (bool) $task->is_boursagri,
                    'boursagri_contract_number' => $task->boursagri_contract_number,
                    'pointed' => (bool) $task->pointed,
                    'pointed_at' => $task->pointed_at?->toIso8601String(),
                    'pointed_at_label' => $task->pointed_at?->format('d/m/Y H:i') ?? null,
                    'archived_at' => $task->archived_at?->toIso8601String(),
                    'archived_at_label' => $task->archived_at?->format('d/m/Y H:i') ?? '—',
                    'archived_by_system' => (bool) $task->archived_by_system,
                    'position' => (int) $task->position,
                    'indicators' => is_array($task->indicators) ? $task->indicators : [],
                    'style' => $this->aprevoirService->applyColorRules([
                        'task' => (string) ($task->task ?? ''),
                        'comment' => (string) ($task->comment ?? ''),
                    ], $formattingRules),
                ];
            })
        );

        return Inertia::render('Tasks/Archive/Index', [
            'archives' => $archives,
            'filters' => [
                'date_from' => $validated['date_from'] ?? '',
                'date_to' => $validated['date_to'] ?? '',
                'search' => $validated['search'] ?? '',
                'assignee' => $validated['assignee'] ?? '',
                'contract' => $validated['contract'] ?? '',
                'direct' => $directFilter,
                'boursagri' => $boursagriFilter,
                'indicators' => $indicatorFilters,
                'per_page' => $perPage,
            ],
            'options' => [
                'assignees' => $this->buildAssigneeOptions(),
                'indicators' => $indicatorOptions,
                'per_page' => [25, 50, 100, 150],
            ],
        ]);
    }

    public function restore(AprevoirArchivedTask $archivedTask): RedirectResponse
    {
        $restoredTask = $this->archiveService->restoreArchivedTask($archivedTask);

        if (! $restoredTask) {
            return back()->withErrors([
                'archive_restore' => "La ligne archivée n'existe plus.",
            ]);
        }

        AprevoirTaskChanged::dispatch(
            'created',
            $restoredTask->id,
            null,
            AprevoirTaskChanged::snapshotFromTask($restoredTask),
            ['source' => 'archive_restore'],
        );

        $this->broadcastTaskRestored($restoredTask);

        return back()->with('status', 'Ligne restaurée dans À Prévoir.');
    }

    private function applyAssigneeFilter(Builder $query, string $raw): void
    {
        if ($raw === 'none') {
            $query->where(function (Builder $subQuery): void {
                $subQuery
                    ->whereNull('assignee_type')
                    ->orWhereNull('assignee_id');
            });

            return;
        }

        if (preg_match('/^free:(.+)$/', $raw, $matches)) {
            $label = trim($matches[1]);
            if ($label !== '') {
                $query
                    ->where('assignee_type', 'free')
                    ->where('assignee_label_free', $label);
            }

            return;
        }

        if (! preg_match('/^(user|transporter|depot):(\d+)$/', $raw, $matches)) {
            return;
        }

        $query
            ->where('assignee_type', $matches[1])
            ->where('assignee_id', (int) $matches[2]);
    }

    private function applySearchFilter(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';

        $query->where(function (Builder $subQuery) use ($like, $search): void {
            $subQuery
                ->where('task', 'like', $like)
                ->orWhere('comment', 'like', $like)
                ->orWhere('boursagri_contract_number', 'like', $like)
                ->orWhere('assignee_label_free', 'like', $like);

            if (is_numeric($search)) {
                $subQuery->orWhere('original_task_id', (int) $search);
            }

            $matchedUsers = User::query()
                ->where(function (Builder $userQuery) use ($like): void {
                    $userQuery
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('mobile_phone', 'like', $like);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($matchedUsers !== []) {
                $subQuery->orWhere(function (Builder $userAssigneeQuery) use ($matchedUsers): void {
                    $userAssigneeQuery
                        ->where('assignee_type', 'user')
                        ->whereIn('assignee_id', $matchedUsers);
                });
            }

            $matchedTransporters = Transporter::query()
                ->where(function (Builder $transporterQuery) use ($like): void {
                    $transporterQuery
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($matchedTransporters !== []) {
                $subQuery->orWhere(function (Builder $transporterAssigneeQuery) use ($matchedTransporters): void {
                    $transporterAssigneeQuery
                        ->where('assignee_type', 'transporter')
                        ->whereIn('assignee_id', $matchedTransporters);
                });
            }

            $matchedDepots = Depot::query()
                ->where(function (Builder $depotQuery) use ($like): void {
                    $depotQuery
                        ->where('name', 'like', $like)
                        ->orWhere('address_line1', 'like', $like)
                        ->orWhere('address_line2', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('postal_code', 'like', $like);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($matchedDepots !== []) {
                $subQuery->orWhere(function (Builder $depotAssigneeQuery) use ($matchedDepots): void {
                    $depotAssigneeQuery
                        ->where('assignee_type', 'depot')
                        ->whereIn('assignee_id', $matchedDepots);
                });
            }
        });
    }

    /**
     * @param  array<int,string>  $indicators
     */
    private function applyIndicatorFilters(Builder $query, array $indicators): void
    {
        foreach ($indicators as $indicator) {
            if ($indicator === '') {
                continue;
            }

            $query->whereRaw(
                "JSON_CONTAINS_PATH(indicators, 'one', ?) = 1",
                [$this->jsonPathFromDot($indicator)]
            );
        }
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function buildIndicatorOptions(Builder $baseQuery): array
    {
        $paths = [];

        $rows = (clone $baseQuery)
            ->select(['id', 'indicators'])
            ->whereNotNull('indicators')
            ->get();

        foreach ($rows as $row) {
            $this->extractIndicatorPaths($row->indicators, '', $paths);
        }

        if ($paths === []) {
            return [];
        }

        asort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            static fn (string $path, string $label): array => [
                'value' => $path,
                'label' => $label,
            ],
            array_keys($paths),
            array_values($paths),
        );
    }

    /**
     * @param  mixed  $value
     * @param  array<string,string>  $paths
     */
    private function extractIndicatorPaths(mixed $value, string $path, array &$paths): void
    {
        if ($path !== '' && str_starts_with(strtolower($path), 'auto_detected')) {
            return;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    $this->extractIndicatorPaths($item, $path, $paths);
                }

                return;
            }

            foreach ($value as $key => $child) {
                $segment = trim((string) $key);
                if ($segment === '') {
                    continue;
                }

                $nextPath = $path !== '' ? "{$path}.{$segment}" : $segment;
                $this->extractIndicatorPaths($child, $nextPath, $paths);
            }

            return;
        }

        if ($value === null || $value === false) {
            return;
        }

        if (is_string($value) && trim($value) === '') {
            return;
        }

        if ($path === '') {
            return;
        }

        if (! isset($paths[$path])) {
            $paths[$path] = $this->prettyIndicatorKey($path);
        }
    }

    private function jsonPathFromDot(string $dotPath): string
    {
        $segments = array_filter(array_map(
            static fn (string $segment): string => trim($segment),
            explode('.', $dotPath),
        ));

        if ($segments === []) {
            return '$';
        }

        $path = '$';
        foreach ($segments as $segment) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $segment);
            $path .= '."'.$escaped.'"';
        }

        return $path;
    }

    private function prettyIndicatorKey(string $raw): string
    {
        return (string) preg_replace_callback(
            '/\b\w/u',
            static fn (array $matches): string => mb_strtoupper($matches[0], 'UTF-8'),
            trim(preg_replace('/\s+/u', ' ', str_replace(['.', '_'], ' ', $raw)) ?? '')
        );
    }

    /**
     * @return array{user: array<int,string>, transporter: array<int,string>, depot: array<int,string>}
     */
    private function buildAssigneeLookup(Collection $tasks): array
    {
        $userIds = [];
        $transporterIds = [];
        $depotIds = [];

        foreach ($tasks as $task) {
            $type = $task->assignee_type;
            $id = $task->assignee_id ? (int) $task->assignee_id : null;

            if (! $id || ! $type) {
                continue;
            }

            if ($type === 'user') {
                $userIds[] = $id;
            } elseif ($type === 'transporter') {
                $transporterIds[] = $id;
            } elseif ($type === 'depot') {
                $depotIds[] = $id;
            }
        }

        $userLookup = User::query()
            ->whereIn('id', array_values(array_unique($userIds)))
            ->get(['id', 'name', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(function (User $user): array {
                return [(int) $user->id => $this->userLabel($user)];
            })
            ->all();

        $transporterLookup = Transporter::query()
            ->whereIn('id', array_values(array_unique($transporterIds)))
            ->get(['id', 'first_name', 'last_name', 'company_name'])
            ->mapWithKeys(function (Transporter $transporter): array {
                return [(int) $transporter->id => $this->transporterLabel($transporter)];
            })
            ->all();

        $depotLookup = Depot::query()
            ->whereIn('id', array_values(array_unique($depotIds)))
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Depot $depot): array => [(int) $depot->id => (string) $depot->name])
            ->all();

        return [
            'user' => $userLookup,
            'transporter' => $transporterLookup,
            'depot' => $depotLookup,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function buildVehicleLookup(Collection $tasks): array
    {
        $vehicleIds = $tasks
            ->pluck('vehicle_id')
            ->filter(fn ($id) => ! empty($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($vehicleIds === []) {
            return [];
        }

        return Vehicle::query()
            ->whereIn('id', $vehicleIds)
            ->get(['id', 'name', 'registration'])
            ->mapWithKeys(function (Vehicle $vehicle): array {
                $parts = array_values(array_filter([
                    trim((string) ($vehicle->name ?? '')),
                    trim((string) ($vehicle->registration ?? '')),
                ]));

                $label = $parts !== [] ? implode(' • ', $parts) : "Véhicule #{$vehicle->id}";

                return [(int) $vehicle->id => $label];
            })
            ->all();
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function buildAssigneeOptions(): array
    {
        $pairs = AprevoirArchivedTask::query()
            ->select(['assignee_type', 'assignee_id'])
            ->whereNotNull('assignee_type')
            ->whereNotNull('assignee_id')
            ->distinct()
            ->get();

        $userIds = [];
        $transporterIds = [];
        $depotIds = [];

        foreach ($pairs as $pair) {
            $id = (int) $pair->assignee_id;
            if ($id <= 0) {
                continue;
            }

            if ($pair->assignee_type === 'user') {
                $userIds[] = $id;
            } elseif ($pair->assignee_type === 'transporter') {
                $transporterIds[] = $id;
            } elseif ($pair->assignee_type === 'depot') {
                $depotIds[] = $id;
            }
        }

        $users = User::query()
            ->whereIn('id', array_values(array_unique($userIds)))
            ->get(['id', 'name', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(fn (User $user): array => [(int) $user->id => $this->userLabel($user)])
            ->all();

        $transporters = Transporter::query()
            ->whereIn('id', array_values(array_unique($transporterIds)))
            ->get(['id', 'first_name', 'last_name', 'company_name'])
            ->mapWithKeys(fn (Transporter $transporter): array => [(int) $transporter->id => $this->transporterLabel($transporter)])
            ->all();

        $depots = Depot::query()
            ->whereIn('id', array_values(array_unique($depotIds)))
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Depot $depot): array => [(int) $depot->id => (string) $depot->name])
            ->all();

        $freeLabels = AprevoirArchivedTask::query()
            ->where('assignee_type', 'free')
            ->whereNotNull('assignee_label_free')
            ->pluck('assignee_label_free')
            ->map(fn ($label) => trim((string) $label))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $options = [];

        foreach ($pairs as $pair) {
            $id = (int) $pair->assignee_id;
            $type = (string) $pair->assignee_type;
            if ($id <= 0 || ! in_array($type, ['user', 'transporter', 'depot'], true)) {
                continue;
            }

            $label = match ($type) {
                'user' => $users[$id] ?? "Utilisateur #{$id}",
                'transporter' => $transporters[$id] ?? "Transporteur #{$id}",
                'depot' => $depots[$id] ?? "Dépôt #{$id}",
                default => "Assigné #{$id}",
            };

            $options["{$type}:{$id}"] = [
                'value' => "{$type}:{$id}",
                'label' => $label,
            ];
        }

        foreach ($freeLabels as $label) {
            $options["free:{$label}"] = [
                'value' => "free:{$label}",
                'label' => $label,
            ];
        }

        uasort($options, fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        $hasUnassigned = AprevoirArchivedTask::query()
            ->where(function (Builder $query): void {
                $query->whereNull('assignee_type')->orWhereNull('assignee_id');
            })
            ->exists();

        return [
            ...($hasUnassigned ? [['value' => 'none', 'label' => 'Sans assigné']] : []),
            ...array_values($options),
        ];
    }

    /**
     * @param  array{user: array<int,string>, transporter: array<int,string>, depot: array<int,string>}  $lookup
     */
    private function assigneeLabel(?string $type, ?int $id, ?string $freeLabel, array $lookup): string
    {
        if ($type === 'free') {
            $label = trim((string) ($freeLabel ?? ''));
            return $label !== '' ? $label : 'Chauffeur libre';
        }

        if (! $type || ! $id) {
            return 'Sans assigné';
        }

        return match ($type) {
            'user' => $lookup['user'][$id] ?? "Utilisateur #{$id}",
            'transporter' => $lookup['transporter'][$id] ?? "Transporteur #{$id}",
            'depot' => $lookup['depot'][$id] ?? "Dépôt #{$id}",
            default => "Assigné #{$id}",
        };
    }

    private function assigneeMeta(?string $type): string
    {
        return match ($type) {
            'user' => 'Ets Jeudy',
            'transporter' => 'Transporteur',
            'depot' => 'Dépôt',
            'free' => 'Chauffeur libre',
            default => 'Sans assigné',
        };
    }

    private function broadcastTaskRestored(AprevoirTask $task): void
    {
        try {
            AprevoirTaskUpdated::dispatch(
                (int) $task->id,
                $task->date?->toDateString(),
                $task->assignee_type,
                $task->assignee_id !== null ? (int) $task->assignee_id : null,
                'created',
            );
        } catch (Throwable $exception) {
            Log::warning('Archive restore realtime broadcast failed.', [
                'task_id' => $task->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function userLabel(User $user): string
    {
        $fullName = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

        return $fullName !== '' ? $fullName : (string) ($user->name ?: $user->email ?: "Utilisateur #{$user->id}");
    }

    private function transporterLabel(Transporter $transporter): string
    {
        $fullName = trim((string) (($transporter->first_name ?? '').' '.($transporter->last_name ?? '')));
        $company = trim((string) ($transporter->company_name ?? ''));

        if ($fullName !== '' && $company !== '') {
            return "{$fullName} ({$company})";
        }

        if ($fullName !== '') {
            return $fullName;
        }

        if ($company !== '') {
            return $company;
        }

        return "Transporteur #{$transporter->id}";
    }
}
