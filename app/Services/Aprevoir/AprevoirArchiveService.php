<?php

namespace App\Services\Aprevoir;

use App\Models\AprevoirArchivedTask;
use App\Models\AprevoirTask;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AprevoirArchiveService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    )
    {
    }

    public function archiveOldTasks(Carbon $cutoffDate): int
    {
        $cutoff = $cutoffDate->copy()->startOfDay();

        $result = DB::transaction(function () use ($cutoff): array {
            $taskModelClass = $this->taskModelClass();
            $taskIds = $taskModelClass::query()
                ->where('pointed', true)
                ->whereDate('date', '<=', $cutoff->toDateString())
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $archived = 0;
            $deduplicated = 0;

            foreach ($taskIds as $taskId) {
                $status = $this->archiveTaskById((int) $taskId, $cutoff, false);

                if ($status === 'archived') {
                    $archived++;
                } elseif ($status === 'deduplicated') {
                    $deduplicated++;
                }
            }

            return [
                'archived' => $archived,
                'deduplicated' => $deduplicated,
            ];
        });

        $processed = (int) $result['archived'] + (int) $result['deduplicated'];

        if ($processed > 0) {
            $this->auditLogService->log([
                'action' => 'archive_old_tasks',
                'module' => $this->logModule(),
                'description' => sprintf(
                    'Archivage %s exécuté (cutoff %s, %d lignes traitées)',
                    $this->moduleLabel(),
                    $cutoff->toDateString(),
                    $processed
                ),
                'payload' => [
                    'cutoff_date' => $cutoff->toDateString(),
                    'archived_count' => (int) $result['archived'],
                    'deduplicated_count' => (int) $result['deduplicated'],
                    'processed_count' => $processed,
                ],
            ]);
        }

        return $processed;
    }

    public function archiveTask(Model $task): bool
    {
        $cutoff = now()->subDays(90)->startOfDay();

        $status = DB::transaction(
            fn (): string => $this->archiveTaskById((int) $task->id, $cutoff, true)
        );

        return in_array($status, ['archived', 'deduplicated'], true);
    }

    public function restoreArchivedTask(Model $task): ?Model
    {
        return DB::transaction(function () use ($task): ?AprevoirTask {
            $archivedTaskModelClass = $this->archivedTaskModelClass();
            $archivedTask = $archivedTaskModelClass::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->first();

            if (! $archivedTask) {
                return null;
            }

            $duplicate = $this->findDuplicateActiveTask($archivedTask);

            if ($duplicate) {
                $archivedTask->delete();

                $this->auditLogService->log([
                    'action' => 'restore_archive_task_deduplicated',
                    'module' => $this->logModule(),
                    'description' => sprintf(
                        'Restauration archive #%d ignorée (doublon actif #%d)',
                        (int) $task->id,
                        (int) $duplicate->id
                    ),
                    'payload' => [
                        'archive_id' => (int) $task->id,
                        'original_task_id' => $archivedTask->original_task_id !== null ? (int) $archivedTask->original_task_id : null,
                        'active_task_id' => (int) $duplicate->id,
                        'date' => $archivedTask->date?->toDateString(),
                        'assignee_type' => $archivedTask->assignee_type,
                        'assignee_id' => $archivedTask->assignee_id !== null ? (int) $archivedTask->assignee_id : null,
                    ],
                ]);

                return $duplicate;
            }

            $position = $this->nextPositionForGroup(
                $archivedTask->date?->toDateString(),
                $archivedTask->assignee_type,
                $archivedTask->assignee_id !== null ? (int) $archivedTask->assignee_id : null,
                $archivedTask->assignee_label_free,
            );

            $taskModelClass = $this->taskModelClass();
            $restoredTask = $taskModelClass::query()->create([
                'date' => $archivedTask->date?->toDateString(),
                'fin_date' => $archivedTask->fin_date?->toDateString(),
                'assignee_type' => $archivedTask->assignee_type,
                'assignee_id' => $archivedTask->assignee_id !== null ? (int) $archivedTask->assignee_id : null,
                'assignee_label_free' => $archivedTask->assignee_label_free,
                'vehicle_id' => $archivedTask->vehicle_id !== null ? (int) $archivedTask->vehicle_id : null,
                'remorque_id' => $archivedTask->remorque_id !== null ? (int) $archivedTask->remorque_id : null,
                'task' => (string) $archivedTask->task,
                'loading_place' => $archivedTask->loading_place,
                'delivery_place' => $archivedTask->delivery_place,
                'comment' => $archivedTask->comment,
                'is_direct' => (bool) $archivedTask->is_direct,
                'is_boursagri' => (bool) $archivedTask->is_boursagri,
                'boursagri_contract_number' => $archivedTask->boursagri_contract_number,
                'indicators' => $archivedTask->indicators,
                'pointed' => (bool) $archivedTask->pointed,
                'pointed_at' => $archivedTask->pointed_at,
                'pointed_by_user_id' => $archivedTask->pointed_by_user_id !== null ? (int) $archivedTask->pointed_by_user_id : null,
                'position' => $position,
                'created_by_user_id' => $archivedTask->created_by_user_id !== null ? (int) $archivedTask->created_by_user_id : null,
                'updated_by_user_id' => $archivedTask->updated_by_user_id !== null ? (int) $archivedTask->updated_by_user_id : null,
            ]);

            $archivedTask->delete();

            $this->auditLogService->log([
                'action' => 'restore_archive_task',
                'module' => $this->logModule(),
                'description' => sprintf(
                    'Restauration archive #%d vers tâche active #%d',
                    (int) $task->id,
                    (int) $restoredTask->id
                ),
                'payload' => [
                    'archive_id' => (int) $task->id,
                    'restored_task_id' => (int) $restoredTask->id,
                    'original_task_id' => $archivedTask->original_task_id !== null ? (int) $archivedTask->original_task_id : null,
                    'date' => $restoredTask->date?->toDateString(),
                    'assignee_type' => $restoredTask->assignee_type,
                    'assignee_id' => $restoredTask->assignee_id !== null ? (int) $restoredTask->assignee_id : null,
                    'assignee_label_free' => $restoredTask->assignee_label_free,
                    'position' => (int) ($restoredTask->position ?? 0),
                ],
            ]);

            return $restoredTask;
        });
    }

    private function archiveTaskById(int $taskId, Carbon $cutoff, bool $logSingle): string
    {
        $taskModelClass = $this->taskModelClass();
        $task = $taskModelClass::query()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            return 'missing';
        }

        if (! $this->isArchivable($task, $cutoff)) {
            return 'skipped';
        }

        $archivedTaskModelClass = $this->archivedTaskModelClass();
        $alreadyArchived = $archivedTaskModelClass::query()
            ->where('original_task_id', $task->id)
            ->exists();

        $status = 'archived';

        if (! $alreadyArchived) {
            $archivedTaskModelClass::query()->create($this->buildArchivePayload($task));
        } else {
            $status = 'deduplicated';
        }

        $task->delete();

        if ($logSingle) {
            $this->auditLogService->log([
                'action' => 'archive_task',
                'module' => $this->logModule(),
                'description' => sprintf('Archivage tâche #%d', (int) $task->id),
                'payload' => [
                    'task_id' => (int) $task->id,
                    'date' => $task->date?->toDateString(),
                    'assignee_type' => $task->assignee_type,
                    'assignee_id' => $task->assignee_id !== null ? (int) $task->assignee_id : null,
                    'pointed' => (bool) $task->pointed,
                    'cutoff_date' => $cutoff->toDateString(),
                    'status' => $status,
                ],
            ]);
        }

        return $status;
    }

    private function isArchivable(Model $task, Carbon $cutoff): bool
    {
        if (! $task->pointed || $task->date === null) {
            return false;
        }

        return $task->date->copy()->startOfDay()->lte($cutoff);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildArchivePayload(Model $task): array
    {
        return [
            'original_task_id' => (int) $task->id,
            'date' => $task->date?->toDateString(),
            'fin_date' => $task->fin_date?->toDateString(),
            'assignee_type' => $task->assignee_type,
            'assignee_id' => $task->assignee_id !== null ? (int) $task->assignee_id : null,
            'assignee_label_free' => $task->assignee_label_free,
            'vehicle_id' => $task->vehicle_id !== null ? (int) $task->vehicle_id : null,
            'remorque_id' => $task->remorque_id !== null ? (int) $task->remorque_id : null,
            'task' => (string) $task->task,
            'loading_place' => $task->loading_place,
            'delivery_place' => $task->delivery_place,
            'comment' => $task->comment,
            'is_direct' => (bool) $task->is_direct,
            'is_boursagri' => (bool) $task->is_boursagri,
            'boursagri_contract_number' => $task->boursagri_contract_number,
            'indicators' => $task->indicators,
            'pointed' => (bool) $task->pointed,
            'pointed_at' => $task->pointed_at,
            'pointed_by_user_id' => $task->pointed_by_user_id !== null ? (int) $task->pointed_by_user_id : null,
            'position' => (int) ($task->position ?? 0),
            'created_by_user_id' => $task->created_by_user_id !== null ? (int) $task->created_by_user_id : null,
            'updated_by_user_id' => $task->updated_by_user_id !== null ? (int) $task->updated_by_user_id : null,
            'archived_at' => now(),
            'archived_by_system' => true,
            'created_at' => $task->created_at,
            'updated_at' => $task->updated_at,
        ];
    }

    private function findDuplicateActiveTask(Model $task): ?Model
    {
        $taskModelClass = $this->taskModelClass();
        $query = $taskModelClass::query()
            ->where('task', (string) $task->task)
            ->where('is_direct', (bool) $task->is_direct)
            ->where('is_boursagri', (bool) $task->is_boursagri)
            ->where('pointed', (bool) $task->pointed);

        $this->applyNullableWhere($query, 'date', $task->date?->toDateString());
        $this->applyNullableWhere($query, 'assignee_type', $task->assignee_type);
        $this->applyNullableWhere($query, 'assignee_id', $task->assignee_id);
        $this->applyNullableWhere($query, 'assignee_label_free', $task->assignee_label_free);
        $this->applyNullableWhere($query, 'vehicle_id', $task->vehicle_id);
        $this->applyNullableWhere($query, 'remorque_id', $task->remorque_id);
        $this->applyNullableWhere($query, 'fin_date', $task->fin_date?->toDateString());
        $this->applyNullableWhere($query, 'loading_place', $task->loading_place);
        $this->applyNullableWhere($query, 'delivery_place', $task->delivery_place);

        if ($task->comment === null) {
            $query->whereNull('comment');
        } else {
            $query->where('comment', (string) $task->comment);
        }

        if ($task->boursagri_contract_number === null) {
            $query->whereNull('boursagri_contract_number');
        } else {
            $query->where('boursagri_contract_number', (string) $task->boursagri_contract_number);
        }

        return $query->orderByDesc('id')->first();
    }

    private function applyNullableWhere($query, string $column, mixed $value): void
    {
        if ($value === null || $value === '') {
            $query->whereNull($column);

            return;
        }

        $query->where($column, $value);
    }

    private function nextPositionForGroup(?string $date, ?string $assigneeType, ?int $assigneeId, ?string $assigneeLabelFree): int
    {
        $taskModelClass = $this->taskModelClass();
        $query = $taskModelClass::query();

        if ($date === null || $date === '') {
            $query->whereNull('date');
        } else {
            $query->whereDate('date', $date);
        }

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

    protected function taskModelClass(): string
    {
        return AprevoirTask::class;
    }

    protected function archivedTaskModelClass(): string
    {
        return AprevoirArchivedTask::class;
    }

    protected function logModule(): string
    {
        return 'a_prevoir';
    }

    protected function moduleLabel(): string
    {
        return 'À Prévoir';
    }
}
