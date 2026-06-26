<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_recipient_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->json('sector_ids')->nullable();
            $table->json('user_ids')->nullable();
            $table->json('excluded_user_ids')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipient_groups');
    }
};
