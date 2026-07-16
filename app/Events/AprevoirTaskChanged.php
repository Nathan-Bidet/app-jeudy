<?php

namespace App\Events;

use App\Models\AprevoirTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AprevoirTaskChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $action,
        public readonly ?int $taskId = null,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
        public readonly array $meta = [],
    ) {
    }

    public static function snapshotFromTask(AprevoirTask $task): array
    {
        return [
            'date' => $task->date?->toDateString() ?? '',
            'fin_date' => $task->fin_date?->toDateString(),
            'assignee_type' => $task->assignee_type,
            'assignee_id' => $task->assignee_id !== null ? (int) $task->assignee_id : null,
            'assignee_label_free' => $task->assignee_label_free,
            'task_label' => $task->task,
            'loading_place' => $task->loading_place,
            'delivery_place' => $task->delivery_place,
            'comment' => $task->comment,
            'vehicle_id' => $task->vehicle_id !== null ? (int) $task->vehicle_id : null,
            'remorque_id' => $task->remorque_id !== null ? (int) $task->remorque_id : null,
            'is_direct' => (bool) $task->is_direct,
            'is_boursagri' => (bool) $task->is_boursagri,
            'boursagri_contract_number' => $task->boursagri_contract_number,
        ];
    }

    public static function groupKeyFromTask(AprevoirTask $task): string
    {
        return implode('|', [
            $task->date?->toDateString() ?? '',
            $task->assignee_type,
            (string) $task->assignee_id,
            $task->assignee_label_free ?? '',
        ]);
    }
}
