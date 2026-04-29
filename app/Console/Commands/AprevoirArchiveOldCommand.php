<?php

namespace App\Console\Commands;

use App\Services\Aprevoir\AprevoirArchiveService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AprevoirArchiveOldCommand extends Command
{
    protected $signature = 'a-prevoir:archive-old';

    protected $description = 'Archive les lignes À Prévoir pointées de plus de 90 jours';

    public function handle(AprevoirArchiveService $archiveService): int
    {
        $cutoffDate = Carbon::today()->subDays(90)->startOfDay();

        $archivedCount = $archiveService->archiveOldTasks($cutoffDate);

        $this->info('Archivage À Prévoir terminé.');
        $this->line('Date cutoff: '.$cutoffDate->toDateString());
        $this->line('Lignes archivées: '.$archivedCount);

        return self::SUCCESS;
    }
}

