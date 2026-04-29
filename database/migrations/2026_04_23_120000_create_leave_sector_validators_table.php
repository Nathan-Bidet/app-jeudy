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
        Schema::create('leave_sector_validators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->foreignId('validator_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('sector_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_sector_validators');
    }
};

