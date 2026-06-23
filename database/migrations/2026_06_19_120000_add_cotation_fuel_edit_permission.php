<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'cotations.fuel.edit', 'guard_name' => 'web'],
            ['created_at' => $now, 'updated_at' => $now],
        );

        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->first();
        $permission = DB::table('permissions')->where('name', 'cotations.fuel.edit')->where('guard_name', 'web')->first();

        if ($adminRole && $permission) {
            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permission->id,
                'role_id' => $adminRole->id,
            ]);
        }
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('name', 'cotations.fuel.edit')->where('guard_name', 'web')->first();

        if (! $permission) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
        DB::table('sector_permissions')->where('ability', 'cotations.fuel.edit')->delete();
        DB::table('access_exceptions')->where('ability', 'cotations.fuel.edit')->delete();
        DB::table('permissions')->where('id', $permission->id)->delete();
    }
};
