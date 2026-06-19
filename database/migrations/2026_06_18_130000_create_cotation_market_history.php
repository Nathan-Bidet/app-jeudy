<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cotation_market_prices');
        Schema::dropIfExists('cotation_market_refreshes');

        Schema::create('cotation_market_refreshes', function (Blueprint $table): void {
            $table->id();
            $table->string('source_url', 500);
            $table->boolean('is_success')->default(false);
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->index('fetched_at', 'cot_ref_fetched_idx');
            $table->index('is_success', 'cot_ref_success_idx');
        });

        Schema::create('cotation_market_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refresh_id')
                ->constrained('cotation_market_refreshes', indexName: 'cot_price_refresh_fk')
                ->cascadeOnDelete();
            $table->string('product_code', 16);
            $table->string('product_name', 80);
            $table->unsignedInteger('product_sort')->default(999);
            $table->string('contract_code', 80)->nullable();
            $table->string('maturity_label', 80);
            $table->unsignedTinyInteger('maturity_month')->nullable();
            $table->unsignedSmallInteger('maturity_year');
            $table->unsignedSmallInteger('harvest_year');
            $table->decimal('price', 12, 4);
            $table->string('raw_price', 80)->nullable();
            $table->unsignedInteger('maturity_sort')->default(999999);
            $table->timestamp('quoted_at')->nullable();
            $table->timestamps();

            $table->index(['product_code', 'harvest_year', 'maturity_sort'], 'cot_price_product_harvest_idx');
            $table->index(['harvest_year', 'maturity_year'], 'cot_price_harvest_maturity_idx');
            $table->index('quoted_at', 'cot_price_quoted_idx');
        });

        $now = now();
        foreach ([
            ['key' => 'harvest_left_year', 'label' => 'Récolte gauche', 'value' => (float) $now->year, 'sort_order' => 10],
            ['key' => 'harvest_right_year', 'label' => 'Récolte droite', 'value' => (float) ($now->year + 1), 'sort_order' => 20],
        ] as $row) {
            DB::table('cotation_settings')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'section' => 'display',
                    'label' => $row['label'],
                    'value' => $row['value'],
                    'unit' => null,
                    'note' => null,
                    'sort_order' => $row['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('cotation_settings')
            ->whereIn('key', ['harvest_left_year', 'harvest_right_year'])
            ->delete();

        Schema::dropIfExists('cotation_market_prices');
        Schema::dropIfExists('cotation_market_refreshes');
    }
};
