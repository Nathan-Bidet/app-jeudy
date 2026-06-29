<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_poll_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('announcement_poll_id')->constrained('announcement_polls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('selected_option_ids')->nullable();
            $table->text('other_text')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_poll_id', 'user_id'], 'announcement_poll_response_unique');
            $table->index('announcement_id');
            $table->index('responded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_poll_responses');
    }
};
