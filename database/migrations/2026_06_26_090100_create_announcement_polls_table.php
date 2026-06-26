<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->unique()->constrained('announcements')->cascadeOnDelete();
            $table->string('poll_type', 20)->default('single');
            $table->boolean('allow_other')->default(false);
            $table->timestamps();
        });

        Schema::create('announcement_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_poll_id')->constrained('announcement_polls')->cascadeOnDelete();
            $table->string('label', 255);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['announcement_poll_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_poll_options');
        Schema::dropIfExists('announcement_polls');
    }
};
