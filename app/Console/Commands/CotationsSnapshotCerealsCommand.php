<?php

namespace App\Console\Commands;

use App\Http\Controllers\CotationController;
use App\Services\Cotations\CotationCerealHistoryService;
use Illuminate\Console\Command;

class CotationsSnapshotCerealsCommand extends Command
{
    protected $signature = 'cotations:snapshot-cereals';

    protected $description = 'Enregistre une version historique complète des cotations de céréales (ignorée si le contenu est inchangé).';

    public function handle(CotationCerealHistoryService $history, CotationController $controller): int
    {
        $snapshot = $history->record($controller->cerealSnapshotPayload(), 'daily');

        $this->info($snapshot
            ? sprintf('Version historique enregistrée pour le %s (#%d).', $snapshot->business_date->toDateString(), $snapshot->id)
            : 'Contenu inchangé : aucune nouvelle version.');

        return self::SUCCESS;
    }
}
