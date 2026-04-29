<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_vehicle_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('old_vehicle_id');
            $table->string('old_vehicle_free')->nullable();
            $table->foreignId('new_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->timestamps();

            $table->unique('old_vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_vehicle_mappings');
    }
};
