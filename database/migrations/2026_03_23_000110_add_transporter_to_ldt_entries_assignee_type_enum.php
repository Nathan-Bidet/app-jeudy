<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('ldt_entries', function (Blueprint $table): void {
                $table->string('assignee_type', 32)->default('none')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE `ldt_entries` MODIFY `assignee_type` ENUM('user','transporter','depot','none') NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE `ldt_entries` SET `assignee_type` = 'none', `assignee_id` = 0 WHERE `assignee_type` = 'transporter'");
        DB::statement("ALTER TABLE `ldt_entries` MODIFY `assignee_type` ENUM('user','depot','none') NOT NULL DEFAULT 'none'");
    }
};
