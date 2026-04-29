<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->foreignId('remorque_id')
                ->nullable()
                ->after('vehicle_id')
                ->constrained('vehicles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('remorque_id');
        });
    }
};

