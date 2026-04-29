<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_user_mappings', function (Blueprint $table): void {
            $table->string('target_type', 50)->nullable()->after('source_column');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->index(['target_type', 'target_id']);
        });

        Schema::table('import_vehicle_mappings', function (Blueprint $table): void {
            $table->string('target_type', 50)->nullable()->after('new_vehicle_id');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->index(['target_type', 'target_id']);
        });

        Schema::table('import_driver_free_mappings', function (Blueprint $table): void {
            $table->string('target_type', 50)->nullable()->after('new_user_id');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->index(['target_type', 'target_id']);
        });

        DB::table('import_user_mappings')
            ->whereNull('target_id')
            ->whereNotNull('new_user_id')
            ->update([
                'target_type' => 'user',
                'target_id' => DB::raw('new_user_id'),
            ]);

        DB::table('import_vehicle_mappings')
            ->whereNull('target_id')
            ->whereNotNull('new_vehicle_id')
            ->update([
                'target_type' => 'vehicle',
                'target_id' => DB::raw('new_vehicle_id'),
            ]);

        DB::table('import_driver_free_mappings')
            ->whereNull('target_id')
            ->whereNotNull('new_user_id')
            ->update([
                'target_type' => 'user',
                'target_id' => DB::raw('new_user_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('import_driver_free_mappings', function (Blueprint $table): void {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropColumn(['target_type', 'target_id']);
        });

        Schema::table('import_vehicle_mappings', function (Blueprint $table): void {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropColumn(['target_type', 'target_id']);
        });

        Schema::table('import_user_mappings', function (Blueprint $table): void {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropColumn(['target_type', 'target_id']);
        });
    }
};
