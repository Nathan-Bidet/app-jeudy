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

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            if (! Schema::hasColumn('cotation_manual_prices', 'line_type')) {
                $table->string('line_type', 16)->default('matif')->after('market_identity_hash');
            }
        });

        DB::table('cotation_manual_prices')
            ->where(function ($query): void {
                $query->whereNull('contract_code')
                    ->orWhere('contract_code', '');
            })
            ->whereNull('maturity_month')
            ->update([
                'line_type' => 'custom',
                'market_identity_hash' => null,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cotation_manual_prices') || ! Schema::hasColumn('cotation_manual_prices', 'line_type')) {
            return;
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            $table->dropColumn('line_type');
        });
    }
};
