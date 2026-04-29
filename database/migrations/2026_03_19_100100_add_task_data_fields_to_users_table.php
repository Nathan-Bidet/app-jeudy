<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'operations_comment')) {
                $table->text('operations_comment')->nullable()->after('eco_conduite_valid_until');
            }

            if (! Schema::hasColumn('users', 'display_order')) {
                $table->integer('display_order')->nullable()->after('operations_comment');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('users', 'operations_comment')) {
                $columnsToDrop[] = 'operations_comment';
            }

            if (Schema::hasColumn('users', 'display_order')) {
                $columnsToDrop[] = 'display_order';
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

