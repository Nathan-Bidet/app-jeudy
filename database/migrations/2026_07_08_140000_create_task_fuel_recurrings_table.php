<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_fuel_recurrings', function (Blueprint $table): void {
            $table->id();
            $table->string('client_name')->nullable();
            $table->string('code_tiers')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('site')->nullable();
            $table->string('fuel_type')->nullable();
            $table->unsignedInteger('volume_liters')->nullable();
            $table->boolean('urgent')->default(false);
            $table->text('comment')->nullable();
            $table->date('first_delivery_date');
            // recurrence_type: daily | weekly | weekdays | monthly
            $table->string('recurrence_type');
            // recurrence_config: { interval: N } or { days: [0-6] } (0=Mon, 6=Sun)
            $table->json('recurrence_config')->nullable();
            $table->unsignedSmallInteger('days_before')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_fuel_recurrings');
    }
};
