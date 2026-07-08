<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_fuel_options', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'active', 'sort_order'], 'task_fuel_options_kind_active_idx');
            $table->unique(['kind', 'label'], 'task_fuel_options_kind_label_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_fuel_options');
    }
};
