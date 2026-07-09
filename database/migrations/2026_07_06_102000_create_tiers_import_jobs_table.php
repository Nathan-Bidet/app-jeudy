<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers_import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_config_id')->nullable()->constrained('task_tiers_import_configs')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('original_filename')->nullable();
            $table->string('disk')->default('local');
            $table->string('file_path');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('current_line')->nullable();
            $table->unsignedInteger('total_lines')->nullable();
            $table->string('message')->nullable();
            $table->json('options')->nullable();
            $table->json('stats')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiers_import_jobs');
    }
};
