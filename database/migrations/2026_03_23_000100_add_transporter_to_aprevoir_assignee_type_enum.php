<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_type` ENUM('user','transporter','depot') NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE `a_prevoir_tasks` SET `assignee_type` = NULL, `assignee_id` = NULL WHERE `assignee_type` = 'transporter'");
        DB::statement("ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_type` ENUM('user','depot') NULL");
    }
};

