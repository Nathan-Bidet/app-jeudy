<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotation_manual_prices') || Schema::hasColumn('cotation_manual_prices', 'margin_operation')) {
            return;
        }

        // Toutes les lignes existantes gardent le comportement historique :
        // la base est soustraite du MATIF (« subtract »).
        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            $table->string('margin_operation', 8)->default('subtract')->after('margin');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cotation_manual_prices') || ! Schema::hasColumn('cotation_manual_prices', 'margin_operation')) {
            return;
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            $table->dropColumn('margin_operation');
        });
    }
};
