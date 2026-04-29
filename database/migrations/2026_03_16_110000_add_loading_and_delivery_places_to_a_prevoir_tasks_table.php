<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->text('loading_place')->nullable()->after('task');
            $table->text('delivery_place')->nullable()->after('loading_place');
        });
    }

    public function down(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->dropColumn(['loading_place', 'delivery_place']);
        });
    }
};

