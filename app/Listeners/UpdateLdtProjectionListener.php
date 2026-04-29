<?php

namespace App\Listeners;

use App\Events\AprevoirTaskChanged;
use App\Services\Ldt\LdtProjectionService;

class UpdateLdtProjectionListener
{
    public function __construct(private readonly LdtProjectionService $projection)
    {
    }

    public function handle(AprevoirTaskChanged $event): void
    {
        $this->projection->handleSourceTaskChanged(
            $event->taskId,
            $event->before,
            $event->after,
        );
    }
}
