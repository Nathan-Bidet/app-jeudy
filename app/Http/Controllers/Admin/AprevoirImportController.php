<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AprevoirTask;
use App\Models\Depot;
use App\Models\ImportDriverFreeMapping;
use App\Models\ImportLdtPlan;
use App\Models\ImportUserMapping;
use App\Models\ImportVehicleMapping;
use App\Models\Transporter;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AprevoirImportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $stats = [
            'total_rows' => ImportLdtPlan::query()->count(),
            'pending_rows' => ImportLdtPlan::query()->whereNull('imported_at')->count(),
            'imported_rows' => ImportLdtPlan::query()->whereNotNull('imported_at')->count(),
        ];

        $internalUsers = User::query()
            ->orderByRaw('COALESCE(last_name, name) asc')
            ->orderByRaw('COALESCE(first_name, name) asc')
            ->get(['id', 'name', 'first_name', 'last_name', 'email']);

        $driverOptions = collect();

        $driverOptions = $driverOptions->merge(
            $internalUsers->map(fn (User $user): array => [
                'id' => $this->mappingOptionId('user', (int) $user->id),
                'label' => '[Personnel] '.$this->userLabel($user),
                'type' => 'user',
                'source' => 'personnels_jeudy',
                'target_type' => 'user',
                'target_id' => (int) $user->id,
            ])
        );

        $transporters = Transporter::query()
            ->where('is_active', true)
            ->orderByRaw('COALESCE(company_name, last_name, first_name) asc')
            ->get(['id', 'company_name', 'first_name', 'last_name']);

        $driverOptions = $driverOptions->merge(
            $transporters->map(fn (Transporter $transporter): array => [
                'id' => $this->mappingOptionId('transporter', (int) $transporter->id),
                'label' => '[Transporteur] '.$this->transporterLabel($transporter),
                'search_text' => $this->transporterSearchText($transporter),
                'type' => 'transporter',
                'source' => 'transporteurs',
                'target_type' => 'transporter',
                'target_id' => (int) $transporter->id,
            ])
        );

        $depots = Depot::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $driverOptions = $driverOptions->merge(
            $depots->map(fn (Depot $depot): array => [
                'id' => $this->mappingOptionId('depot', (int) $depot->id),
                'label' => '[Dépôt] '.trim((string) $depot->name),
                'type' => 'depot',
                'source' => 'depots',
                'target_type' => 'depot',
                'target_id' => (int) $depot->id,
            ])
        )->values();

        $userOptions = $internalUsers
            ->map(fn (User $user): array => [
                'id' => $this->mappingOptionId('user', (int) $user->id),
                'label' => $this->userLabel($user),
                'type' => 'user',
                'source' => 'users',
                'target_type' => 'user',
                'target_id' => (int) $user->id,
            ])
            ->values();

        $vehicleOptions = Vehicle::query()
            ->with('type:id,code,label')
            ->where('is_active', true)
            ->orderByRaw('COALESCE(name, registration) asc')
            ->get(['id', 'name', 'registration', 'vehicle_type_id'])
            ->map(function (Vehicle $vehicle): ?array {
                $source = $this->vehicleSource(
                    (string) ($vehicle->type?->code ?? ''),
                    (string) ($vehicle->type?->label ?? '')
                );

                if ($source === null) {
                    return null;
                }

                return [
                    'id' => $this->mappingOptionId('vehicle', (int) $vehicle->id),
                    'label' => '['.$source['prefix'].'] '.$this->vehicleLabel($vehicle),
                    'type' => 'vehicle',
                    'source' => $source['source'],
                    'target_type' => 'vehicle',
                    'target_id' => (int) $vehicle->id,
                ];
            })
            ->filter()
            ->values();

        $driverMappings = ImportUserMapping::query()
            ->where('source_column', 'driver_id')
            ->get(['old_user_id', 'new_user_id', 'target_type', 'target_id'])
            ->mapWithKeys(fn (ImportUserMapping $mapping): array => [
                (string) $mapping->old_user_id => $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id),
            ])
            ->all();

        $createdByMappings = ImportUserMapping::query()
            ->where('source_column', 'created_by')
            ->get(['old_user_id', 'new_user_id', 'target_type', 'target_id'])
            ->mapWithKeys(fn (ImportUserMapping $mapping): array => [
                (string) $mapping->old_user_id => $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id),
            ])
            ->all();

        $updatedByMappings = ImportUserMapping::query()
            ->where('source_column', 'updated_by')
            ->get(['old_user_id', 'new_user_id', 'target_type', 'target_id'])
            ->mapWithKeys(fn (ImportUserMapping $mapping): array => [
                (string) $mapping->old_user_id => $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id),
            ])
            ->all();

        $vehicleMappings = ImportVehicleMapping::query()
            ->get(['old_vehicle_id', 'new_vehicle_id', 'target_type', 'target_id'])
            ->mapWithKeys(fn (ImportVehicleMapping $mapping): array => [
                (string) $mapping->old_vehicle_id => $this->normalizeVehicleTarget($mapping->target_type, $mapping->target_id, $mapping->new_vehicle_id),
            ])
            ->all();

        $driverFreeMappings = ImportDriverFreeMapping::query()
            ->get(['old_driver_free', 'new_user_id', 'target_type', 'target_id'])
            ->mapWithKeys(fn (ImportDriverFreeMapping $mapping): array => [
                (string) $mapping->old_driver_free => $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id),
            ])
            ->all();

        $driverIdEntries = ImportLdtPlan::query()
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id as old_user_id, MAX(driver_free) as driver_free')
            ->groupBy('driver_id')
            ->orderBy('driver_id')
            ->get()
            ->map(function ($row) use ($driverMappings): array {
                $target = $driverMappings[(string) $row->old_user_id] ?? null;

                return [
                    'old_user_id' => (int) $row->old_user_id,
                    'driver_free' => $row->driver_free,
                    'mapped_target_type' => $target['target_type'] ?? null,
                    'mapped_target_id' => $target['target_id'] ?? null,
                    'mapped_option_id' => $target
                        ? $this->mappingOptionId((string) $target['target_type'], (int) $target['target_id'])
                        : null,
                ];
            })
            ->values()
            ->all();

        $driverFreeEntries = ImportLdtPlan::query()
            ->whereNotNull('driver_free')
            ->whereRaw("TRIM(driver_free) <> ''")
            ->selectRaw('driver_free as old_driver_free')
            ->distinct()
            ->orderBy('driver_free')
            ->get()
            ->map(function ($row) use ($driverFreeMappings): array {
                $target = $driverFreeMappings[(string) $row->old_driver_free] ?? null;

                return [
                    'old_driver_free' => (string) $row->old_driver_free,
                    'mapped_target_type' => $target['target_type'] ?? null,
                    'mapped_target_id' => $target['target_id'] ?? null,
                    'mapped_option_id' => $target
                        ? $this->mappingOptionId((string) $target['target_type'], (int) $target['target_id'])
                        : null,
                ];
            })
            ->values()
            ->all();

        $vehicleEntries = ImportLdtPlan::query()
            ->whereNotNull('vehicle_id')
            ->selectRaw('vehicle_id as old_vehicle_id, MAX(vehicle_free) as vehicle_free')
            ->groupBy('vehicle_id')
            ->orderBy('vehicle_id')
            ->get()
            ->map(function ($row) use ($vehicleMappings): array {
                $target = $vehicleMappings[(string) $row->old_vehicle_id] ?? null;

                return [
                    'old_vehicle_id' => (int) $row->old_vehicle_id,
                    'vehicle_free' => $row->vehicle_free,
                    'mapped_target_type' => $target['target_type'] ?? null,
                    'mapped_target_id' => $target['target_id'] ?? null,
                    'mapped_option_id' => $target
                        ? $this->mappingOptionId((string) $target['target_type'], (int) $target['target_id'])
                        : null,
                ];
            })
            ->values()
            ->all();

        $createdByEntries = ImportLdtPlan::query()
            ->whereNotNull('created_by')
            ->selectRaw('created_by as old_user_id')
            ->distinct()
            ->orderBy('created_by')
            ->get()
            ->map(function ($row) use ($createdByMappings): array {
                $target = $createdByMappings[(string) $row->old_user_id] ?? null;

                return [
                    'old_user_id' => (int) $row->old_user_id,
                    'mapped_target_type' => $target['target_type'] ?? null,
                    'mapped_target_id' => $target['target_id'] ?? null,
                    'mapped_option_id' => $target
                        ? $this->mappingOptionId((string) $target['target_type'], (int) $target['target_id'])
                        : null,
                ];
            })
            ->values()
            ->all();

        $updatedByEntries = ImportLdtPlan::query()
            ->whereNotNull('updated_by')
            ->selectRaw('updated_by as old_user_id')
            ->distinct()
            ->orderBy('updated_by')
            ->get()
            ->map(function ($row) use ($updatedByMappings): array {
                $target = $updatedByMappings[(string) $row->old_user_id] ?? null;

                return [
                    'old_user_id' => (int) $row->old_user_id,
                    'mapped_target_type' => $target['target_type'] ?? null,
                    'mapped_target_id' => $target['target_id'] ?? null,
                    'mapped_option_id' => $target
                        ? $this->mappingOptionId((string) $target['target_type'], (int) $target['target_id'])
                        : null,
                ];
            })
            ->values()
            ->all();

        $missingMappings = [
            'driver_id' => collect($driverIdEntries)
                ->filter(fn (array $entry): bool => empty($entry['mapped_option_id']))
                ->values()
                ->all(),
            'driver_free' => collect($driverFreeEntries)
                ->filter(fn (array $entry): bool => empty($entry['mapped_option_id']))
                ->values()
                ->all(),
            'vehicle_id' => collect($vehicleEntries)
                ->filter(fn (array $entry): bool => empty($entry['mapped_option_id']))
                ->values()
                ->all(),
            'created_by' => collect($createdByEntries)
                ->filter(fn (array $entry): bool => empty($entry['mapped_option_id']))
                ->values()
                ->all(),
            'updated_by' => collect($updatedByEntries)
                ->filter(fn (array $entry): bool => empty($entry['mapped_option_id']))
                ->values()
                ->all(),
        ];

        $canImport = collect($missingMappings)->every(
            fn (array $entries): bool => count($entries) === 0
        );

        return Inertia::render('Admin/AprevoirImport/Index', [
            'stats' => $stats,
            'driverMappingOptions' => $driverOptions->all(),
            'userMappingOptions' => $userOptions->all(),
            'vehicleMappingOptions' => $vehicleOptions->all(),
            'driverIdEntries' => $driverIdEntries,
            'driverFreeEntries' => $driverFreeEntries,
            'vehicleEntries' => $vehicleEntries,
            'createdByEntries' => $createdByEntries,
            'updatedByEntries' => $updatedByEntries,
            'missingMappings' => $missingMappings,
            'canImport' => $canImport,
            'importReport' => $request->session()->get('aprevoir_import_report'),
        ]);
    }

    public function updateMappings(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'user_mappings' => ['nullable', 'array'],
            'user_mappings.*.old_user_id' => ['required', 'integer'],
            'user_mappings.*.source_column' => ['required', 'in:driver_id,created_by,updated_by'],
            'user_mappings.*.new_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'user_mappings.*.target_type' => ['nullable', 'in:user,transporter,depot'],
            'user_mappings.*.target_id' => ['nullable', 'integer', 'min:1'],

            'vehicle_mappings' => ['nullable', 'array'],
            'vehicle_mappings.*.old_vehicle_id' => ['required', 'integer'],
            'vehicle_mappings.*.old_vehicle_free' => ['nullable', 'string', 'max:255'],
            'vehicle_mappings.*.new_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'vehicle_mappings.*.target_type' => ['nullable', 'in:vehicle'],
            'vehicle_mappings.*.target_id' => ['nullable', 'integer', 'min:1'],

            'driver_free_mappings' => ['nullable', 'array'],
            'driver_free_mappings.*.old_driver_free' => ['required', 'string', 'max:255'],
            'driver_free_mappings.*.new_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'driver_free_mappings.*.target_type' => ['nullable', 'in:user,transporter,depot'],
            'driver_free_mappings.*.target_id' => ['nullable', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['user_mappings'] ?? [] as $index => $row) {
                $oldUserId = (int) $row['old_user_id'];
                $sourceColumn = (string) $row['source_column'];
                [$targetType, $targetId] = $this->resolveTarget($row, 'new_user_id', 'user');

                if ($targetType === null || $targetId === null) {
                    ImportUserMapping::query()
                        ->where('old_user_id', $oldUserId)
                        ->where('source_column', $sourceColumn)
                        ->delete();
                    continue;
                }

                $allowedTypes = $sourceColumn === 'driver_id'
                    ? ['user', 'transporter', 'depot']
                    : ['user'];

                if (! in_array($targetType, $allowedTypes, true) || ! $this->targetExists($targetType, $targetId)) {
                    throw ValidationException::withMessages([
                        "user_mappings.{$index}.target_id" => 'Correspondance invalide.',
                    ]);
                }

                ImportUserMapping::query()->updateOrCreate(
                    [
                        'old_user_id' => $oldUserId,
                        'source_column' => $sourceColumn,
                    ],
                    [
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'new_user_id' => $targetType === 'user' ? $targetId : null,
                    ],
                );
            }

            foreach ($validated['vehicle_mappings'] ?? [] as $index => $row) {
                $oldVehicleId = (int) $row['old_vehicle_id'];
                [$targetType, $targetId] = $this->resolveTarget($row, 'new_vehicle_id', 'vehicle');

                if ($targetType === null || $targetId === null) {
                    ImportVehicleMapping::query()
                        ->where('old_vehicle_id', $oldVehicleId)
                        ->delete();
                    continue;
                }

                if ($targetType !== 'vehicle' || ! $this->targetExists($targetType, $targetId)) {
                    throw ValidationException::withMessages([
                        "vehicle_mappings.{$index}.target_id" => 'Correspondance véhicule invalide.',
                    ]);
                }

                ImportVehicleMapping::query()->updateOrCreate(
                    ['old_vehicle_id' => $oldVehicleId],
                    [
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'new_vehicle_id' => $targetId,
                        'old_vehicle_free' => $row['old_vehicle_free'] ?? null,
                    ],
                );
            }

            foreach ($validated['driver_free_mappings'] ?? [] as $index => $row) {
                $oldDriverFree = trim((string) $row['old_driver_free']);
                [$targetType, $targetId] = $this->resolveTarget($row, 'new_user_id', 'user');

                if ($oldDriverFree === '') {
                    continue;
                }

                if ($targetType === null || $targetId === null) {
                    ImportDriverFreeMapping::query()
                        ->where('old_driver_free', $oldDriverFree)
                        ->delete();
                    continue;
                }

                if (! in_array($targetType, ['user', 'transporter', 'depot'], true) || ! $this->targetExists($targetType, $targetId)) {
                    throw ValidationException::withMessages([
                        "driver_free_mappings.{$index}.target_id" => 'Correspondance driver_free invalide.',
                    ]);
                }

                ImportDriverFreeMapping::query()->updateOrCreate(
                    ['old_driver_free' => $oldDriverFree],
                    [
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'new_user_id' => $targetType === 'user' ? $targetId : null,
                    ],
                );
            }
        });

        return back()->with('success', 'Mappings import À prévoir enregistrés.');
    }

    public function import(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $batchId = (string) Str::uuid();
        $actorId = (int) ($request->user()?->id ?? 0);

        $context = $this->buildImportContext();
        $rows = ImportLdtPlan::query()
            ->whereNull('imported_at')
            ->orderBy('id')
            ->get();

        $report = [
            'dry_run' => $dryRun,
            'batch_id' => $batchId,
            'pending_total' => $rows->count(),
            'importable_count' => 0,
            'imported_count' => 0,
            'error_count' => 0,
            'errors' => [],
        ];

        foreach ($rows as $row) {
            [$payload, $errors] = $this->buildAprevoirPayload($row, $context, $actorId);

            if ($errors !== []) {
                $message = implode(' | ', $errors);
                $report['error_count']++;

                if (count($report['errors']) < 100) {
                    $report['errors'][] = [
                        'row_id' => (int) $row->id,
                        'legacy_id' => $row->legacy_id ? (int) $row->legacy_id : null,
                        'error' => $message,
                    ];
                }

                if (! $dryRun) {
                    $row->forceFill([
                        'import_batch_id' => $batchId,
                        'import_error' => $message,
                    ])->save();
                }

                continue;
            }

            $report['importable_count']++;

            if ($dryRun) {
                continue;
            }

            try {
                $task = new AprevoirTask();
                $task->timestamps = false;
                $task->forceFill($payload);
                $task->save();

                $row->forceFill([
                    'imported_at' => now(),
                    'import_batch_id' => $batchId,
                    'import_error' => null,
                ])->save();

                $report['imported_count']++;
            } catch (Throwable $e) {
                $report['error_count']++;
                $message = 'Erreur import final: '.$e->getMessage();

                if (count($report['errors']) < 100) {
                    $report['errors'][] = [
                        'row_id' => (int) $row->id,
                        'legacy_id' => $row->legacy_id ? (int) $row->legacy_id : null,
                        'error' => $message,
                    ];
                }

                $row->forceFill([
                    'import_batch_id' => $batchId,
                    'import_error' => $message,
                ])->save();
            }
        }

        $message = $dryRun
            ? sprintf(
                'Dry-run terminé. En attente: %d, importables: %d, erreurs: %d.',
                $report['pending_total'],
                $report['importable_count'],
                $report['error_count'],
            )
            : sprintf(
                'Import final terminé. En attente: %d, importées: %d, erreurs: %d.',
                $report['pending_total'],
                $report['imported_count'],
                $report['error_count'],
            );

        return redirect()
            ->route('admin.aprevoir-import.index')
            ->with('success', $message)
            ->with('aprevoir_import_report', $report);
    }

    public function loadLegacyData(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        if (! Schema::hasTable('ldt_plan')) {
            return back()->withErrors([
                'legacy' => "La table legacy 'ldt_plan' est introuvable dans la base actuelle.",
            ]);
        }

        $batchId = (string) Str::uuid();
        $totalRead = 0;
        $totalInserted = 0;

        DB::table('ldt_plan')
            ->select([
                'id',
                'date_day',
                'driver_id',
                'driver_free',
                'vehicle_id',
                'vehicle_free',
                'task',
                'comments',
                'flag_paper',
                'flag_direct',
                'flag_boursagri',
                'boursagri_contract',
                'fin',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
                'sort_order',
                'sms_livre',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalRead, &$totalInserted, $batchId): void {
                $payload = [];

                foreach ($rows as $row) {
                    $totalRead++;
                    $payload[] = [
                        'legacy_id' => (int) $row->id,
                        'date_day' => $row->date_day,
                        'driver_id' => $row->driver_id,
                        'driver_free' => $row->driver_free,
                        'vehicle_id' => $row->vehicle_id,
                        'vehicle_free' => $row->vehicle_free,
                        'task' => $row->task,
                        'comments' => $row->comments,
                        'flag_paper' => $row->flag_paper,
                        'flag_direct' => $row->flag_direct,
                        'flag_boursagri' => $row->flag_boursagri,
                        'boursagri_contract' => $row->boursagri_contract,
                        'fin' => $row->fin,
                        'created_by' => $row->created_by,
                        'updated_by' => $row->updated_by,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                        'sort_order' => $row->sort_order,
                        'sms_livre' => $row->sms_livre,
                        'imported_at' => null,
                        'import_error' => null,
                        'import_batch_id' => $batchId,
                    ];
                }

                if ($payload !== []) {
                    $totalInserted += DB::table('import_ldt_plan')->insertOrIgnore($payload);
                }
            }, 'id');

        $totalSkipped = max(0, $totalRead - $totalInserted);

        return back()->with(
            'success',
            sprintf(
                'Chargement legacy terminé. Lues: %d, insérées: %d, ignorées (doublons): %d.',
                $totalRead,
                $totalInserted,
                $totalSkipped
            )
        );
    }

    /**
     * @return array{
     *   assignees: array{
     *     by_driver_id: array<int, array{target_type:string,target_id:int}>,
     *     by_driver_free: array<string, array{target_type:string,target_id:int}>
     *   },
     *   vehicles: array<int, array{target_type:string,target_id:int}>,
     *   users: array{
     *     by_created_by: array<int, array{target_type:string,target_id:int}>,
     *     by_updated_by: array<int, array{target_type:string,target_id:int}>
     *   }
     * }
     */
    private function buildImportContext(): array
    {
        $driverIdMappings = ImportUserMapping::query()
            ->where('source_column', 'driver_id')
            ->get(['old_user_id', 'target_type', 'target_id', 'new_user_id'])
            ->reduce(function (array $carry, ImportUserMapping $mapping): array {
                $target = $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id);
                if ($target) {
                    $carry[(int) $mapping->old_user_id] = $target;
                }

                return $carry;
            }, []);

        $driverFreeMappings = ImportDriverFreeMapping::query()
            ->get(['old_driver_free', 'target_type', 'target_id', 'new_user_id'])
            ->reduce(function (array $carry, ImportDriverFreeMapping $mapping): array {
                $target = $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id);
                if ($target) {
                    $carry[(string) $mapping->old_driver_free] = $target;
                }

                return $carry;
            }, []);

        $vehicleMappings = ImportVehicleMapping::query()
            ->get(['old_vehicle_id', 'target_type', 'target_id', 'new_vehicle_id'])
            ->reduce(function (array $carry, ImportVehicleMapping $mapping): array {
                $target = $this->normalizeVehicleTarget($mapping->target_type, $mapping->target_id, $mapping->new_vehicle_id);
                if ($target) {
                    $carry[(int) $mapping->old_vehicle_id] = $target;
                }

                return $carry;
            }, []);

        $createdByMappings = ImportUserMapping::query()
            ->where('source_column', 'created_by')
            ->get(['old_user_id', 'target_type', 'target_id', 'new_user_id'])
            ->reduce(function (array $carry, ImportUserMapping $mapping): array {
                $target = $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id);
                if ($target) {
                    $carry[(int) $mapping->old_user_id] = $target;
                }

                return $carry;
            }, []);

        $updatedByMappings = ImportUserMapping::query()
            ->where('source_column', 'updated_by')
            ->get(['old_user_id', 'target_type', 'target_id', 'new_user_id'])
            ->reduce(function (array $carry, ImportUserMapping $mapping): array {
                $target = $this->normalizeUserTarget($mapping->target_type, $mapping->target_id, $mapping->new_user_id);
                if ($target) {
                    $carry[(int) $mapping->old_user_id] = $target;
                }

                return $carry;
            }, []);

        return [
            'assignees' => [
                'by_driver_id' => $driverIdMappings,
                'by_driver_free' => $driverFreeMappings,
            ],
            'vehicles' => $vehicleMappings,
            'users' => [
                'by_created_by' => $createdByMappings,
                'by_updated_by' => $updatedByMappings,
            ],
        ];
    }

    /**
     * @param array{
     *   assignees: array{
     *     by_driver_id: array<int, array{target_type:string,target_id:int}>,
     *     by_driver_free: array<string, array{target_type:string,target_id:int}>
     *   },
     *   vehicles: array<int, array{target_type:string,target_id:int}>,
     *   users: array{
     *     by_created_by: array<int, array{target_type:string,target_id:int}>,
     *     by_updated_by: array<int, array{target_type:string,target_id:int}>
     *   }
     * } $context
     * @return array{0:?array<string,mixed>,1:array<int,string>}
     */
    private function buildAprevoirPayload(ImportLdtPlan $row, array $context, int $actorId): array
    {
        $errors = [];

        $date = $row->date_day?->toDateString();
        if (! $date) {
            $errors[] = 'date_day manquante';
        }

        $task = trim((string) ($row->task ?? ''));
        if ($task === '') {
            $errors[] = 'task manquant';
        }

        $assigneeType = null;
        $assigneeId = null;
        $assigneeLabelFree = null;

        if (! empty($row->driver_id)) {
            $target = $context['assignees']['by_driver_id'][(int) $row->driver_id] ?? null;
            if (! $target) {
                $errors[] = 'mapping driver_id manquant';
            } else {
                $assigneeType = (string) $target['target_type'];
                $assigneeId = (int) $target['target_id'];
            }
        } elseif (filled($row->driver_free)) {
            $driverFree = trim((string) $row->driver_free);
            $target = $context['assignees']['by_driver_free'][$driverFree] ?? null;
            if (! $target) {
                $errors[] = 'mapping driver_free manquant';
            } else {
                $assigneeType = (string) $target['target_type'];
                $assigneeId = (int) $target['target_id'];
            }
        }

        if ($assigneeType !== null && ! in_array($assigneeType, ['user', 'transporter', 'depot', 'free'], true)) {
            $errors[] = 'type d’assignataire invalide';
        }

        if ($assigneeType === 'free') {
            $assigneeLabelFree = filled($row->driver_free) ? trim((string) $row->driver_free) : null;
            $assigneeId = null;
        }

        $vehicleId = null;
        if (! empty($row->vehicle_id)) {
            $vehicleTarget = $context['vehicles'][(int) $row->vehicle_id] ?? null;
            if (! $vehicleTarget) {
                $errors[] = 'mapping vehicle_id manquant';
            } elseif (($vehicleTarget['target_type'] ?? null) !== 'vehicle') {
                $errors[] = 'mapping vehicle_id invalide';
            } else {
                $vehicleId = (int) $vehicleTarget['target_id'];
            }
        }

        $createdBy = null;
        if (! empty($row->created_by)) {
            $target = $context['users']['by_created_by'][(int) $row->created_by] ?? null;
            if (! $target || ($target['target_type'] ?? null) !== 'user') {
                $errors[] = 'mapping created_by manquant ou invalide';
            } else {
                $createdBy = (int) $target['target_id'];
            }
        } else {
            $createdBy = $actorId > 0 ? $actorId : null;
        }

        $updatedBy = null;
        if (! empty($row->updated_by)) {
            $target = $context['users']['by_updated_by'][(int) $row->updated_by] ?? null;
            if (! $target || ($target['target_type'] ?? null) !== 'user') {
                $errors[] = 'mapping updated_by manquant ou invalide';
            } else {
                $updatedBy = (int) $target['target_id'];
            }
        }

        if ($createdBy === null) {
            $errors[] = 'created_by_user_id indisponible';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $createdAt = $row->created_at ?? now();
        $updatedAt = $row->updated_at ?? $createdAt;
        $finDate = null;
        if (filled($row->fin)) {
            try {
                $finDate = Carbon::parse((string) $row->fin)->toDateString();
            } catch (Throwable) {
                $finDate = null;
            }
        }

        return [[
            'date' => $date,
            'fin_date' => $finDate,
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
            'assignee_label_free' => $assigneeLabelFree,
            'vehicle_id' => $vehicleId,
            'remorque_id' => null,
            'task' => $task,
            'loading_place' => null,
            'delivery_place' => null,
            'comment' => filled($row->comments) ? trim((string) $row->comments) : null,
            'is_direct' => (bool) $row->flag_direct,
            'is_boursagri' => (bool) $row->flag_boursagri,
            'boursagri_contract_number' => filled($row->boursagri_contract) ? trim((string) $row->boursagri_contract) : null,
            'indicators' => [
                'legacy' => [
                    'legacy_id' => $row->legacy_id ? (int) $row->legacy_id : null,
                    'flag_paper' => $row->flag_paper,
                    'sms_livre' => $row->sms_livre,
                    'sort_order' => $row->sort_order,
                    'vehicle_free' => $row->vehicle_free,
                    'driver_free' => $row->driver_free,
                ],
            ],
            'pointed' => false,
            'pointed_at' => null,
            'pointed_by_user_id' => null,
            'position' => (int) ($row->sort_order ?? 0),
            'created_by_user_id' => $createdBy,
            'updated_by_user_id' => $updatedBy,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ], []];
    }

    private function resolveTarget(array $row, string $legacyIdField, string $legacyTargetType): array
    {
        $targetType = isset($row['target_type']) && $row['target_type'] !== ''
            ? (string) $row['target_type']
            : null;

        $targetId = isset($row['target_id']) && $row['target_id'] !== ''
            ? (int) $row['target_id']
            : null;

        if (($targetType === null || $targetId === null) && isset($row[$legacyIdField]) && $row[$legacyIdField] !== null && $row[$legacyIdField] !== '') {
            $targetType = $legacyTargetType;
            $targetId = (int) $row[$legacyIdField];
        }

        if ($targetType === null || $targetId === null || $targetId <= 0) {
            return [null, null];
        }

        return [$targetType, $targetId];
    }

    private function targetExists(string $targetType, int $targetId): bool
    {
        return match ($targetType) {
            'user' => User::query()->whereKey($targetId)->exists(),
            'transporter' => Transporter::query()->whereKey($targetId)->exists(),
            'depot' => Depot::query()->whereKey($targetId)->exists(),
            'vehicle' => Vehicle::query()->whereKey($targetId)->exists(),
            default => false,
        };
    }

    private function mappingOptionId(string $targetType, int $targetId): string
    {
        return strtolower(trim($targetType)).':'.$targetId;
    }

    private function normalizeUserTarget(?string $targetType, ?int $targetId, ?int $newUserId): ?array
    {
        if (! empty($targetType) && ! empty($targetId)) {
            return [
                'target_type' => (string) $targetType,
                'target_id' => (int) $targetId,
            ];
        }

        if (! empty($newUserId)) {
            return [
                'target_type' => 'user',
                'target_id' => (int) $newUserId,
            ];
        }

        return null;
    }

    private function normalizeVehicleTarget(?string $targetType, ?int $targetId, ?int $newVehicleId): ?array
    {
        if (! empty($targetType) && ! empty($targetId)) {
            return [
                'target_type' => (string) $targetType,
                'target_id' => (int) $targetId,
            ];
        }

        if (! empty($newVehicleId)) {
            return [
                'target_type' => 'vehicle',
                'target_id' => (int) $newVehicleId,
            ];
        }

        return null;
    }

    private function userLabel(User $user): string
    {
        $fullName = trim(
            collect([$user->first_name, $user->last_name])
                ->filter()
                ->implode(' ')
        );

        return $fullName !== '' ? $fullName : ($user->name ?: (string) $user->email);
    }

    private function transporterLabel(Transporter $transporter): string
    {
        $fullName = trim(
            collect([$transporter->first_name, $transporter->last_name])
                ->filter()
                ->implode(' ')
        );

        if ($fullName !== '') {
            return $fullName;
        }

        $company = trim((string) ($transporter->company_name ?? ''));

        if ($company !== '') {
            return $company;
        }

        return 'Transporteur #'.$transporter->id;
    }

    private function transporterSearchText(Transporter $transporter): string
    {
        return strtolower(
            trim(
                collect([
                    $transporter->first_name,
                    $transporter->last_name,
                    $transporter->company_name,
                ])->filter()->implode(' ')
            )
        );
    }

    private function vehicleLabel(Vehicle $vehicle): string
    {
        $name = trim((string) ($vehicle->name ?? ''));
        $registration = trim((string) ($vehicle->registration ?? ''));

        if ($registration !== '' && $name !== '') {
            return $registration.' / '.$name;
        }

        if ($registration !== '') {
            return $registration;
        }

        if ($name !== '') {
            return $name;
        }

        return 'Véhicule #'.$vehicle->id;
    }

    private function vehicleSource(string $code, string $label): ?array
    {
        $code = strtolower(trim($code));
        $label = strtolower(trim($label));

        if (
            in_array($code, ['tracteur', 'porteur', 'camion'], true)
            || str_contains($label, 'tracteur')
            || str_contains($label, 'porteur')
            || str_contains($label, 'camion')
        ) {
            return ['source' => 'camions', 'prefix' => 'Camion'];
        }

        if ($code === 'benne' || str_contains($label, 'benne') || str_contains($label, 'remorque')) {
            return ['source' => 'remorques', 'prefix' => 'Remorque'];
        }

        if ($code === 'ensemble_pl' || str_contains($label, 'ensemble pl')) {
            return ['source' => 'ensembles_pl', 'prefix' => 'Ensemble PL'];
        }

        if ($code === 'vl' || $label === 'vl') {
            return ['source' => 'vl', 'prefix' => 'VL'];
        }

        return null;
    }
}
