<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_driver_free_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('old_driver_free');
            $table->foreignId('new_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('old_driver_free');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_driver_free_mappings');
    }
};
