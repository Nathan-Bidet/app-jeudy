<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('code_zeendoc', 120)->nullable()->after('registration');
            $table->foreignId('driver_user_id')->nullable()->after('code_zeendoc')->constrained('users')->nullOnDelete();
            $table->foreignId('driver_carb_user_id')->nullable()->after('driver_user_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_rental')->default(false)->after('sector_id');
            $table->foreignId('garage_id')->nullable()->after('is_rental')->constrained('garages')->nullOnDelete();
            $table->foreignId('tractor_vehicle_id')->nullable()->after('garage_id')->constrained('vehicles')->nullOnDelete();

            $table->index('code_zeendoc');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['code_zeendoc']);

            $table->dropConstrainedForeignId('tractor_vehicle_id');
            $table->dropConstrainedForeignId('garage_id');
            $table->dropColumn('is_rental');
            $table->dropConstrainedForeignId('driver_carb_user_id');
            $table->dropConstrainedForeignId('driver_user_id');
            $table->dropColumn('code_zeendoc');
        });
    }
};
