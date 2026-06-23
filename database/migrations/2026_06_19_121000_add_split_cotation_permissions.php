<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $permissions = [
        'cotations.cereals.view',
        'cotations.cereals.edit',
        'cotations.fuel.view',
        'cotations.fuel.edit',
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

        $this->copyPermissionAssignments('cotations.view', ['cotations.cereals.view', 'cotations.fuel.view']);
        $this->copyPermissionAssignments('cotations.manage', ['cotations.cereals.edit']);
        $this->copyPermissionAssignments('cotations.cereals.edit', ['cotations.cereals.view']);
        $this->copyPermissionAssignments('cotations.fuel.edit', ['cotations.fuel.view']);

        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
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
    }

    public function down(): void
    {
        $removable = [
            'cotations.cereals.view',
            'cotations.cereals.edit',
            'cotations.fuel.view',
        ];

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $removable)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        if ($permissionIds !== []) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('sector_permissions')->whereIn('ability', $removable)->delete();
        DB::table('access_exceptions')->whereIn('ability', $removable)->delete();
        DB::table('permissions')->whereIn('name', $removable)->where('guard_name', 'web')->delete();
    }

    /**
     * @param  array<int, string>  $targets
     */
    private function copyPermissionAssignments(string $source, array $targets): void
    {
        $sourcePermission = DB::table('permissions')
            ->where('name', $source)
            ->where('guard_name', 'web')
            ->first();

        if (! $sourcePermission) {
            return;
        }

        $targetPermissions = DB::table('permissions')
            ->whereIn('name', $targets)
            ->where('guard_name', 'web')
            ->get(['id', 'name']);

        foreach ($targetPermissions as $targetPermission) {
            DB::table('role_has_permissions')
                ->where('permission_id', $sourcePermission->id)
                ->get(['role_id'])
                ->each(function ($row) use ($targetPermission): void {
                    DB::table('role_has_permissions')->updateOrInsert([
                        'permission_id' => $targetPermission->id,
                        'role_id' => $row->role_id,
                    ]);
                });

            DB::table('model_has_permissions')
                ->where('permission_id', $sourcePermission->id)
                ->get(['model_type', 'model_id'])
                ->each(function ($row) use ($targetPermission): void {
                    DB::table('model_has_permissions')->updateOrInsert([
                        'permission_id' => $targetPermission->id,
                        'model_type' => $row->model_type,
                        'model_id' => $row->model_id,
                    ]);
                });

            DB::table('sector_permissions')
                ->where('ability', $source)
                ->get(['sector_id'])
                ->each(function ($row) use ($targetPermission): void {
                    DB::table('sector_permissions')->updateOrInsert(
                        ['sector_id' => $row->sector_id, 'ability' => $targetPermission->name],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                });

            DB::table('access_exceptions')
                ->where('ability', $source)
                ->get(['user_id', 'sector_id', 'effect', 'created_by'])
                ->each(function ($row) use ($targetPermission): void {
                    DB::table('access_exceptions')->updateOrInsert(
                        [
                            'user_id' => $row->user_id,
                            'sector_id' => $row->sector_id,
                            'ability' => $targetPermission->name,
                        ],
                        [
                            'effect' => $row->effect,
                            'created_by' => $row->created_by,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                });
        }
    }
};
