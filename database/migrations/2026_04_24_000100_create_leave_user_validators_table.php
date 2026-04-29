<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_user_validators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('validator_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('target_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_user_validators');
    }
};
