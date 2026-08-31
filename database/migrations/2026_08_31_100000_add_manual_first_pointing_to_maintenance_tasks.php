<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            // Marque une date métier fixée à la main par un responsable. Une fois
            // vrai, le pointage automatique ne recalcule plus jamais la date.
            $table->boolean('first_pointed_on_manual')->default(false)->after('first_pointed_on');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->dropColumn('first_pointed_on_manual');
        });
    }
};
