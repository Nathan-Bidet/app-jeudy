<?php

namespace App\Console\Commands;

use App\Services\Cotations\CotationMarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CotationsRefreshCommand extends Command
{
    protected $signature = 'cotations:refresh';

    protected $description = 'Récupère les cours Euronext Cotations et les enregistre en base.';

    public function handle(CotationMarketService $marketService): int
    {
        $refresh = $marketService->refreshFromEuronext();

        if ($refresh->is_success) {
            $this->info(sprintf('Cotations refreshed: %d rows.', (int) $refresh->row_count));

            return self::SUCCESS;
        }

        $error = $refresh->error_message ?: 'unknown error';

        // Échec définitif ou erreur technique : toujours remonté (code 1), la cause est déjà
        // consignée par le service.
        if (! $refresh->isTransientFailure()) {
            $this->error(sprintf('Cotations refresh failed: %s', $error));

            return self::FAILURE;
        }

        // Indisponibilité temporaire de la source : tolérée tant que les cours restent récents, la
        // tâche réessaie à la minute suivante. Au-delà du seuil, c'est un incident à traiter.
        $lastSuccess = $marketService->lastSuccessfulRefreshAt($refresh);
        $threshold = (int) config('cotations.refresh.alert_after_minutes', 15);
        $minutes = $lastSuccess ? (int) floor($lastSuccess->diffInMinutes(now())) : null;

        if ($threshold <= 0 || $lastSuccess === null || $minutes >= $threshold) {
            Log::error('Cotations : source des cours indisponible, cours non actualisés', [
                'refresh_id' => $refresh->id,
                'error' => $error,
                'last_success_at' => $lastSuccess?->toIso8601String(),
                'minutes_without_update' => $minutes,
                'alert_after_minutes' => $threshold,
            ]);
            $this->error(sprintf('Cotations refresh failed: %s (no update since %s)', $error, $lastSuccess?->toDateTimeString() ?? 'ever'));

            return self::FAILURE;
        }

        $this->warn(sprintf(
            'Cotations source temporarily unavailable: %s (last update %d min ago, retry next minute).',
            $error,
            $minutes,
        ));

        return self::SUCCESS;
    }
}
