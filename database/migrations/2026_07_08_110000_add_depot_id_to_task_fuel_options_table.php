<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_fuel_options', function (Blueprint $table): void {
            $table->foreignId('depot_id')->nullable()->constrained('depots')->nullOnDelete()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('task_fuel_options', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('depot_id');
        });
    }
};
