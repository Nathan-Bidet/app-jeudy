<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('leave_type_id')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('start_portion')->nullable();
            $table->string('end_portion')->nullable();
            $table->boolean('is_all_day')->default(true);
            $table->time('custom_start_time')->nullable();
            $table->time('custom_end_time')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('validator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->index(['target_user_id', 'status']);
            $table->index(['start_at', 'end_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};

