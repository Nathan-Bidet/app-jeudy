<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LdtEntryUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ?int $entryId,
        public readonly ?string $date,
        public readonly ?int $assigneeId,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('ldt.global')];
    }

    public function broadcastAs(): string
    {
        return 'ldt.entry.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'entry_id' => $this->entryId,
            'date' => $this->date,
            'assignee_id' => $this->assigneeId,
        ];
    }
}
