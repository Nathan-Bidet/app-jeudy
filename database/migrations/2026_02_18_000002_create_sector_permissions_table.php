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
        Schema::create('sector_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->string('ability');
            $table->timestamps();

            $table->unique(['sector_id', 'ability']);
            $table->index('ability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sector_permissions');
    }
};
