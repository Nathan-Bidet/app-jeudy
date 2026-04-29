<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hour_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->time('morning_start')->nullable();
            $table->time('morning_end')->nullable();
            $table->time('afternoon_start')->nullable();
            $table->time('afternoon_end')->nullable();
            $table->unsignedInteger('total_minutes')->default(0);
            $table->boolean('has_breakfast_before_5')->default(false);
            $table->boolean('has_lunch')->default(false);
            $table->boolean('has_dinner_after_21')->default(false);
            $table->boolean('has_long_night')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hour_sheets');
    }
};
