<?php

namespace App\Jobs;

use App\Http\Controllers\TaskTiersImportException;
use App\Models\TaskTiersImportConfig;
use App\Models\TiersImportJob;
use App\Support\Tiers\TiersColumnFormat;
use App\Support\Tiers\TiersSearchText;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

class ProcessTiersImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    private float $startedAt = 0.0;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $lastQuery = null;

    private ?int $currentLine = null;

    private ?int $totalLines = null;

    private ?int $lastProgress = null;

    private ?string $lastStep = null;

    public function __construct(public readonly int $tiersImportJobId)
    {
    }

    public function handle(): void
    {
        $job = TiersImportJob::query()->with(['config', 'creator'])->findOrFail($this->tiersImportJobId);
        $this->startedAt = microtime(true);
        DB::connection()->disableQueryLog();
        $this->registerDiagnostics($job);

        $this->updateJob($job, [
            'status' => 'running',
            'progress' => max(1, (int) $job->progress),
            'message' => "Préparation de l'import...",
            'started_at' => $job->started_at ?: now(),
            'failed_at' => null,
            'error' => null,
        ]);

        try {
            $config = $job->config;
            if (! $config) {
                throw new TaskTiersImportException(
                    step: 'préparation',
                    message: "La configuration d'import est introuvable.",
                );
            }

            $options = (array) ($job->options ?? []);
            $extension = strtolower((string) ($options['extension'] ?? pathinfo($job->file_path, PATHINFO_EXTENSION)));
            $path = Storage::disk($job->disk ?: 'local')->path($job->file_path);

            $this->logStep('Étape 1 : fichier reçu', $job, [
                'file' => $job->original_filename,
                'extension' => $extension,
                'queue' => $this->queue,
            ]);

            if (! is_file($path)) {
                throw new TaskTiersImportException(
                    step: 'lecture du fichier',
                    message: "Le fichier importé n'existe plus sur le serveur.",
                );
            }

            $this->updateJob($job, [
                'message' => 'Lecture du fichier...',
                'progress' => 5,
            ]);

            $countStartedAt = microtime(true);
            $totalRows = $this->countDataRows($extension, $path);
            $countDuration = microtime(true) - $countStartedAt;
            $this->totalLines = $totalRows;
            $this->updateJob($job, [
                'total_lines' => $totalRows,
                'message' => 'Analyse des colonnes...',
                'progress' => 10,
                'stats' => [
                    'diagnostics' => $this->diagnosticsContext($job),
                ],
            ]);

            $stats = $this->importRows($job, $config, $extension, $path, $options);
            $stats['file_read_seconds'] = round((float) ($stats['file_read_seconds'] ?? 0) + $countDuration, 3);
            $report = $this->buildReport($job, $stats, 'completed');
            $hasWarnings = ($report['summary']['ignored_rows'] ?? 0) > 0
                || ($report['summary']['automatic_corrections'] ?? 0) > 0
                || ($report['summary']['errors_count'] ?? 0) > 0;
            $statsForStorage = $stats;
            unset($statsForStorage['warnings']);

            $this->updateJob($job, [
                'status' => 'completed',
                'progress' => 100,
                'message' => ! $hasWarnings
                    ? 'Import terminé avec succès.'
                    : $this->formatCompletionMessage($report),
                'stats' => [
                    ...$statsForStorage,
                    'status' => ! $hasWarnings ? 'success' : 'warning',
                    'report' => $report,
                ],
                'completed_at' => now(),
                'failed_at' => null,
                'error' => null,
            ]);

            $this->logStep('Import Tiers terminé', $job, [
                ...$stats,
                'elapsed_seconds' => round(microtime(true) - $this->startedAt, 3),
            ]);
        } catch (TaskTiersImportException $exception) {
            $this->handleImportException($job, $exception);
        } catch (Throwable $exception) {
            $diagnostics = $this->diagnosticsContext($job, $exception);

            Log::error('Import Tiers unexpected queue failure', [
                'job_id' => $job->id,
                'message' => $exception->getMessage(),
                ...$diagnostics,
                'exception' => $exception,
            ]);

            $this->updateJob($job, [
                'status' => 'failed',
                'progress' => 0,
                'message' => $this->simplifyImportError($exception),
                'error' => [
                    'step' => 'import',
                    'row' => null,
                    'column' => null,
                    'detail' => $exception->getMessage(),
                    'diagnostics' => $diagnostics,
                ],
                'failed_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $job = TiersImportJob::query()->find($this->tiersImportJobId);

        if (! $job) {
            Log::error('Import Tiers job failed without tracking row', [
                'job_id' => $this->tiersImportJobId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return;
        }

        $diagnostics = $this->diagnosticsContext($job, $exception);

        Log::error('Import Tiers worker marked job as failed', [
            'job_id' => $job->id,
            'message' => $exception->getMessage(),
            ...$diagnostics,
            'exception' => $exception,
        ]);

        if ($job->status === 'failed') {
            return;
        }

        $this->updateJob($job, [
            'status' => 'failed',
            'progress' => (int) ($job->progress ?? $this->lastProgress ?? 0),
            'message' => $this->simplifyImportError($exception),
            'error' => [
                'step' => $this->lastStep ?: 'worker',
                'row' => $job->current_line ?: $this->currentLine,
                'column' => null,
                'detail' => $exception->getMessage(),
                'diagnostics' => $diagnostics,
            ],
            'failed_at' => now(),
        ]);
    }

    private function handleImportException(TiersImportJob $job, TaskTiersImportException $exception): void
    {
        $diagnostics = $this->diagnosticsContext($job, $exception);

        Log::error('Import Tiers queue failed', [
            'job_id' => $job->id,
            'step' => $exception->step,
            'row' => $exception->row,
            'column' => $exception->column,
            'message' => $exception->getMessage(),
            ...$diagnostics,
            'exception' => $exception,
        ]);

        $hasRowContext = $exception->row !== null;
        $this->updateJob($job, [
            'status' => $hasRowContext ? 'waiting_user' : 'failed',
            'progress' => $hasRowContext ? (int) $job->progress : 0,
            'message' => $exception->userMessage(),
            'error' => [
                'step' => $exception->step,
                'row' => $exception->row,
                'column' => $exception->column,
                'detail' => $exception->getMessage(),
                'diagnostics' => $diagnostics,
                'context' => $hasRowContext ? [
                    'config_id' => $job->import_config_id,
                    'row' => $exception->row,
                    'resume_from_row' => $exception->row + 1,
                    'columns' => $exception->columns,
                    'values' => $exception->rowValues,
                ] : null,
            ],
            'failed_at' => $hasRowContext ? null : now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function importRows(
        TiersImportJob $job,
        TaskTiersImportConfig $config,
        string $extension,
        string $path,
        array $options,
    ): array {
        $reader = $this->makeReader($extension);

        $this->logStep('Étape 2 : ouverture Excel', $job);

        try {
            $reader->open($path);
        } catch (Throwable $exception) {
            throw new TaskTiersImportException(
                step: 'lecture du fichier',
                message: 'Format Excel non lisible : '.$exception->getMessage(),
                previous: $exception,
            );
        }

        try {
            return $this->processRows($reader, $job, $config, $options);
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function processRows(
        ReaderInterface $reader,
        TiersImportJob $job,
        TaskTiersImportConfig $config,
        array $options,
    ): array {
        $validated = [
            'columns' => (array) ($options['columns'] ?? $config->columns ?? []),
            'identification_column' => $options['identification_column'] ?? $config->identification_column,
            'reference_column' => $options['reference_column'] ?? $config->reference_column,
            'resume_from_row' => $options['resume_from_row'] ?? 2,
            'skip_row' => $options['skip_row'] ?? null,
            'corrected_row_number' => $options['corrected_row_number'] ?? null,
            'corrected_row' => $options['corrected_row'] ?? [],
            'manual_validated_row_number' => $options['manual_validated_row_number'] ?? null,
        ];

        $this->logStep('Étape 3 : lecture des colonnes', $job, [
            'columns_count' => count($validated['columns']),
        ]);

        $columns = collect($validated['columns'])
            ->filter(fn (array $column): bool => (bool) ($column['import'] ?? false))
            ->keyBy(fn (array $column): int => (int) $column['index']);

        if ($columns->isEmpty()) {
            throw new TaskTiersImportException(
                step: 'analyse des colonnes',
                message: 'Sélectionnez au moins une colonne à importer.',
            );
        }

        $this->logStep('Étape 4 : création du mapping', $job, [
            'imported_columns_count' => $columns->count(),
            'identification_column' => $validated['identification_column'] ?? null,
            'reference_column' => $validated['reference_column'] ?? null,
        ]);

        $this->logStep('Étape 5 : création de la table / migration si nécessaire', $job, [
            'dynamic_table_creation' => false,
            'storage' => 'task_tiers_records.data json',
        ]);

        $identificationIndex = $validated['identification_column'] ?? null;
        $referenceIndex = $validated['reference_column'] ?? null;
        $resumeFromRow = (int) ($validated['resume_from_row'] ?? 2);
        $skipRow = isset($validated['skip_row']) ? (int) $validated['skip_row'] : null;
        $correctedRowNumber = isset($validated['corrected_row_number']) ? (int) $validated['corrected_row_number'] : null;
        $manualValidatedRowNumber = isset($validated['manual_validated_row_number'])
            ? (int) $validated['manual_validated_row_number']
            : null;
        $correctedRow = array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($validated['corrected_row'] ?? []),
        );
        $readRows = 0;
        $importedRows = 0;
        $updatedRows = 0;
        $skippedDuplicates = 0;
        $emptyRows = 0;
        $ignoredRows = 0;
        $automaticCorrections = 0;
        $interventionRows = 0;
        $batchesProcessed = 0;
        $warnings = [];
        $headerSkipped = false;
        $totalRows = max(1, (int) ($job->total_lines ?? 0));
        $batchSize = 1000;
        $pendingRows = [];
        $existingHashes = $this->preloadExistingHashes();
        $fileReadSeconds = 0.0;
        $cleaningSeconds = 0.0;
        $insertSeconds = 0.0;
        $lastExcelRow = null;
        $importedAt = now();

        $this->updateJob($job, [
            'message' => 'Import des lignes...',
            'progress' => 12,
        ]);

        $this->logStep('Étape 6 : préparation des lignes', $job, [
            'resume_from_row' => $resumeFromRow,
            'skip_row' => $skipRow,
            'corrected_row_number' => $correctedRowNumber,
            'manual_validated_row_number' => $manualValidatedRowNumber,
        ]);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $sheetRowIndex => $row) {
                if (! $headerSkipped) {
                    $headerSkipped = true;
                    continue;
                }

                $excelRow = (int) $sheetRowIndex;
                if ($excelRow < $resumeFromRow) {
                    continue;
                }

                if ($skipRow !== null && $excelRow === $skipRow) {
                    $ignoredRows++;
                    $warnings[] = $this->makeWarning(
                        row: $excelRow,
                        category: 'ignored',
                        type: 'ligne ignorée',
                        action: 'Ignorée par l’utilisateur',
                        message: 'La ligne a été ignorée lors de la reprise manuelle.',
                    );
                    continue;
                }

                $readRows++;
                $readStartedAt = microtime(true);
                $values = $correctedRowNumber !== null && $excelRow === $correctedRowNumber
                    ? $correctedRow
                    : array_map(fn ($value): string => $this->stringifyCellValue($value), $row->toArray());
                $fileReadSeconds += microtime(true) - $readStartedAt;
                $skipFormatValidation = $manualValidatedRowNumber !== null && $excelRow === $manualValidatedRowNumber;
                $data = [];
                $warningsBeforeRow = count($warnings);
                $cleaningStartedAt = microtime(true);

                try {
                    foreach ($columns as $index => $column) {
                        $rawValue = $values[(int) $index] ?? '';
                        $value = $this->cleanImportedValue(
                            value: $rawValue,
                            column: $column,
                            skipFormatValidation: $skipFormatValidation,
                            excelRow: $excelRow,
                            warnings: $warnings,
                        );
                        $targetKey = trim((string) ($column['existing_column'] ?? ''));

                        if ($targetKey === '') {
                            $targetKey = Str::slug((string) ($column['application_name'] ?? $column['source_name'] ?? 'colonne_'.$index), '_');
                        }

                        if ($targetKey === '') {
                            throw new TaskTiersImportException(
                                step: 'analyse des colonnes',
                                message: 'Nom de colonne vide après normalisation.',
                                row: $excelRow,
                                column: (string) ($column['source_name'] ?? 'Colonne '.((int) $index + 1)),
                                rowValues: $this->rowValuesForError($values, $validated['columns']),
                                columns: $this->columnsForError($validated['columns']),
                            );
                        }

                        $data[$targetKey] = $value;
                    }
                } catch (TaskTiersImportException $exception) {
                    $cleaningSeconds += microtime(true) - $cleaningStartedAt;
                    $interventionRows++;
                    $warnings[] = $this->makeWarning(
                        row: $exception->row ?? $excelRow,
                        column: $exception->column,
                        originalValue: $this->valueForWarningColumn($values, $validated['columns'], $exception->column),
                        category: 'intervention',
                        type: 'intervention utilisateur',
                        action: 'Ligne mise de côté',
                        message: $exception->getMessage(),
                        context: [
                            'columns' => $this->columnsForError($validated['columns']),
                            'values' => $this->rowValuesForError($values, $validated['columns']),
                        ],
                    );
                    $this->tickProgress($job, $excelRow, $readRows, $totalRows, false);
                    continue;
                } catch (Throwable $exception) {
                    $cleaningSeconds += microtime(true) - $cleaningStartedAt;
                    $interventionRows++;
                    $warnings[] = $this->makeWarning(
                        row: $excelRow,
                        category: 'intervention',
                        type: 'intervention utilisateur',
                        action: 'Ligne mise de côté',
                        message: $exception->getMessage(),
                        context: [
                            'columns' => $this->columnsForError($validated['columns']),
                            'values' => $this->rowValuesForError($values, $validated['columns']),
                        ],
                    );
                    $this->tickProgress($job, $excelRow, $readRows, $totalRows, false);
                    continue;
                }
                $cleaningSeconds += microtime(true) - $cleaningStartedAt;

                if ($this->rowIsEmpty($data)) {
                    $emptyRows++;
                    $ignoredRows++;
                    $warnings[] = $this->makeWarning(
                        row: $excelRow,
                        category: 'ignored',
                        type: 'ligne vide',
                        action: 'Ligne ignorée',
                        message: 'La ligne est vide après nettoyage.',
                    );
                    $this->tickProgress($job, $excelRow, $readRows, $totalRows, false);
                    continue;
                }

                if ($skipFormatValidation) {
                    $this->logStep('Ligne validée manuellement', $job, [
                        'excel_row' => $excelRow,
                    ]);
                }

                $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (isset($existingHashes[$hash])) {
                    $skippedDuplicates++;
                    $ignoredRows++;
                    $warnings[] = $this->makeWarning(
                        row: $excelRow,
                        category: 'ignored',
                        type: 'doublon parfait',
                        action: 'Ligne ignorée',
                        message: 'Une ligne strictement identique existe déjà.',
                    );
                    $this->tickProgress($job, $excelRow, $readRows, $totalRows, false);
                    continue;
                }

                $existingHashes[$hash] = true;
                $pendingRows[] = [
                    'import_config_id' => $config->id,
                    'source_row_hash' => $hash,
                    'primary_identifier' => $this->valueForColumnIndex($values, $identificationIndex),
                    'reference_value' => $this->valueForColumnIndex($values, $referenceIndex),
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'search_text' => TiersSearchText::build(
                        $data,
                        $this->valueForColumnIndex($values, $identificationIndex),
                        $this->valueForColumnIndex($values, $referenceIndex),
                    ),
                    'imported_by_user_id' => $job->created_by_user_id ?: null,
                    'imported_at' => $importedAt,
                    'created_at' => $importedAt,
                    'updated_at' => $importedAt,
                ];

                if (count($pendingRows) >= $batchSize) {
                    [$insertedCount, $batchSeconds] = $this->insertTiersBatch($pendingRows, $job, $excelRow, $values, $validated['columns']);
                    $insertSeconds += $batchSeconds;
                    $importedRows += $insertedCount;
                    $batchesProcessed++;
                    $pendingRows = [];
                    $this->tickProgress($job, $excelRow, $readRows, $totalRows, true);
                }

                $automaticCorrections += max(0, count($warnings) - $warningsBeforeRow);
                $lastExcelRow = $excelRow;
            }

            break;
        }

        if ($pendingRows !== []) {
            [$insertedCount, $batchSeconds] = $this->insertTiersBatch($pendingRows, $job, $lastExcelRow ?? (int) ($job->current_line ?? 0), [], $validated['columns']);
            $insertSeconds += $batchSeconds;
            $importedRows += $insertedCount;
            $batchesProcessed++;
            $this->tickProgress($job, $lastExcelRow ?? (int) ($job->current_line ?? 0), $readRows, $totalRows, true);
        }

        return [
            'read_rows' => $readRows,
            'imported_rows' => $importedRows,
            'updated_rows' => $updatedRows,
            'skipped_duplicates' => $skippedDuplicates,
            'empty_rows' => $emptyRows,
            'ignored_rows' => $ignoredRows,
            'automatic_corrections' => $automaticCorrections,
            'intervention_rows' => $interventionRows,
            'errors_count' => $interventionRows,
            'batches_processed' => $batchesProcessed,
            'batch_size' => $batchSize,
            'file_read_seconds' => round($fileReadSeconds, 3),
            'cleaning_seconds' => round($cleaningSeconds, 3),
            'insert_seconds' => round($insertSeconds, 3),
            'warnings' => $warnings,
        ];
    }

    private function countDataRows(string $extension, string $path): int
    {
        $reader = $this->makeReader($extension);
        $total = 0;
        $headerSkipped = false;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    if (! $headerSkipped) {
                        $headerSkipped = true;
                        continue;
                    }

                    $total++;
                }

                break;
            }
        } catch (Throwable $exception) {
            throw new TaskTiersImportException(
                step: 'lecture du fichier',
                message: 'Format Excel non lisible : '.$exception->getMessage(),
                previous: $exception,
            );
        } finally {
            $reader->close();
        }

        return $total;
    }

    /**
     * @return array<string, true>
     */
    private function preloadExistingHashes(): array
    {
        $hashes = [];

        DB::table('task_tiers_records')
            ->whereNotNull('source_row_hash')
            ->orderBy('id')
            ->select(['id', 'source_row_hash'])
            ->chunkById(5000, function ($records) use (&$hashes): void {
                foreach ($records as $record) {
                    $hash = (string) ($record->source_row_hash ?? '');
                    if ($hash !== '') {
                        $hashes[$hash] = true;
                    }
                }
            });

        return $hashes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $values
     * @param  array<int, array<string, mixed>>  $columns
     * @return array{0: int, 1: float}
     */
    private function insertTiersBatch(array $rows, TiersImportJob $job, int $excelRow, array $values, array $columns): array
    {
        if ($rows === []) {
            return [0, 0.0];
        }

        $startedAt = microtime(true);

        try {
            DB::table('task_tiers_records')->insert($rows);
        } catch (Throwable $exception) {
            throw new TaskTiersImportException(
                step: 'import des lignes',
                message: 'Erreur SQL : '.$exception->getMessage(),
                row: $excelRow > 0 ? $excelRow : null,
                rowValues: $values !== [] ? $this->rowValuesForError($values, $columns) : [],
                columns: $this->columnsForError($columns),
                previous: $exception,
            );
        }

        $duration = microtime(true) - $startedAt;

        $this->logStep('Batch Tiers inséré', $job, [
            'excel_row' => $excelRow,
            'batch_size' => count($rows),
            'duration_seconds' => round($duration, 3),
        ]);

        return [count($rows), $duration];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function buildReport(TiersImportJob $job, array $stats, string $finalStatus): array
    {
        $warnings = array_values((array) ($stats['warnings'] ?? []));
        $automaticCorrections = collect($warnings)->where('category', 'automatic_correction')->count();
        $ignoredWarnings = collect($warnings)->where('category', 'ignored')->count();
        $interventionWarnings = collect($warnings)->where('category', 'intervention')->count();
        $elapsedSeconds = $this->startedAt > 0 ? max(0.001, microtime(true) - $this->startedAt) : null;
        $readRows = (int) ($stats['read_rows'] ?? 0);
        $hasWarnings = $automaticCorrections > 0 || $ignoredWarnings > 0 || $interventionWarnings > 0;

        return [
            'generated_at' => now()->toIso8601String(),
            'imported_at' => now()->toIso8601String(),
            'user' => [
                'id' => $job->created_by_user_id,
                'name' => $job->creator?->name,
            ],
            'file' => [
                'name' => $job->original_filename,
            ],
            'status' => $finalStatus,
            'status_label' => $finalStatus === 'failed'
                ? 'Échec'
                : ($hasWarnings ? 'Succès avec avertissements' : 'Succès'),
            'summary' => [
                'total_rows' => (int) ($job->total_lines ?? $readRows),
                'analyzed_rows' => $readRows,
                'imported_rows' => (int) ($stats['imported_rows'] ?? 0),
                'updated_rows' => (int) ($stats['updated_rows'] ?? 0),
                'ignored_rows' => (int) ($stats['ignored_rows'] ?? 0),
                'duplicates_count' => (int) ($stats['skipped_duplicates'] ?? 0),
                'automatic_corrections' => $automaticCorrections,
                'errors_count' => $interventionWarnings,
                'empty_rows' => (int) ($stats['empty_rows'] ?? 0),
            ],
            'warnings' => $warnings,
            'warnings_by_category' => [
                'automatic_correction' => $automaticCorrections,
                'ignored' => $ignoredWarnings,
                'intervention' => $interventionWarnings,
            ],
            'technical' => [
                'duration_seconds' => $elapsedSeconds ? round($elapsedSeconds, 3) : null,
                'duration_human' => $elapsedSeconds ? $this->humanDuration((int) round($elapsedSeconds)) : null,
                'average_rows_per_second' => $elapsedSeconds ? round($readRows / $elapsedSeconds, 2) : null,
                'file_read_seconds' => (float) ($stats['file_read_seconds'] ?? 0),
                'cleaning_seconds' => (float) ($stats['cleaning_seconds'] ?? 0),
                'insert_seconds' => (float) ($stats['insert_seconds'] ?? 0),
                'last_processed_line' => $this->currentLine ?: $job->current_line,
                'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'memory_limit' => ini_get('memory_limit'),
                'batches_processed' => (int) ($stats['batches_processed'] ?? 0),
                'batch_size' => (int) ($stats['batch_size'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function formatCompletionMessage(array $report): string
    {
        $summary = (array) ($report['summary'] ?? []);

        return implode("\n", [
            'Import terminé avec avertissements',
            '',
            number_format((int) ($summary['analyzed_rows'] ?? 0), 0, ',', ' ').' lignes analysées',
            number_format((int) ($summary['imported_rows'] ?? 0), 0, ',', ' ').' lignes importées',
            number_format((int) ($summary['updated_rows'] ?? 0), 0, ',', ' ').' lignes mises à jour',
            number_format((int) ($summary['ignored_rows'] ?? 0), 0, ',', ' ').' lignes ignorées',
            number_format((int) ($summary['automatic_corrections'] ?? 0), 0, ',', ' ').' corrections automatiques',
            number_format((int) ($summary['errors_count'] ?? 0), 0, ',', ' ').' lignes nécessitent une intervention',
        ]);
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
     * @return array<string, mixed>
     */
    private function makeWarning(
        int $row,
        ?string $column = null,
        ?string $originalValue = null,
        ?string $correctedValue = null,
        string $category = 'ignored',
        string $type = 'avertissement',
        string $action = '',
        string $message = '',
        array $context = [],
    ): array {
        return [
            'row' => $row,
            'column' => $column,
            'original_value' => $originalValue,
            'corrected_value' => $correctedValue,
            'category' => $category,
            'type' => $type,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<int, array<string, mixed>>  $columns
     */
    private function valueForWarningColumn(array $values, array $columns, ?string $columnLabel): ?string
    {
        if (! $columnLabel) {
            return null;
        }

        foreach ($columns as $column) {
            $label = (string) ($column['source_name'] ?? 'Colonne '.(((int) ($column['index'] ?? 0)) + 1));
            if ($label !== $columnLabel) {
                continue;
            }

            return (string) ($values[(int) ($column['index'] ?? 0)] ?? '');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private function cleanImportedValue(
        string $value,
        array $column,
        bool $skipFormatValidation,
        int $excelRow,
        array &$warnings,
    ): string
    {
        $cleaned = $value;
        $columnName = (string) ($column['source_name'] ?? 'Colonne');

        if ((bool) ($column['clean_values'] ?? false)) {
            $rules = (array) ($column['cleaning_rules'] ?? []);

            if (in_array('trim_spaces', $rules, true)) {
                $before = $cleaned;
                $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned);
                if ($cleaned !== $before) {
                    $warnings[] = $this->makeWarning(
                        row: $excelRow,
                        column: $columnName,
                        originalValue: $before,
                        correctedValue: $cleaned,
                        category: 'automatic_correction',
                        type: 'correction automatique',
                        action: 'Espaces normalisés',
                        message: 'Les espaces superflus ont été supprimés ou normalisés.',
                    );
                }
            }

            if (in_array('empty_na', $rules, true) && preg_match('/^(n\/a|na|#n\/a|null)$/iu', trim($cleaned))) {
                $before = $cleaned;
                $cleaned = '';
                $warnings[] = $this->makeWarning(
                    row: $excelRow,
                    column: $columnName,
                    originalValue: $before,
                    correctedValue: '',
                    category: 'automatic_correction',
                    type: 'correction automatique',
                    action: 'Valeur N/A vidée',
                    message: 'Une valeur N/A a été remplacée par une valeur vide.',
                );
            }

            if (in_array('empty_parasites', $rules, true) && preg_match('/^(0+|[-_.]+)$/u', trim($cleaned))) {
                $before = $cleaned;
                $cleaned = '';
                $warnings[] = $this->makeWarning(
                    row: $excelRow,
                    column: $columnName,
                    originalValue: $before,
                    correctedValue: '',
                    category: 'automatic_correction',
                    type: 'correction automatique',
                    action: 'Valeur parasite vidée',
                    message: 'Une valeur parasite a été remplacée par une valeur vide.',
                );
            }

            if (in_array('remove_useless_zeroes', $rules, true)) {
                if (preg_match('/^0+$/u', trim($cleaned))) {
                    $before = $cleaned;
                    $cleaned = '';
                    $warnings[] = $this->makeWarning(
                        row: $excelRow,
                        column: $columnName,
                        originalValue: $before,
                        correctedValue: '',
                        category: 'automatic_correction',
                        type: 'correction automatique',
                        action: 'Zéro parasite supprimé',
                        message: 'Un zéro isolé a été considéré comme vide.',
                    );
                } elseif (preg_match('/^\d+([,.]0+)$/u', trim($cleaned))) {
                    $before = $cleaned;
                    $cleaned = preg_replace('/([,.]0+)$/u', '', trim($cleaned)) ?? $cleaned;
                    if ($cleaned !== $before) {
                        $warnings[] = $this->makeWarning(
                            row: $excelRow,
                            column: $columnName,
                            originalValue: $before,
                            correctedValue: $cleaned,
                            category: 'automatic_correction',
                            type: 'correction automatique',
                            action: 'Zéros inutiles supprimés',
                            message: 'Les décimales nulles inutiles ont été supprimées.',
                        );
                    }
                }
            }
        }

        $format = (string) ($column['format_model'] ?? '');
        $beforeFormat = $cleaned;
        $cleaned = TiersColumnFormat::normalize($cleaned, $format);
        if ($cleaned !== $beforeFormat) {
            $warnings[] = $this->makeWarning(
                row: $excelRow,
                column: $columnName,
                originalValue: $beforeFormat,
                correctedValue: $cleaned,
                category: 'automatic_correction',
                type: 'correction automatique',
                action: 'Format appliqué',
                message: 'Le modèle de colonne a normalisé la valeur.',
            );
        }

        if (! $skipFormatValidation && ! TiersColumnFormat::isValid($cleaned, $format)) {
            throw new TaskTiersImportException(
                step: 'analyse des colonnes',
                message: TiersColumnFormat::validationMessage($format),
                column: (string) ($column['source_name'] ?? 'Colonne'),
            );
        }

        return $cleaned;
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array{index: int, label: string, value: string}>
     */
    private function rowValuesForError(array $values, array $columns): array
    {
        return collect($columns)
            ->map(function (array $column) use ($values): array {
                $index = (int) ($column['index'] ?? 0);

                return [
                    'index' => $index,
                    'label' => (string) ($column['source_name'] ?? 'Colonne '.($index + 1)),
                    'value' => (string) ($values[$index] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array{index: int, label: string}>
     */
    private function columnsForError(array $columns): array
    {
        return collect($columns)
            ->map(function (array $column): array {
                $index = (int) ($column['index'] ?? 0);

                return [
                    'index' => $index,
                    'label' => (string) ($column['source_name'] ?? 'Colonne '.($index + 1)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $data
     */
    private function rowIsEmpty(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function valueForColumnIndex(array $values, mixed $index): ?string
    {
        if ($index === null || $index === '') {
            return null;
        }

        $value = $values[(int) $index] ?? null;

        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    private function tickProgress(TiersImportJob $job, int $excelRow, int $readRows, int $totalRows, bool $force = false): void
    {
        if ($force || $readRows === 1 || $readRows % 1000 === 0) {
            $progress = min(98, 12 + (int) round(($readRows / max(1, $totalRows)) * 86));
            $this->currentLine = $excelRow;
            $this->totalLines = $totalRows;
            $this->lastProgress = $progress;

            $this->updateJob($job, [
                'progress' => $progress,
                'current_line' => $excelRow,
                'message' => 'Import des lignes... '.$progress.' %',
                'stats' => [
                    'diagnostics' => $this->diagnosticsContext($job),
                ],
            ]);

            $this->logStep('Étape 7 : insertion des données', $job, [
                'excel_row' => $excelRow,
                'read_rows' => $readRows,
                'total_rows' => $totalRows,
                'forced' => $force,
            ]);
        }
    }

    private function registerDiagnostics(TiersImportJob $job): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $this->lastQuery = [
                'sql' => Str::limit($query->sql, 2000),
                'bindings' => array_map(
                    fn (mixed $binding): string => Str::limit($this->stringifyCellValue($binding), 500),
                    array_slice($query->bindings, 0, 20),
                ),
                'bindings_count' => count($query->bindings),
                'time_ms' => $query->time,
            ];
        });

        register_shutdown_function(function () use ($job): void {
            $error = error_get_last();
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

            if (! $error || ! in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
                return;
            }

            $freshJob = TiersImportJob::query()->find($job->id);
            if (! $freshJob || in_array($freshJob->status, ['completed', 'failed', 'waiting_user'], true)) {
                return;
            }

            $diagnostics = $this->diagnosticsContext($freshJob);

            Log::critical('Import Tiers stopped by fatal PHP shutdown', [
                'job_id' => $freshJob->id,
                'fatal_error' => $error,
                ...$diagnostics,
            ]);

            $this->updateJob($freshJob, [
                'status' => 'failed',
                'progress' => (int) ($freshJob->progress ?? $this->lastProgress ?? 0),
                'message' => 'Import interrompu brutalement : '.$this->fatalErrorMessage($error),
                'error' => [
                    'step' => $this->lastStep ?: 'shutdown',
                    'row' => $freshJob->current_line ?: $this->currentLine,
                    'column' => null,
                    'detail' => $this->fatalErrorMessage($error),
                    'fatal_error' => $error,
                    'diagnostics' => $diagnostics,
                ],
                'failed_at' => now(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticsContext(TiersImportJob $job, ?Throwable $exception = null): array
    {
        $currentLine = $job->current_line ?: $this->currentLine;
        $totalLines = $job->total_lines ?: $this->totalLines;

        $context = [
            'current_line' => $currentLine,
            'total_lines' => $totalLines,
            'progress' => $job->progress ?: $this->lastProgress,
            'elapsed_seconds' => $this->startedAt > 0 ? round(microtime(true) - $this->startedAt, 3) : null,
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 2),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'memory_limit' => ini_get('memory_limit'),
            'last_step' => $this->lastStep,
            'last_query' => $this->lastQuery,
        ];

        if ($exception) {
            $context['exception'] = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function fatalErrorMessage(array $error): string
    {
        return trim(sprintf(
            '%s dans %s:%s',
            (string) ($error['message'] ?? 'Erreur fatale PHP'),
            (string) ($error['file'] ?? 'fichier inconnu'),
            (string) ($error['line'] ?? 'ligne inconnue'),
        ));
    }

    private function updateJob(TiersImportJob $job, array $attributes): void
    {
        $job->forceFill($attributes)->save();
        $job->refresh();
    }

    private function makeReader(string $extension): ReaderInterface
    {
        return match ($extension) {
            'xlsx' => new XlsxReader(),
            'csv' => new CsvReader(),
            'ods' => new OdsReader(),
            default => throw new TaskTiersImportException(
                step: 'lecture du fichier',
                message: 'Format de fichier non pris en charge.',
            ),
        };
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

    private function simplifyImportError(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $class = $exception::class;

        if (str_contains($class, 'TimeoutExceededException') || str_contains($message, 'timed out')) {
            return 'Import interrompu : timeout du worker queue dépassé.';
        }

        if (str_contains($message, 'Allowed memory size') || str_contains($message, 'exhausted')) {
            return 'Import interrompu : mémoire PHP insuffisante.';
        }

        if (str_contains($message, 'Base table or view not found')) {
            return 'Erreur : table Tiers non trouvée.';
        }

        if (str_contains($message, 'Data too long')) {
            return 'Erreur SQL : une colonne contient une valeur trop longue.';
        }

        if (str_contains($message, 'Integrity constraint violation')) {
            return 'Erreur SQL : contrainte de base de données non respectée.';
        }

        return 'Erreur import : '.$message;
    }

    private function logStep(string $step, TiersImportJob $job, array $context = []): void
    {
        $this->lastStep = $step;

        Log::info('task_tiers_import_step', [
            'step' => $step,
            'job_id' => $job->id,
            'config_id' => $job->import_config_id,
            'diagnostics' => $this->diagnosticsContext($job),
            ...$context,
        ]);
    }
}
