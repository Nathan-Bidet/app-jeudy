<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotation_manual_prices', function (Blueprint $table): void {
            $table->id();
            $table->char('identity_hash', 40)->unique('cot_manual_identity_uq');
            $table->string('line_type', 16)->default('custom');
            $table->string('product_code', 16);
            $table->string('product_name', 80);
            $table->unsignedInteger('product_sort')->default(999);
            $table->string('contract_code', 80)->nullable();
            $table->string('display_label', 80)->nullable();
            $table->string('maturity_label', 80);
            $table->unsignedTinyInteger('maturity_month')->nullable();
            $table->unsignedSmallInteger('maturity_year');
            $table->unsignedSmallInteger('harvest_year');
            $table->decimal('manual_matif', 12, 4)->nullable();
            $table->decimal('margin', 12, 4)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_code', 'harvest_year', 'maturity_year'], 'cot_manual_product_year_idx');
            $table->index(['harvest_year', 'maturity_year'], 'cot_manual_harvest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotation_manual_prices');
    }
};
