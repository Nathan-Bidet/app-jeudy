<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('show_on_dashboard')->default(false)->after('sent_at');
            $table->date('dashboard_expires_at')->nullable()->after('show_on_dashboard');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['show_on_dashboard', 'dashboard_expires_at']);
        });
    }
};
