<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_user_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('old_user_id');
            $table->foreignId('new_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source_column', ['driver_id', 'created_by', 'updated_by'])->nullable();
            $table->timestamps();

            $table->unique(['old_user_id', 'source_column']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_user_mappings');
    }
};
