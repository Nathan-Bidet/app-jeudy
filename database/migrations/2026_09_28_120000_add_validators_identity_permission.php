<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Congés / Heures — voir l'identité des valideurs dans les écrans de
 * validation (App\Support\Validation\ValidatorIdentity).
 *
 * Attribuée au seul rôle admin, qui voit déjà tout via l'AccessManager :
 * aucun autre rôle, secteur ou exception utilisateur n'est modifié, le
 * comportement actuel (identité masquée) est donc conservé pour tous les
 * autres. Idempotente, sans suppression ni recréation.
 */
return new class extends Migration
{
    private const PERMISSION = 'conges_heures.validators_identity.view';

    public function up(): void
    {
        $now = now();

        $exists = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->exists();

        if (! $exists) {
            DB::table('permissions')->insert([
                'name' => self::PERMISSION,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId && $adminRoleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $adminRoleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
