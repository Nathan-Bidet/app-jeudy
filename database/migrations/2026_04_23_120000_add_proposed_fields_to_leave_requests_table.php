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
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dateTime('proposed_start_at')->nullable()->after('decided_at');
            $table->dateTime('proposed_end_at')->nullable()->after('proposed_start_at');
            $table->string('proposed_start_portion')->nullable()->after('proposed_end_at');
            $table->string('proposed_end_portion')->nullable()->after('proposed_start_portion');
            $table->time('proposed_custom_start_time')->nullable()->after('proposed_end_portion');
            $table->time('proposed_custom_end_time')->nullable()->after('proposed_custom_start_time');
            $table->text('proposed_message')->nullable()->after('proposed_custom_end_time');
            $table->foreignId('proposed_by_user_id')->nullable()->after('proposed_message')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposed_by_user_id');
            $table->dropColumn([
                'proposed_start_at',
                'proposed_end_at',
                'proposed_start_portion',
                'proposed_end_portion',
                'proposed_custom_start_time',
                'proposed_custom_end_time',
                'proposed_message',
            ]);
        });
    }
};

