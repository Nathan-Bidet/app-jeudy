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
            $table->date('nacelle_valid_until')->nullable()->after('certiphyto_valid_until');
            $table->date('eco_conduite_valid_until')->nullable()->after('nacelle_valid_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nacelle_valid_until',
                'eco_conduite_valid_until',
            ]);
        });
    }
};

