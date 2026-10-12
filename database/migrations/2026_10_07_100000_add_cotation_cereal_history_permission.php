<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'cotations.cereals.history.view';

    /**
     * Les droits actuels de consultation des céréales sont recopiés pour que
     * personne ne perde l'accès à l'historique ; il pourra ensuite être retiré
     * individuellement depuis l'administration des accès.
     *
     * @var array<int, string>
     */
    private array $sources = ['cotations.cereals.view', 'cotations.cereals.edit'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['created_at' => $now, 'updated_at' => $now],
        );

        $permission = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->first();
        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->first();

        if ($adminRole && $permission) {
            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permission->id,
                'role_id' => $adminRole->id,
            ]);
        }

        foreach ($this->sources as $source) {
            $this->copyAssignments($source, $permission);
        }
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->first();

        if (! $permission) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
        DB::table('sector_permissions')->where('ability', self::PERMISSION)->delete();
        DB::table('access_exceptions')->where('ability', self::PERMISSION)->delete();
        DB::table('permissions')->where('id', $permission->id)->delete();
    }

    private function copyAssignments(string $source, object $target): void
    {
        $sourcePermission = DB::table('permissions')->where('name', $source)->where('guard_name', 'web')->first();

        if (! $sourcePermission) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $sourcePermission->id)->get(['role_id'])
            ->each(fn ($row) => DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $target->id,
                'role_id' => $row->role_id,
            ]));

        DB::table('model_has_permissions')->where('permission_id', $sourcePermission->id)->get(['model_type', 'model_id'])
            ->each(fn ($row) => DB::table('model_has_permissions')->updateOrInsert([
                'permission_id' => $target->id,
                'model_type' => $row->model_type,
                'model_id' => $row->model_id,
            ]));

        DB::table('sector_permissions')->where('ability', $source)->get(['sector_id'])
            ->each(fn ($row) => DB::table('sector_permissions')->updateOrInsert(
                ['sector_id' => $row->sector_id, 'ability' => self::PERMISSION],
                ['created_at' => now(), 'updated_at' => now()],
            ));

        // Seules les autorisations sont copiées : un refus « view » ne doit pas
        // être dupliqué tel quel, il est déjà bloquant via cereals.view.
        DB::table('access_exceptions')->where('ability', $source)->where('effect', 'allow')
            ->get(['user_id', 'sector_id', 'effect', 'created_by'])
            ->each(fn ($row) => DB::table('access_exceptions')->updateOrInsert(
                ['user_id' => $row->user_id, 'sector_id' => $row->sector_id, 'ability' => self::PERMISSION],
                ['effect' => $row->effect, 'created_by' => $row->created_by, 'created_at' => now(), 'updated_at' => now()],
            ));
    }
};
