<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_tiers_import_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('original_filename')->nullable();
            $table->json('columns');
            $table->string('identification_column')->nullable();
            $table->string('reference_column')->nullable();
            $table->json('options')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('identification_column');
            $table->index('reference_column');
        });

        Schema::create('task_tiers_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_config_id')->nullable()->constrained('task_tiers_import_configs')->nullOnDelete();
            $table->string('source_row_hash', 64)->nullable();
            $table->string('primary_identifier')->nullable();
            $table->string('reference_value')->nullable();
            $table->json('data');
            $table->text('search_text')->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('source_row_hash');
            $table->index('primary_identifier');
            $table->index('reference_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_tiers_records');
        Schema::dropIfExists('task_tiers_import_configs');
    }
};
