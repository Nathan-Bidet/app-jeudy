<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->string('assignee_label_free', 255)->nullable()->after('assignee_id');
        });

        Schema::table('a_prevoir_tasks_archive', function (Blueprint $table): void {
            $table->string('assignee_label_free', 255)->nullable()->after('assignee_id');
        });

        Schema::table('ldt_entries', function (Blueprint $table): void {
            $table->string('assignee_label_free', 255)->default('')->after('assignee_id');
        });

        DB::statement("ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_type` ENUM('user','transporter','depot','free') NULL");
        DB::statement("ALTER TABLE `ldt_entries` MODIFY `assignee_type` ENUM('user','transporter','depot','free','none') NOT NULL DEFAULT 'none'");

        Schema::table('ldt_entries', function (Blueprint $table): void {
            $table->dropUnique('ldt_entries_date_assignee_unique');
            $table->dropIndex('ldt_entries_date_assignee_idx');
        });

        Schema::table('ldt_entries', function (Blueprint $table): void {
            $table->unique(['date', 'assignee_type', 'assignee_id', 'assignee_label_free'], 'ldt_entries_date_assignee_unique');
            $table->index(['date', 'assignee_type', 'assignee_id', 'assignee_label_free'], 'ldt_entries_date_assignee_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ldt_entries', function (Blueprint $table): void {
            $table->dropUnique('ldt_entries_date_assignee_unique');
            $table->dropIndex('ldt_entries_date_assignee_idx');
        });

        DB::statement("ALTER TABLE `ldt_entries` MODIFY `assignee_type` ENUM('user','transporter','depot','none') NOT NULL DEFAULT 'none'");
        DB::statement("ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_type` ENUM('user','transporter','depot') NULL");

        Schema::table('ldt_entries', function (Blueprint $table): void {
            $table->dropColumn('assignee_label_free');
            $table->unique(['date', 'assignee_type', 'assignee_id'], 'ldt_entries_date_assignee_unique');
            $table->index(['date', 'assignee_type', 'assignee_id'], 'ldt_entries_date_assignee_idx');
        });

        Schema::table('a_prevoir_tasks_archive', function (Blueprint $table): void {
            $table->dropColumn('assignee_label_free');
        });

        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->dropColumn('assignee_label_free');
        });
    }
};
