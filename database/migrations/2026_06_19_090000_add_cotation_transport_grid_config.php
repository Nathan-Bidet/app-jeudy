<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('cotation_settings')->updateOrInsert(
            ['key' => 'transport_grid_config'],
            [
                'section' => 'transport_grid',
                'label' => 'Tableau prix des transports',
                'value' => null,
                'unit' => null,
                'note' => json_encode([
                    'reference_key' => '',
                    'columns' => [
                        ['id' => 'col_1', 'label' => 'Colonne 1', 'reference_key' => '', 'base' => 0],
                        ['id' => 'col_2', 'label' => 'Colonne 2', 'reference_key' => '', 'base' => 0],
                    ],
                    'rows' => [
                        ['id' => 'row_1', 'label' => 'Ligne 1', 'base' => 0],
                        ['id' => 'row_2', 'label' => 'Ligne 2', 'base' => 0],
                    ],
                    'cells' => [],
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
            ->where('key', 'transport_grid_config')
            ->delete();
    }
};
