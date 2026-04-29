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
            $table->text('totp_secret')->nullable()->after('password');
            $table->timestamp('totp_enabled_at')->nullable()->after('totp_secret');
            $table->unsignedTinyInteger('totp_attempts')->default(0)->after('totp_enabled_at');
            $table->timestamp('totp_locked_until')->nullable()->after('totp_attempts');
            $table->text('totp_recovery_codes')->nullable()->after('totp_locked_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'totp_secret',
                'totp_enabled_at',
                'totp_attempts',
                'totp_locked_until',
                'totp_recovery_codes',
            ]);
        });
    }
};
