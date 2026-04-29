<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AprevoirTaskUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $taskId,
        public readonly ?string $date,
        public readonly ?string $assigneeType,
        public readonly ?int $assigneeId,
        public readonly string $action,
        public readonly ?string $clientMutationId = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('aprevoir.global')];
    }

    public function broadcastAs(): string
    {
        return 'aprevoir.task.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->taskId,
            'date' => $this->date,
            'assignee_type' => $this->assigneeType,
            'assignee_id' => $this->assigneeId,
            'action' => $this->action,
            'client_mutation_id' => $this->clientMutationId,
        ];
    }
}
