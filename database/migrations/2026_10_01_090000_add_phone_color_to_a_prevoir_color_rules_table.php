<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('a_prevoir_color_rules', 'phone_color')) {
            return;
        }

        Schema::table('a_prevoir_color_rules', function (Blueprint $table): void {
            $table->string('phone_color')->nullable()->after('bg_color');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('a_prevoir_color_rules', 'phone_color')) {
            return;
        }

        Schema::table('a_prevoir_color_rules', function (Blueprint $table): void {
            $table->dropColumn('phone_color');
        });
    }
};
