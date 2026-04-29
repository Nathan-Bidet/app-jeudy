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
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('internal_number', 50)->nullable()->after('phone');
            $table->string('photo_path')->nullable()->after('internal_number');
            $table->string('glpi_url', 2048)->nullable()->after('photo_path');
            $table->string('depot_address')->nullable()->after('glpi_url');
            $table->date('birthday')->nullable()->after('depot_address');

            $table->date('driving_license_valid_until')->nullable()->after('birthday');
            $table->date('fimo_valid_until')->nullable()->after('driving_license_valid_until');
            $table->date('adr_valid_until')->nullable()->after('fimo_valid_until');
            $table->date('fco_valid_until')->nullable()->after('adr_valid_until');
            $table->date('caces_valid_until')->nullable()->after('fco_valid_until');
            $table->date('certiphyto_valid_until')->nullable()->after('caces_valid_until');
            $table->date('occupational_health_valid_until')->nullable()->after('certiphyto_valid_until');
            $table->date('sst_valid_until')->nullable()->after('occupational_health_valid_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'internal_number',
                'photo_path',
                'glpi_url',
                'depot_address',
                'birthday',
                'driving_license_valid_until',
                'fimo_valid_until',
                'adr_valid_until',
                'fco_valid_until',
                'caces_valid_until',
                'certiphyto_valid_until',
                'occupational_health_valid_until',
                'sst_valid_until',
            ]);
        });
    }
};
