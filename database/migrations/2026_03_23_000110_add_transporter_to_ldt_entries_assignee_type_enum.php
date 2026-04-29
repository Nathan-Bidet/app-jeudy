<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `ldt_entries` MODIFY `assignee_type` ENUM('user','transporter','depot','none') NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `ldt_entries` SET `assignee_type` = 'none', `assignee_id` = 0 WHERE `assignee_type` = 'transporter'");
        DB::statement("ALTER TABLE `ldt_entries` MODIFY `assignee_type` ENUM('user','depot','none') NOT NULL DEFAULT 'none'");
    }
};
