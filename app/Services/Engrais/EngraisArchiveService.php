<?php

namespace App\Services\Engrais;

use App\Models\EngraisArchivedTask;
use App\Models\EngraisTask;
use App\Services\Aprevoir\AprevoirArchiveService;

class EngraisArchiveService extends AprevoirArchiveService
{
    protected function taskModelClass(): string
    {
        return EngraisTask::class;
    }

    protected function archivedTaskModelClass(): string
    {
        return EngraisArchivedTask::class;
    }

    protected function logModule(): string
    {
        return 'engrais';
    }

    protected function moduleLabel(): string
    {
        return 'Engrais';
    }
}
