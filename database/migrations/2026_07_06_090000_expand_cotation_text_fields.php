<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cotation_manual_prices')) {
            Schema::table('cotation_manual_prices', function (Blueprint $table): void {
                if (Schema::hasColumn('cotation_manual_prices', 'product_name')) {
                    $table->string('product_name', 255)->change();
                }

                if (Schema::hasColumn('cotation_manual_prices', 'contract_code')) {
                    $table->string('contract_code', 255)->nullable()->change();
                }

                if (Schema::hasColumn('cotation_manual_prices', 'display_label')) {
                    $table->string('display_label', 255)->nullable()->change();
                }

                if (Schema::hasColumn('cotation_manual_prices', 'maturity_label')) {
                    $table->string('maturity_label', 255)->change();
                }
            });
        }

        if (Schema::hasTable('cotation_custom_cereals')) {
            Schema::table('cotation_custom_cereals', function (Blueprint $table): void {
                if (Schema::hasColumn('cotation_custom_cereals', 'name')) {
                    $table->string('name', 255)->change();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cotation_manual_prices')) {
            Schema::table('cotation_manual_prices', function (Blueprint $table): void {
                if (Schema::hasColumn('cotation_manual_prices', 'product_name')) {
                    $table->string('product_name', 80)->change();
                }

                if (Schema::hasColumn('cotation_manual_prices', 'contract_code')) {
                    $table->string('contract_code', 80)->nullable()->change();
                }

                if (Schema::hasColumn('cotation_manual_prices', 'display_label')) {
                    $table->string('display_label', 80)->nullable()->change();
                }

                if (Schema::hasColumn('cotation_manual_prices', 'maturity_label')) {
                    $table->string('maturity_label', 80)->change();
                }
            });
        }

        if (Schema::hasTable('cotation_custom_cereals')) {
            Schema::table('cotation_custom_cereals', function (Blueprint $table): void {
                if (Schema::hasColumn('cotation_custom_cereals', 'name')) {
                    $table->string('name', 80)->change();
                }
            });
        }
    }
};
