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

        try {
            DB::statement('ALTER TABLE cotation_manual_prices DROP INDEX cot_manual_identity_uq');
        } catch (\Throwable) {
            // The index may already have been removed on partially migrated databases.
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            if (! Schema::hasColumn('cotation_manual_prices', 'market_identity_hash')) {
                $table->char('market_identity_hash', 40)->nullable()->after('identity_hash');
            }

            if (! Schema::hasColumn('cotation_manual_prices', 'line_type')) {
                $table->string('line_type', 16)->default('matif')->after('market_identity_hash');
            }

            if (! Schema::hasColumn('cotation_manual_prices', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('margin');
            }
        });

        DB::table('cotation_manual_prices')
            ->whereNull('market_identity_hash')
            ->update(['market_identity_hash' => DB::raw('identity_hash')]);

        foreach ([
            'cot_manual_line_idx' => 'ALTER TABLE cotation_manual_prices ADD INDEX cot_manual_line_idx (identity_hash)',
            'cot_manual_matif_idx' => 'ALTER TABLE cotation_manual_prices ADD INDEX cot_manual_matif_idx (market_identity_hash)',
            'cot_manual_display_idx' => 'ALTER TABLE cotation_manual_prices ADD INDEX cot_manual_display_idx (product_code, harvest_year, sort_order)',
        ] as $statement) {
            try {
                DB::statement($statement);
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cotation_manual_prices')) {
            return;
        }

        foreach (['cot_manual_display_idx', 'cot_manual_matif_idx', 'cot_manual_line_idx'] as $index) {
            try {
                DB::statement("ALTER TABLE cotation_manual_prices DROP INDEX {$index}");
            } catch (\Throwable) {
            }
        }

        Schema::table('cotation_manual_prices', function (Blueprint $table): void {
            if (Schema::hasColumn('cotation_manual_prices', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('cotation_manual_prices', 'market_identity_hash')) {
                $table->dropColumn('market_identity_hash');
            }
            if (Schema::hasColumn('cotation_manual_prices', 'line_type')) {
                $table->dropColumn('line_type');
            }
        });
    }
};
