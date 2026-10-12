<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotation_manual_prices')) {
            return;
        }

        // `margin` est déjà la Base : la marge calculée s'appelle `sale_margin`.
        if (! Schema::hasColumn('cotation_manual_prices', 'sale_margin')) {
            Schema::table('cotation_manual_prices', function (Blueprint $table): void {
                $table->decimal('sale_margin', 12, 2)->nullable()->after('sale_price_operation');
            });
        }

        // Initialisation des lignes existantes : prix de vente signé − base signée.
        // Requête directe (pas d'Eloquent) pour ne toucher ni updated_at ni updated_by.
        DB::table('cotation_manual_prices')
            ->select(['id', 'margin', 'margin_operation', 'sale_price', 'sale_price_operation'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $sale = abs((float) ($row->sale_price ?? 0)) * ($row->sale_price_operation === 'subtract' ? -1 : 1);
                    $base = abs((float) ($row->margin ?? 0)) * ($row->margin_operation === 'add' ? 1 : -1);

                    DB::table('cotation_manual_prices')
                        ->where('id', $row->id)
                        ->update(['sale_margin' => round($sale - $base, 2)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('cotation_manual_prices') && Schema::hasColumn('cotation_manual_prices', 'sale_margin')) {
            Schema::table('cotation_manual_prices', function (Blueprint $table): void {
                $table->dropColumn('sale_margin');
            });
        }
    }
};
