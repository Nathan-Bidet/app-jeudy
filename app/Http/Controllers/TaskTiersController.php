<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTiersImportJob;
use App\Models\TaskTiersImportConfig;
use App\Models\TaskTiersRecord;
use App\Models\TiersImportJob;
use App\Support\Access\AccessManager;
use App\Support\Tiers\TiersColumnFormat;
use App\Support\Tiers\TiersSearchText;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TaskTiersController extends Controller
{
    public function __construct(private readonly AccessManager $accessManager)
    {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Tasks/Tiers/Index', [
            'permissions' => [
                'can_import' => (bool) ($user && $this->accessManager->can($user, 'task.tiers.import')),
                'can_delete_data' => (bool) ($user && $this->accessManager->can($user, 'task.tiers.delete')),
                'can_update' => (bool) ($user && $this->accessManager->can($user, 'task.tiers.update')),
            ],
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $perPage = 50;
        $page = max(1, (int) $request->integer('page', 1));
        $search = trim((string) $request->query('search', ''));
        $columns = $this->tiersTableColumns();

        $query = TaskTiersRecord::query();

        if ($search !== '') {
            $this->applyTiersSearch($query, $search, $columns);
        }

        if ($search !== '') {
            $normalizedSearch = Str::lower($search);
            $query->orderByRaw(
                'CASE WHEN LOWER(primary_identifier) = ? OR LOWER(reference_value) = ? THEN 0 ELSE 1 END',
                [$normalizedSearch, $normalizedSearch],
            );
        }

        $paginator = $query
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())
            ->map(function (TaskTiersRecord $record) use ($columns): array {
                $data = (array) ($record->data ?? []);

                return [
                    'id' => $record->id,
                    'imported_at' => $record->imported_at?->toIso8601String(),
                    'values' => collect($columns)
                        ->mapWithKeys(fn (array $column): array => [
                            $column['key'] => TiersColumnFormat::formatForDisplay(
                                $data[$column['key']] ?? '',
                                $column['format'] ?? '',
                            ),
                        ])
                        ->all(),
                ];
            })
            ->values();

        return response()->json([
            'columns' => $columns,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function storeRecord(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:5000'],
        ]);

        $columns = $this->tiersTableColumns();
        $data = $this->filteredRecordData((array) $validated['values'], $columns);
        $record = TaskTiersRecord::query()->create([
            'import_config_id' => $this->writableTiersConfig($request)->id,
            'source_row_hash' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'data' => $data,
            'search_text' => TiersSearchText::build($data),
            'imported_by_user_id' => $request->user()?->id,
            'imported_at' => now(),
        ]);

        $this->logTiersAdminAction($request, 'record_created', [
            'record_id' => $record->id,
        ]);

        return response()->json([
            'message' => 'Ligne créée.',
            'record_id' => $record->id,
        ], 201);
    }

    public function updateRecord(Request $request, TaskTiersRecord $record): JsonResponse
    {
        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:5000'],
        ]);

        $columns = $this->tiersTableColumns();
        $data = $this->filteredRecordData((array) $validated['values'], $columns);

        $record->forceFill([
            'data' => $data,
            'source_row_hash' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'search_text' => TiersSearchText::build($data, $record->primary_identifier, $record->reference_value),
        ])->save();

        $this->logTiersAdminAction($request, 'record_updated', [
            'record_id' => $record->id,
        ]);

        return response()->json([
            'message' => 'Ligne mise à jour.',
        ]);
    }

    public function destroyRecord(Request $request, TaskTiersRecord $record): JsonResponse
    {
        $recordId = $record->id;
        $record->delete();

        $this->logTiersAdminAction($request, 'record_deleted', [
            'record_id' => $recordId,
        ]);

        return response()->json([
            'message' => 'Ligne supprimée.',
        ]);
    }

    public function storeColumn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
        ]);

        $label = trim((string) $validated['label']);
        $key = $this->uniqueTiersColumnKey(Str::slug($label, '_'));
        $this->upsertManualColumn($request, $key, $label, (string) ($validated['format'] ?? ''));

        $this->logTiersAdminAction($request, 'column_created', [
            'column_key' => $key,
            'label' => $label,
        ]);

        return response()->json([
            'message' => 'Colonne créée.',
            'column' => [
                'key' => $key,
                'label' => $label,
                'format' => (string) ($validated['format'] ?? ''),
            ],
        ], 201);
    }

    public function updateColumn(Request $request, string $columnKey): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
        ]);

        $label = trim((string) $validated['label']);
        $this->upsertManualColumn($request, $columnKey, $label, (string) ($validated['format'] ?? ''));

        $this->logTiersAdminAction($request, 'column_updated', [
            'column_key' => $columnKey,
            'label' => $label,
        ]);

        return response()->json([
            'message' => 'Colonne mise à jour.',
        ]);
    }

    public function destroyColumn(Request $request, string $columnKey): JsonResponse
    {
        TaskTiersRecord::query()
            ->whereNotNull('data')
            ->chunkById(500, function ($records) use ($columnKey): void {
                foreach ($records as $record) {
                    $data = (array) ($record->data ?? []);
                    if (! array_key_exists($columnKey, $data)) {
                        continue;
                    }

                    unset($data[$columnKey]);
                    $record->forceFill([
                        'data' => $data,
                        'source_row_hash' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                        'search_text' => TiersSearchText::build($data, $record->primary_identifier, $record->reference_value),
                    ])->save();
                }
            });

        $this->deleteTiersColumnConfig($request, $columnKey);
        $this->logTiersAdminAction($request, 'column_deleted', [
            'column_key' => $columnKey,
        ]);

        return response()->json([
            'message' => 'Colonne supprimée.',
        ]);
    }

    public function previewHeader(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:xlsx,xls,csv,ods',
            ],
        ]);

        $file = $validated['file'];
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw ValidationException::withMessages([
                'file' => "Les fichiers .xls anciens ne sont pas encore pris en charge. Merci d'utiliser un fichier .xlsx, .csv ou .ods.",
            ]);
        }

        $path = $file->getRealPath();
        if (! $path) {
            throw ValidationException::withMessages([
                'file' => "Le fichier n'a pas pu être lu.",
            ]);
        }

        $reader = $this->makeReader($extension);

        try {
            $result = $this->readHeaderPreview($reader, $path);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'file' => "L'en-tête du fichier n'a pas pu être lu.",
            ]);
        } finally {
            $reader->close();
        }

        return response()->json([
            'file' => [
                'name' => $file->getClientOriginalName(),
                'extension' => $extension,
                'size' => $file->getSize(),
            ],
            ...$result,
        ]);
    }

    public function storeImportConfig(Request $request): JsonResponse
    {
        $validated = $this->validateImportConfiguration($request);

        $config = $this->createImportConfig($request, $validated, false);

        return response()->json([
            'message' => "La préparation de l'import a été enregistrée.",
            'config_id' => $config->id,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->decodeJsonPayload($request);

        $validated = $this->validateImportConfiguration($request, [
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:xlsx,xls,csv,ods',
            ],
        ]);

        $file = $validated['file'];
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw ValidationException::withMessages([
                'file' => "Les fichiers .xls anciens ne sont pas encore pris en charge. Merci d'utiliser un fichier .xlsx, .csv ou .ods.",
            ]);
        }

        $disk = 'local';
        $path = $file->store('tiers-imports', $disk);

        if (! $path) {
            throw ValidationException::withMessages([
                'file' => "Le fichier n'a pas pu être sauvegardé pour l'import.",
            ]);
        }

        $config = $this->createImportConfig($request, $validated, true);
        $importJob = TiersImportJob::query()->create([
            'import_config_id' => $config->id,
            'created_by_user_id' => $request->user()?->id,
            'status' => 'pending',
            'original_filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'file_path' => $path,
            'progress' => 0,
            'message' => 'Import en attente',
            'options' => [
                'columns' => $validated['columns'],
                'identification_column' => $validated['identification_column'] ?? null,
                'reference_column' => $validated['reference_column'] ?? null,
                'extension' => $extension,
                'resume_from_row' => 2,
            ],
        ]);

        Log::info('task_tiers_import_job_created', [
            'job_id' => $importJob->id,
            'config_id' => $config->id,
            'file' => $file->getClientOriginalName(),
            'path' => $path,
        ]);

        ProcessTiersImportJob::dispatch($importJob->id)->onConnection('database');

        return response()->json([
            'message' => 'Import lancé en arrière-plan.',
            'import_job_id' => $importJob->id,
            'status' => $importJob->status,
            'progress' => $importJob->progress,
        ], 202);
    }

    public function importStatus(TiersImportJob $importJob): JsonResponse
    {
        return response()->json($this->serializeImportJob($importJob->fresh()));
    }

    public function importHistory(): JsonResponse
    {
        $imports = TiersImportJob::query()
            ->with('creator')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (TiersImportJob $importJob): array => $this->serializeImportHistoryItem($importJob))
            ->values();

        return response()->json([
            'imports' => $imports,
        ]);
    }

    public function importReport(TiersImportJob $importJob): JsonResponse
    {
        $importJob->loadMissing('creator');

        return response()->json([
            'import' => $this->serializeImportHistoryItem($importJob),
            'report' => $this->reportForImportJob($importJob),
        ]);
    }

    public function resolveImportError(Request $request, TiersImportJob $importJob): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['skip', 'import'])],
            'corrected_row' => ['nullable', 'array'],
            'corrected_row.*' => ['nullable', 'string', 'max:5000'],
        ]);

        $error = (array) ($importJob->error ?? []);
        $context = (array) ($error['context'] ?? []);
        $row = isset($context['row']) ? (int) $context['row'] : null;

        if ($importJob->status !== 'waiting_user' || $row === null) {
            return response()->json([
                'message' => "Aucune ligne d'import en attente de correction.",
                'import_job_id' => $importJob->id,
                'status' => $importJob->status,
            ], 422);
        }

        $options = (array) ($importJob->options ?? []);
        unset(
            $options['skip_row'],
            $options['corrected_row_number'],
            $options['corrected_row'],
            $options['manual_validated_row_number'],
        );

        if ($validated['action'] === 'skip') {
            $options['resume_from_row'] = $row + 1;
            $options['skip_row'] = $row;
        } else {
            $correctedRow = [];
            foreach ((array) ($validated['corrected_row'] ?? []) as $index => $value) {
                $correctedRow[(int) $index] = trim((string) $value);
            }

            $options['resume_from_row'] = $row;
            $options['corrected_row_number'] = $row;
            $options['corrected_row'] = $correctedRow;
            $options['manual_validated_row_number'] = $row;
        }

        $importJob->forceFill([
            'status' => 'pending',
            'message' => 'Reprise de l’import...',
            'options' => $options,
            'error' => null,
            'failed_at' => null,
        ])->save();

        ProcessTiersImportJob::dispatch($importJob->id)->onConnection('database');

        return response()->json([
            'message' => 'Import relancé en arrière-plan.',
            'import_job_id' => $importJob->id,
            'status' => $importJob->status,
            'progress' => $importJob->progress,
        ], 202);
    }

    public function destroyData(Request $request): JsonResponse
    {
        $deletedRows = 0;
        $deletedColumns = count($this->tiersTableColumns());
        $deletedConfigs = 0;

        DB::transaction(function () use (&$deletedRows, &$deletedConfigs): void {
            $deletedRows = TaskTiersRecord::query()->count();
            $deletedConfigs = TaskTiersImportConfig::query()->count();

            TaskTiersRecord::query()->delete();
            TaskTiersImportConfig::query()->delete();
        });

        $this->logTiersAdminAction($request, 'data_deleted', [
            'deleted_rows' => $deletedRows,
            'deleted_columns' => $deletedColumns,
            'deleted_configs' => $deletedConfigs,
        ]);

        return response()->json([
            'message' => 'Toutes les lignes et colonnes Tiers ont été supprimées.',
            'deleted_rows' => $deletedRows,
            'deleted_columns' => $deletedColumns,
            'deleted_configs' => $deletedConfigs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeImportJob(?TiersImportJob $importJob): array
    {
        if (! $importJob) {
            return [
                'status' => 'failed',
                'progress' => 0,
                'message' => "Le suivi de l'import est introuvable.",
            ];
        }

        $error = (array) ($importJob->error ?? []);
        $stats = (array) ($importJob->stats ?? []);
        $diagnostics = $error['diagnostics'] ?? $stats['diagnostics'] ?? null;

        return [
            'import_job_id' => $importJob->id,
            'status' => $importJob->status,
            'progress' => (int) $importJob->progress,
            'current_line' => $importJob->current_line,
            'total_lines' => $importJob->total_lines,
            'message' => $importJob->message,
            'error' => $error !== [] ? $error : null,
            'context' => $error['context'] ?? null,
            'diagnostics' => $diagnostics,
            'stats' => $importJob->stats,
            'report' => $this->reportForImportJob($importJob),
            'created_at' => $importJob->created_at?->toIso8601String(),
            'started_at' => $importJob->started_at?->toIso8601String(),
            'completed_at' => $importJob->completed_at?->toIso8601String(),
            'failed_at' => $importJob->failed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeImportHistoryItem(TiersImportJob $importJob): array
    {
        $report = $this->reportForImportJob($importJob);
        $summary = (array) ($report['summary'] ?? []);
        $technical = (array) ($report['technical'] ?? []);

        return [
            'id' => $importJob->id,
            'date' => $importJob->created_at?->toIso8601String(),
            'user' => $importJob->creator?->name,
            'file' => $importJob->original_filename,
            'rows' => $summary['analyzed_rows'] ?? $importJob->total_lines,
            'duration' => $technical['duration_human'] ?? null,
            'status' => $importJob->status,
            'status_label' => $report['status_label'] ?? $this->statusLabel($importJob),
            'progress' => (int) $importJob->progress,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportForImportJob(TiersImportJob $importJob): array
    {
        $stats = (array) ($importJob->stats ?? []);
        if (isset($stats['report']) && is_array($stats['report'])) {
            return $stats['report'];
        }

        $error = (array) ($importJob->error ?? []);
        $diagnostics = $error['diagnostics'] ?? $stats['diagnostics'] ?? [];

        return [
            'generated_at' => $importJob->updated_at?->toIso8601String(),
            'imported_at' => $importJob->completed_at?->toIso8601String(),
            'user' => [
                'id' => $importJob->created_by_user_id,
                'name' => $importJob->creator?->name,
            ],
            'file' => [
                'name' => $importJob->original_filename,
            ],
            'status' => $importJob->status,
            'status_label' => $this->statusLabel($importJob),
            'summary' => [
                'total_rows' => (int) ($importJob->total_lines ?? 0),
                'analyzed_rows' => (int) ($stats['read_rows'] ?? $importJob->current_line ?? 0),
                'imported_rows' => (int) ($stats['imported_rows'] ?? 0),
                'updated_rows' => (int) ($stats['updated_rows'] ?? 0),
                'ignored_rows' => (int) ($stats['ignored_rows'] ?? 0),
                'duplicates_count' => (int) ($stats['skipped_duplicates'] ?? 0),
                'automatic_corrections' => (int) ($stats['automatic_corrections'] ?? 0),
                'errors_count' => isset($error['detail']) ? 1 : (int) ($stats['errors_count'] ?? 0),
                'empty_rows' => (int) ($stats['empty_rows'] ?? 0),
            ],
            'warnings' => (array) ($stats['warnings'] ?? []),
            'warnings_by_category' => [
                'automatic_correction' => 0,
                'ignored' => 0,
                'intervention' => isset($error['detail']) ? 1 : 0,
            ],
            'technical' => [
                'duration_seconds' => $diagnostics['elapsed_seconds'] ?? null,
                'duration_human' => isset($diagnostics['elapsed_seconds']) ? $this->humanDuration((int) round((float) $diagnostics['elapsed_seconds'])) : null,
                'average_rows_per_second' => null,
                'last_processed_line' => $diagnostics['current_line'] ?? $importJob->current_line,
                'memory_usage_mb' => $diagnostics['memory_usage_mb'] ?? null,
                'memory_peak_mb' => $diagnostics['memory_peak_mb'] ?? null,
                'memory_limit' => $diagnostics['memory_limit'] ?? null,
                'batches_processed' => (int) ($stats['batches_processed'] ?? 0),
            ],
            'error' => $error !== [] ? $error : null,
        ];
    }

    private function statusLabel(TiersImportJob $importJob): string
    {
        $stats = (array) ($importJob->stats ?? []);

        return match ($importJob->status) {
            'completed' => (($stats['status'] ?? null) === 'warning')
                ? 'Succès avec avertissements'
                : 'Succès',
            'failed' => 'Échec',
            'waiting_user' => 'Intervention requise',
            'running' => 'En cours',
            'pending' => 'En attente',
            default => ucfirst((string) $importJob->status),
        };
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' s';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0 ? $minutes.' min '.$remainingSeconds.' s' : $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? $hours.' h '.$remainingMinutes.' min' : $hours.' h';
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function tiersTableColumns(): array
    {
        $columnsByKey = [];
        $dataKeys = TaskTiersRecord::query()
            ->latest('id')
            ->limit(200)
            ->get(['data'])
            ->flatMap(fn (TaskTiersRecord $record): array => array_keys((array) ($record->data ?? [])))
            ->unique()
            ->values();

        $configs = TaskTiersImportConfig::query()
            ->latest('id')
            ->limit(50)
            ->get(['columns', 'options']);

        $deletedColumns = $configs
            ->flatMap(fn (TaskTiersImportConfig $config): array => (array) (($config->options ?? [])['deleted_columns'] ?? []))
            ->map(fn ($key): string => (string) $key)
            ->unique()
            ->values()
            ->all();

        $configs->each(function (TaskTiersImportConfig $config) use (&$columnsByKey, $deletedColumns): void {
            foreach ((array) ($config->columns ?? []) as $column) {
                if (! (bool) ($column['import'] ?? false)) {
                    continue;
                }

                $key = $this->tiersColumnKey($column);
                if ($key === '' || isset($columnsByKey[$key]) || in_array($key, $deletedColumns, true)) {
                    continue;
                }

                $columnsByKey[$key] = [
                    'key' => $key,
                    'label' => $this->tiersColumnLabel($column),
                    'format' => (string) ($column['format_model'] ?? ''),
                ];
            }

            foreach ((array) (($config->options ?? [])['manual_columns'] ?? []) as $manualColumn) {
                $key = trim((string) ($manualColumn['key'] ?? ''));
                if ($key === '' || in_array($key, $deletedColumns, true)) {
                    continue;
                }

                $columnsByKey[$key] = [
                    'key' => $key,
                    'label' => trim((string) ($manualColumn['label'] ?? $key)) ?: $key,
                    'format' => (string) ($manualColumn['format'] ?? ''),
                ];
            }
        });

        foreach ($dataKeys as $key) {
            $key = (string) $key;
            if (isset($columnsByKey[$key]) || in_array($key, $deletedColumns, true)) {
                continue;
            }

            $columnsByKey[$key] = [
                'key' => $key,
                'label' => Str::headline(str_replace('_', ' ', $key)),
                'format' => '',
            ];
        }

        return array_values($columnsByKey);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, array{key: string, label: string}>  $columns
     * @return array<string, string>
     */
    private function filteredRecordData(array $values, array $columns): array
    {
        $allowedKeys = collect($columns)->pluck('key')->all();
        $data = [];

        foreach ($columns as $column) {
            $key = (string) ($column['key'] ?? '');
            if ($key === '' || ! in_array($key, $allowedKeys, true)) {
                continue;
            }

            $format = (string) ($column['format'] ?? '');
            $value = TiersColumnFormat::normalize($values[$key] ?? '', $format);
            if (! TiersColumnFormat::isValid($value, $format)) {
                throw ValidationException::withMessages([
                    'values.'.$key => TiersColumnFormat::validationMessage($format),
                ]);
            }

            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $columns
     */
    private function applyTiersSearch(Builder $query, string $search, array $columns): void
    {
        $needle = '%'.$this->escapeLike(TiersSearchText::normalize($search)).'%';

        $query->where(function (Builder $subQuery) use ($needle): void {
            $subQuery
                ->whereRaw("LOWER(COALESCE(primary_identifier, '')) LIKE ?", [$needle])
                ->orWhereRaw("LOWER(COALESCE(reference_value, '')) LIKE ?", [$needle])
                ->orWhereRaw("COALESCE(search_text, '') LIKE ?", [$needle]);
        });
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function writableTiersConfig(Request $request): TaskTiersImportConfig
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

    private function uniqueTiersColumnKey(string $baseKey): string
    {
        $baseKey = trim($baseKey) !== '' ? trim($baseKey) : 'colonne';
        $existingKeys = collect($this->tiersTableColumns())->pluck('key')->all();
        $key = $baseKey;
        $index = 2;

        while (in_array($key, $existingKeys, true)) {
            $key = $baseKey.'_'.$index;
            $index++;
        }

        return $key;
    }

    private function upsertManualColumn(Request $request, string $key, string $label, string $format): void
    {
        $config = $this->writableTiersConfig($request);
        $options = (array) ($config->options ?? []);
        $manualColumns = collect((array) ($options['manual_columns'] ?? []))
            ->reject(fn (array $column): bool => (string) ($column['key'] ?? '') === $key)
            ->values()
            ->all();

        $manualColumns[] = [
            'key' => $key,
            'label' => $label,
            'format' => $format,
        ];

        $options['manual_columns'] = $manualColumns;
        $options['deleted_columns'] = collect((array) ($options['deleted_columns'] ?? []))
            ->reject(fn ($deletedKey): bool => (string) $deletedKey === $key)
            ->values()
            ->all();
        $config->forceFill(['options' => $options])->save();
        $this->restoreTiersColumnKey($key);
    }

    private function deleteTiersColumnConfig(Request $request, string $key): void
    {
        $config = $this->writableTiersConfig($request);
        $options = (array) ($config->options ?? []);
        $options['manual_columns'] = collect((array) ($options['manual_columns'] ?? []))
            ->reject(fn (array $column): bool => (string) ($column['key'] ?? '') === $key)
            ->values()
            ->all();
        $options['deleted_columns'] = collect((array) ($options['deleted_columns'] ?? []))
            ->push($key)
            ->unique()
            ->values()
            ->all();

        $config->forceFill(['options' => $options])->save();
    }

    private function restoreTiersColumnKey(string $key): void
    {
        TaskTiersImportConfig::query()
            ->whereNotNull('options')
            ->get()
            ->each(function (TaskTiersImportConfig $config) use ($key): void {
                $options = (array) ($config->options ?? []);
                $deletedColumns = collect((array) ($options['deleted_columns'] ?? []))
                    ->reject(fn ($deletedKey): bool => (string) $deletedKey === $key)
                    ->values()
                    ->all();

                if ($deletedColumns === (array) ($options['deleted_columns'] ?? [])) {
                    return;
                }

                $options['deleted_columns'] = $deletedColumns;
                $config->forceFill(['options' => $options])->save();
            });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logTiersAdminAction(Request $request, string $action, array $context = []): void
    {
        Log::info('task_tiers_admin_action', [
            'action' => $action,
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name,
            ...$context,
        ]);
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

    /**
     * @param  array<string, mixed>  $extraRules
     * @return array<string, mixed>
     */
    private function validateImportConfiguration(Request $request, array $extraRules = []): array
    {
        return $request->validate([
            ...$extraRules,
            'original_filename' => ['nullable', 'string', 'max:255'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.index' => ['required', 'integer', 'min:0'],
            'columns.*.source_name' => ['nullable', 'string', 'max:255'],
            'columns.*.import' => ['required', 'boolean'],
            'columns.*.application_name' => ['nullable', 'string', 'max:255'],
            'columns.*.existing_column' => ['nullable', 'string', 'max:255'],
            'columns.*.clean_values' => ['required', 'boolean'],
            'columns.*.cleaning_rules' => ['nullable', 'array'],
            'columns.*.format_model' => ['nullable', 'string', 'max:255'],
            'identification_column' => ['nullable', 'string', 'max:255'],
            'reference_column' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createImportConfig(Request $request, array $validated, bool $finalImportEnabled): TaskTiersImportConfig
    {
        return TaskTiersImportConfig::query()->create([
            'name' => 'Import '.$request->user()?->name.' '.now()->format('d/m/Y H:i'),
            'original_filename' => $validated['original_filename'] ?? null,
            'columns' => $validated['columns'],
            'identification_column' => $validated['identification_column'] ?? null,
            'reference_column' => $validated['reference_column'] ?? null,
            'options' => [
                'status' => $finalImportEnabled ? 'imported' : 'draft',
                'final_import_enabled' => $finalImportEnabled,
            ],
            'created_by_user_id' => $request->user()?->id,
        ]);
    }

    private function decodeJsonPayload(Request $request): void
    {
        foreach (['columns', 'corrected_row'] as $key) {
            $value = $request->input($key);

            if (! is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge([$key => $decoded]);
            }
        }
    }

    private function makeReader(string $extension): ReaderInterface
    {
        return match ($extension) {
            'xlsx' => new XlsxReader(),
            'csv' => new CsvReader(),
            'ods' => new OdsReader(),
            default => throw ValidationException::withMessages([
                'file' => 'Format de fichier non pris en charge.',
            ]),
        };
    }

    /**
     * @return array{columns: array<int, array<string, mixed>>, preview: array<int, array<int, string>>, rows_scanned: int}
     */
    private function readHeaderPreview(ReaderInterface $reader, string $path): array
    {
        $reader->open($path);

        $headers = [];
        $preview = [];
        $rowsScanned = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($value): string => $this->stringifyCellValue($value), $row->toArray());

                if ($rowsScanned === 0) {
                    $headers = $values;
                } elseif (count($preview) < 5) {
                    $preview[] = $values;
                }

                $rowsScanned++;

                if ($rowsScanned >= 6) {
                    break 2;
                }
            }

            break;
        }

        if ($headers === []) {
            throw ValidationException::withMessages([
                'file' => 'Le fichier ne contient aucune ligne lisible.',
            ]);
        }

        $width = max([
            count($headers),
            ...array_map(static fn (array $row): int => count($row), $preview),
        ]);

        $columns = [];
        for ($index = 0; $index < $width; $index++) {
            $sourceName = trim((string) ($headers[$index] ?? ''));
            $fallbackName = 'Colonne '.($index + 1);

            $columns[] = [
                'index' => $index,
                'source_name' => $sourceName !== '' ? $sourceName : $fallbackName,
                'suggested_name' => $sourceName !== '' ? $sourceName : $fallbackName,
                'key' => Str::slug($sourceName !== '' ? $sourceName : $fallbackName, '_'),
            ];
        }

        $normalizedPreview = array_map(function (array $row) use ($width): array {
            $next = [];

            for ($index = 0; $index < $width; $index++) {
                $next[] = $row[$index] ?? '';
            }

            return $next;
        }, $preview);

        return [
            'columns' => $columns,
            'preview' => $normalizedPreview,
            'rows_scanned' => $rowsScanned,
        ];
    }

    private function stringifyCellValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        if ($value instanceof DateInterval) {
            return $value->format('%d j %h h %i min');
        }

        if (is_array($value)) {
            return trim(implode(' ', array_map(fn ($item): string => $this->stringifyCellValue($item), $value)));
        }

        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        return trim((string) $value);
    }
}
