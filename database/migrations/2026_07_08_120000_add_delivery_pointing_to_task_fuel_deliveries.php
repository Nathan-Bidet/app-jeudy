<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_fuel_deliveries', function (Blueprint $table): void {
            $table->date('actual_delivery_date')->nullable()->after('delivery_date');
            $table->foreignId('delivered_driver_user_id')->nullable()->after('actual_delivery_date')->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable()->after('delivered_driver_user_id');
            $table->foreignId('delivered_pointed_by_user_id')->nullable()->after('delivered_at')->constrained('users')->nullOnDelete();

            $table->index(['actual_delivery_date', 'id'], 'task_fuel_actual_delivery_date_idx');
            $table->index('delivered_at', 'task_fuel_delivered_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('task_fuel_deliveries', function (Blueprint $table): void {
            $table->dropIndex('task_fuel_actual_delivery_date_idx');
            $table->dropIndex('task_fuel_delivered_at_idx');
            $table->dropConstrainedForeignId('delivered_pointed_by_user_id');
            $table->dropColumn('delivered_at');
            $table->dropConstrainedForeignId('delivered_driver_user_id');
            $table->dropColumn('actual_delivery_date');
        });
    }
};
