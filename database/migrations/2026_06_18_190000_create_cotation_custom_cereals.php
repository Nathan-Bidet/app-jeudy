<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotation_custom_cereals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique('cot_custom_cereal_code_uq');
            $table->string('name', 80);
            $table->string('base_product_code', 16);
            $table->unsignedInteger('sort_order')->default(100);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['base_product_code', 'sort_order'], 'cot_custom_cereal_base_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotation_custom_cereals');
    }
};
