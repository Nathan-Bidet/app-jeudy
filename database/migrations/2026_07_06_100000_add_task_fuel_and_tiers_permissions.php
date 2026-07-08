<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $permissions = [
        'task.fuel.view',
        'task.fuel.update',
        'task.fuel.delete',
        'task.tiers.view',
        'task.tiers.import',
        'task.tiers.delete',
        'task.tiers.update',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->first();
        if (! $adminRole) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $adminRole->id,
            ]);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('sector_permissions')->whereIn('ability', $this->permissions)->delete();
        DB::table('access_exceptions')->whereIn('ability', $this->permissions)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
