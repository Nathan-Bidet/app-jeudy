<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_fuel_deliveries', function (Blueprint $table): void {
            $table->index('site', 'task_fuel_site_idx');
            $table->index('phone', 'task_fuel_phone_idx');
            $table->index(['postal_code', 'city'], 'task_fuel_city_idx');
            $table->index('volume_liters', 'task_fuel_volume_idx');
            $table->index('created_at', 'task_fuel_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('task_fuel_deliveries', function (Blueprint $table): void {
            $table->dropIndex('task_fuel_site_idx');
            $table->dropIndex('task_fuel_phone_idx');
            $table->dropIndex('task_fuel_city_idx');
            $table->dropIndex('task_fuel_volume_idx');
            $table->dropIndex('task_fuel_created_at_idx');
        });
    }
};
