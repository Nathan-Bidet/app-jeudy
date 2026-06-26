<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hour_sheets', function (Blueprint $table): void {
            $table->boolean('is_continuous_day')->default(false)->after('is_not_worked');
        });
    }

    public function down(): void
    {
        Schema::table('hour_sheets', function (Blueprint $table): void {
            $table->dropColumn('is_continuous_day');
        });
    }
};
