<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_fuel_deliveries', function (Blueprint $table): void {
            $table->foreignId('recurring_id')
                ->nullable()
                ->constrained('task_fuel_recurrings')
                ->nullOnDelete()
                ->after('id');
            $table->date('recurring_occurrence_date')->nullable()->after('recurring_id');
            $table->unique(['recurring_id', 'recurring_occurrence_date'], 'fuel_recurring_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('task_fuel_deliveries', function (Blueprint $table): void {
            $table->dropUnique('fuel_recurring_occurrence_unique');
            $table->dropConstrainedForeignId('recurring_id');
            $table->dropColumn('recurring_occurrence_date');
        });
    }
};
