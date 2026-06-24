<?php

namespace App\Console\Commands;

use App\Models\CotationCustomCereal;
use App\Models\CotationManualPrice;
use Illuminate\Console\Command;

class CotationsSyncCustomCerealNamesCommand extends Command
{
    protected $signature = 'cotations:sync-custom-cereal-names';

    protected $description = 'Resynchronise cotation_manual_prices.product_name sur le nom courant de chaque céréale personnalisée (cotation_custom_cereals.name).';

    public function handle(): int
    {
        $cereals = CotationCustomCereal::query()->get(['code', 'name']);

        if ($cereals->isEmpty()) {
            $this->info('Aucune céréale personnalisée à resynchroniser.');

            return self::SUCCESS;
        }

        $totalUpdated = 0;

        foreach ($cereals as $cereal) {
            $updated = CotationManualPrice::query()
                ->where('product_code', $cereal->code)
                ->where('product_name', '!=', $cereal->name)
                ->update(['product_name' => $cereal->name]);

            if ($updated > 0) {
                $this->info(sprintf('%s (%s) : %d ligne(s) resynchronisée(s).', $cereal->name, $cereal->code, $updated));
            }

            $totalUpdated += $updated;
        }

        $this->info(sprintf('Terminé : %d ligne(s) resynchronisée(s) au total.', $totalUpdated));

        return self::SUCCESS;
    }
}
