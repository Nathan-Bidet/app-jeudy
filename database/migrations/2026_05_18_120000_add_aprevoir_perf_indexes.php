<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->index(['pointed', 'date'], 'aprev_pointed_date_idx');
            $table->index(['pointed', 'date', 'assignee_type', 'assignee_id'], 'aprev_pointed_date_assignee_idx');
            $table->index(['pointed', 'date', 'assignee_label_free'], 'aprev_pointed_date_free_idx');
            $table->index(['date', 'position'], 'aprev_date_position_idx');
        });
    }

    public function down(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->dropIndex('aprev_date_position_idx');
            $table->dropIndex('aprev_pointed_date_free_idx');
            $table->dropIndex('aprev_pointed_date_assignee_idx');
            $table->dropIndex('aprev_pointed_date_idx');
        });
    }
};
