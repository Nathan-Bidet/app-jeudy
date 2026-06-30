<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotation_manual_prices') || Schema::hasColumn('cotation_manual_prices', 'final_price_reference_key')) {
            return;
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            $table->string('final_price_reference_key', 180)->nullable()->after('manual_matif');
            $table->index('final_price_reference_key', 'cot_manual_final_ref_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cotation_manual_prices') || ! Schema::hasColumn('cotation_manual_prices', 'final_price_reference_key')) {
            return;
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            $table->dropIndex('cot_manual_final_ref_idx');
            $table->dropColumn('final_price_reference_key');
        });
    }
};
