<?php

namespace App\Console\Commands;

use App\Services\Engrais\EngraisArchiveService;
use Illuminate\Console\Command;

class EngraisArchiveOldCommand extends Command
{
    protected $signature = 'engrais:archive-old {--days=90}';

    protected $description = 'Archive les lignes Engrais pointées anciennes';

    public function handle(EngraisArchiveService $archiveService): int
    {
        $days = max(1, (int) $this->option('days'));
        $processed = $archiveService->archiveOldTasks(now()->subDays($days));
        $this->info("Archivage Engrais terminé ({$processed} ligne(s)).");

        return self::SUCCESS;
    }
}
