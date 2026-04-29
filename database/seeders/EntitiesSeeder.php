<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class EntitiesSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $rows = [
            ['code' => 'camion_loc', 'label' => 'Camion loc', 'sort_order' => 10],
            ['code' => 'camion', 'label' => 'Poids Lourds', 'sort_order' => 20],
            ['code' => 'tracteur', 'label' => 'Tracteur', 'sort_order' => 30],
            ['code' => 'benne', 'label' => 'Bennes', 'sort_order' => 40],
            ['code' => 'ensemble_pl', 'label' => 'Ensemble PL', 'sort_order' => 50],
            ['code' => 'vl', 'label' => 'VL', 'sort_order' => 60],
            ['code' => 'autre', 'label' => 'Autre', 'sort_order' => 70],
        ];

        foreach ($rows as $row) {
            VehicleType::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'label' => $row['label'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
