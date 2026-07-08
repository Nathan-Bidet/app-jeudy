<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('task_tiers_records', 'search_text')) {
            Schema::table('task_tiers_records', function (Blueprint $table): void {
                $table->text('search_text')->nullable()->after('data');
            });
        }

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('task_tiers_records', function (Blueprint $table): void {
            $table->fullText('search_text', 'task_tiers_records_search_text_fulltext');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('task_tiers_records', 'search_text')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                Schema::table('task_tiers_records', function (Blueprint $table): void {
                    $table->dropFullText('task_tiers_records_search_text_fulltext');
                });
            } catch (Throwable) {
            }
        }

        Schema::table('task_tiers_records', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};
