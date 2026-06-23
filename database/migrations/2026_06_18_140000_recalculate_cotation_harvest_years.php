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

        DB::statement(<<<'SQL'
            UPDATE cotation_market_prices
            SET harvest_year = maturity_year
            WHERE product_code IN ('ECO', 'EBM', 'EMA', 'EOB', 'EOR', 'ERS', 'ETR')
              AND maturity_year IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Data correction only; no safe reversal.
    }
};
