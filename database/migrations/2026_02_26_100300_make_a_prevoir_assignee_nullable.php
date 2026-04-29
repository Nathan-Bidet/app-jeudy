<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_type` ENUM('user','depot') NULL");
        DB::statement('ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Fallback raisonnable pour rollback: rattache les lignes orphelines à leur créateur.
        DB::statement("
            UPDATE `a_prevoir_tasks`
            SET `assignee_type` = 'user', `assignee_id` = `created_by_user_id`
            WHERE `assignee_type` IS NULL OR `assignee_id` IS NULL
        ");

        DB::statement("ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_type` ENUM('user','depot') NOT NULL");
        DB::statement('ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_id` BIGINT UNSIGNED NOT NULL');
    }
};

