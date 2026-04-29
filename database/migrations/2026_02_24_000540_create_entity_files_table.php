<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_files', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 120);
            $table->unsignedBigInteger('attachable_id');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('mime_type', 150)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('version_group', 64)->nullable();
            $table->unsignedInteger('version_number')->default(1);
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id'], 'entity_files_attachable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_files');
    }
};
