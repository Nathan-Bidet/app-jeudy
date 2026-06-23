<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        'cotations.admin',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('cotation_custom_cereals')) {
            Schema::create('cotation_custom_cereals', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 32)->unique('cot_custom_cereal_code_uq');
                $table->string('name', 80);
                $table->string('base_product_code', 16);
                $table->unsignedInteger('sort_order')->default(100);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['base_product_code', 'sort_order'], 'cot_custom_cereal_base_idx');
            });
        }

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
            DB::table('permissions')
                ->whereIn('name', $this->permissions)
                ->where('guard_name', 'web')
                ->pluck('id')
                ->each(function (int $permissionId) use ($adminRole): void {
                    DB::table('role_has_permissions')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => $adminRole->id,
                    ]);
                });
        }
    }

    public function down(): void
    {
        // Intentionally keep repaired tables and permissions.
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

        DB::table('permissions')
            ->whereIn('name', $targets)
            ->where('guard_name', 'web')
            ->get(['id', 'name'])
            ->each(function ($targetPermission) use ($sourcePermission, $source): void {
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
                    ->each(function ($row) use ($source, $targetPermission): void {
                        DB::table('sector_permissions')->updateOrInsert(
                            ['sector_id' => $row->sector_id, 'ability' => $targetPermission->name],
                            ['created_at' => now(), 'updated_at' => now()],
                        );
                    });

                DB::table('access_exceptions')
                    ->where('ability', $source)
                    ->get(['user_id', 'sector_id', 'effect', 'created_by'])
                    ->each(function ($row) use ($source, $targetPermission): void {
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
            });
    }
};
