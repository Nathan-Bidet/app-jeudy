<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('vehicle_ensemble_bennes');

        Schema::create('vehicle_ensemble_bennes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensemble_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('benne_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ensemble_vehicle_id', 'benne_vehicle_id'], 'veh_ens_ben_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_ensemble_bennes');
    }
};
