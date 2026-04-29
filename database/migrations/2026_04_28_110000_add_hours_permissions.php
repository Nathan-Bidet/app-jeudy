<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            'heures.view',
            'heures.create',
            'heures.export',
        ];

        foreach ($permissions as $name) {
            $exists = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', ['heures.view', 'heures.create', 'heures.export'])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
