<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les instructions ENUM/MODIFY sont propres à MySQL. Sur les autres
        // pilotes (SQLite en test), on obtient le même résultat via le Schema
        // builder : colonnes nullables, type texte libre.
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
                $table->string('assignee_type', 32)->nullable()->change();
                $table->unsignedBigInteger('assignee_id')->nullable()->change();
            });

            return;
        }

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

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
                $table->string('assignee_type', 32)->nullable(false)->change();
                $table->unsignedBigInteger('assignee_id')->nullable(false)->change();
            });

            return;
        }

        DB::statement("ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_type` ENUM('user','depot') NOT NULL");
        DB::statement('ALTER TABLE `a_prevoir_tasks` MODIFY `assignee_id` BIGINT UNSIGNED NOT NULL');
    }
};

