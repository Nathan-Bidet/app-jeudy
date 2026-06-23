<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('cotation_settings')->updateOrInsert(
            ['key' => 'fuel_grid_config'],
            [
                'section' => 'fuel_grid',
                'label' => 'Tableau prix carburant',
                'value' => null,
                'unit' => null,
                'note' => json_encode([
                    'vat_rate' => 20,
                    'sections' => [
                        [
                            'id' => 'fuel_grand_froid',
                            'label' => 'FUEL GRAND FROID',
                            'rows' => [
                                ['id' => 'fgf_1', 'tranche' => '0 à 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                                ['id' => 'fgf_2', 'tranche' => '1 000 à 1 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                                ['id' => 'fgf_3', 'tranche' => '2 000 L et +', 'ht' => null, 'gap' => 0, 'text' => ''],
                            ],
                        ],
                        [
                            'id' => 'gnr_agri',
                            'label' => 'GNR AGRI Enregistré',
                            'rows' => [
                                ['id' => 'gnra_1', 'tranche' => '0 à 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                                ['id' => 'gnra_2', 'tranche' => '1 000 à 1 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                                ['id' => 'gnra_3', 'tranche' => '2 000 L et +', 'ht' => null, 'gap' => 0, 'text' => ''],
                            ],
                        ],
                        [
                            'id' => 'gnr_taxe',
                            'label' => 'GNR Taxé',
                            'rows' => [
                                ['id' => 'gnrt_1', 'tranche' => '0 à 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                                ['id' => 'gnrt_2', 'tranche' => '1 000 à 1 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                                ['id' => 'gnrt_3', 'tranche' => '2 000 L et +', 'ht' => null, 'gap' => 0, 'text' => ''],
                            ],
                        ],
                    ],
                    'gnr_tax' => ['ht' => null, 'ttc' => null],
                    'gazole' => ['id' => 'gazole', 'tranche' => 'GAZOLE', 'ttc' => null, 'gap' => 0, 'text' => ''],
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('cotation_settings')
            ->where('key', 'fuel_grid_config')
            ->delete();
    }
};
