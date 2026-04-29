<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_ldt_plan', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('id');
            $table->unique('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_ldt_plan', function (Blueprint $table): void {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
