<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a_prevoir_color_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['task', 'comment', 'both']);
            $table->enum('match_type', ['contains', 'starts_with', 'regex']);
            $table->string('pattern');
            $table->string('text_color')->nullable();
            $table->string('bg_color')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'priority'], 'aprev_color_rules_active_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a_prevoir_color_rules');
    }
};

