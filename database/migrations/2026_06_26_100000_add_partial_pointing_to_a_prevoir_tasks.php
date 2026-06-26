<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->boolean('partially_pointed')->default(false)->after('pointed_by_user_id');
            $table->dateTime('partially_pointed_at')->nullable()->after('partially_pointed');
            $table->foreignId('partially_pointed_by_user_id')->nullable()->after('partially_pointed_at')->constrained('users')->nullOnDelete();

            $table->index(['partially_pointed', 'date'], 'aprev_partial_pointed_date_idx');
        });

        DB::table('permissions')->updateOrInsert(
            ['name' => 'a_prevoir.partial_point', 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()],
        );

        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->first();
        $permissionId = DB::table('permissions')
            ->where('name', 'a_prevoir.partial_point')
            ->where('guard_name', 'web')
            ->value('id');

        if ($adminRole && $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $adminRole->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('a_prevoir_tasks', function (Blueprint $table): void {
            $table->dropIndex('aprev_partial_pointed_date_idx');
            $table->dropConstrainedForeignId('partially_pointed_by_user_id');
            $table->dropColumn(['partially_pointed', 'partially_pointed_at']);
        });

        $permissionIds = DB::table('permissions')
            ->where('name', 'a_prevoir.partial_point')
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        if ($permissionIds !== []) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('sector_permissions')->where('ability', 'a_prevoir.partial_point')->delete();
            DB::table('access_exceptions')->where('ability', 'a_prevoir.partial_point')->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
