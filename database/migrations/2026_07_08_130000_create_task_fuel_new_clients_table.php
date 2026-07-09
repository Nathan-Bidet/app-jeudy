<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_fuel_new_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_tiers_record_id')->constrained('task_tiers_records')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['validated_at', 'created_at'], 'task_fuel_new_clients_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_fuel_new_clients');
    }
};
