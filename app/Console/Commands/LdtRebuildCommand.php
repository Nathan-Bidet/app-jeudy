<?php

namespace App\Console\Commands;

use App\Services\Ldt\LdtProjectionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class LdtRebuildCommand extends Command
{
    protected $signature = 'ldt:rebuild {--from=} {--to=}';

    protected $description = 'Reconstruit la projection LDT depuis a_prevoir_tasks';

    public function handle(LdtProjectionService $projection): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        try {
            $fromDate = $from ? Carbon::parse((string) $from)->startOfDay() : null;
            $toDate = $to ? Carbon::parse((string) $to)->endOfDay() : null;
        } catch (\Throwable) {
            $this->error('Paramètres --from/--to invalides. Format attendu: YYYY-MM-DD');

            return self::INVALID;
        }

        if ($fromDate && $toDate && $fromDate->gt($toDate)) {
            $this->error('--from doit être antérieur ou égal à --to');

            return self::INVALID;
        }

        $result = $projection->rebuildRange($fromDate, $toDate);

        $this->info('Projection LDT reconstruite.');
        $this->line('Entrées reconstruites: '.(int) ($result['rebuilt'] ?? 0));
        $this->line('Entrées supprimées: '.(int) ($result['deleted'] ?? 0));

        return self::SUCCESS;
    }
}
