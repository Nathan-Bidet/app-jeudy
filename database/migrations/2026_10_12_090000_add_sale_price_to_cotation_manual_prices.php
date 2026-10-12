<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotation_manual_prices')) {
            return;
        }

        // Prix de vente (réservé au mode modification) : valeur positive + signe,
        // comme la base (margin / margin_operation). Lignes existantes : NULL, signe « add ».
        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            if (! Schema::hasColumn('cotation_manual_prices', 'sale_price')) {
                $table->decimal('sale_price', 12, 4)->nullable()->after('margin_operation');
            }
            if (! Schema::hasColumn('cotation_manual_prices', 'sale_price_operation')) {
                $table->string('sale_price_operation', 8)->default('add')->after('sale_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cotation_manual_prices')) {
            return;
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            foreach (['sale_price_operation', 'sale_price'] as $column) {
                if (Schema::hasColumn('cotation_manual_prices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
