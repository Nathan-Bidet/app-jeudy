<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ensemble_pls');
        Schema::dropIfExists('bennes');
        Schema::dropIfExists('poids_lourds');
    }

    public function down(): void
    {
        Schema::create('poids_lourds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->nullable()->unique();
            $table->string('name', 150)->nullable();
            $table->string('registration', 50)->nullable();
            $table->foreignId('depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'name']);
            $table->index('registration');
        });

        Schema::create('bennes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->nullable()->unique();
            $table->string('name', 150)->nullable();
            $table->string('registration', 50)->nullable();
            $table->foreignId('depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'name']);
            $table->index('registration');
        });

        Schema::create('ensemble_pls', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->nullable()->unique();
            $table->string('name', 150)->nullable();
            $table->string('registration', 50)->nullable();
            $table->foreignId('depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'name']);
            $table->index('registration');
        });
    }
};
