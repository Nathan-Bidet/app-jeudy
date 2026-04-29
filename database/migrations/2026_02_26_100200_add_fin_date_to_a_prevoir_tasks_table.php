<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table) {
            $table->date('fin_date')->nullable()->after('date');
            $table->index('fin_date', 'aprev_fin_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table) {
            $table->dropIndex('aprev_fin_date_idx');
            $table->dropColumn('fin_date');
        });
    }
};

