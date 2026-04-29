<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->text('url');
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};

