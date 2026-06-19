<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotation_manual_prices') || Schema::hasColumn('cotation_manual_prices', 'display_label')) {
            return;
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            $table->string('display_label', 80)->nullable()->after('contract_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cotation_manual_prices') || ! Schema::hasColumn('cotation_manual_prices', 'display_label')) {
            return;
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            $table->dropColumn('display_label');
        });
    }
};
