<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotation_market_prices')) {
            return;
        }

        DB::table('cotation_market_prices')
            ->whereNotNull('maturity_year')
            ->update([
                'harvest_year' => DB::raw('maturity_year'),
            ]);
    }

    public function down(): void
    {
        // Data correction only; no safe reversal.
    }
};
