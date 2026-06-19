<?php

namespace App\Console\Commands;

use App\Services\Cotations\CotationMarketService;
use Illuminate\Console\Command;

class CotationsRefreshCommand extends Command
{
    protected $signature = 'cotations:refresh';

    protected $description = 'Récupère les cours Euronext Cotations et les enregistre en base.';

    public function handle(CotationMarketService $marketService): int
    {
        $refresh = $marketService->refreshFromEuronext();

        if (! $refresh->is_success) {
            $this->warn(sprintf(
                'Cotations refresh failed: %s',
                $refresh->error_message ?: 'unknown error',
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('Cotations refreshed: %d rows.', (int) $refresh->row_count));

        return self::SUCCESS;
    }
}
