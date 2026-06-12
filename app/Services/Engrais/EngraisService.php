<?php

namespace App\Services\Engrais;

use App\Models\EngraisTask;
use App\Services\Aprevoir\AprevoirService;

class EngraisService extends AprevoirService
{
    protected function taskModelClass(): string
    {
        return EngraisTask::class;
    }

    protected function visibilityScopeModule(): string
    {
        return 'engrais';
    }

    protected function projectsToLdt(): bool
    {
        return false;
    }
}
