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

    /**
     * @return array{date:string,assignee_type:?string,assignee_id:?int,assignee_label_free:?string}
     */
    public static function snapshotFromTask(AprevoirTask $task): array
    {
        return [
            'date' => $task->date?->toDateString() ?? '',
            'assignee_type' => $task->assignee_type,
            'assignee_id' => $task->assignee_id !== null ? (int) $task->assignee_id : null,
            'assignee_label_free' => $task->assignee_label_free,
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
