<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_fuel_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->date('delivery_date')->nullable();
            $table->string('site')->nullable();
            $table->foreignId('tiers_record_id')->nullable()->constrained('task_tiers_records')->nullOnDelete();
            $table->string('code_tiers')->nullable();
            $table->string('client_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('fuel_type')->nullable();
            $table->unsignedInteger('volume_liters')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('urgent')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delivery_date', 'id'], 'task_fuel_delivery_date_idx');
            $table->index(['code_tiers', 'client_name'], 'task_fuel_client_idx');
            $table->index('fuel_type', 'task_fuel_type_idx');
            $table->index('urgent', 'task_fuel_urgent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_fuel_deliveries');
    }
};
