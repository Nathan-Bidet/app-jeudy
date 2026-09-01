<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * « Voir toutes les tâches » : indépendante de l'accès à la page. Sans elle,
 * un utilisateur ne voit que ce qui le concerne.
 *
 * Volontairement non attribuée ici : le seeder ne tourne pas en production, et
 * l'accorder d'office reviendrait à ouvrir à tous ce que cette permission est
 * précisément censée restreindre.
 */
return new class extends Migration
{
    private const PERMISSION = 'maintenance.view.all';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['updated_at' => $now, 'created_at' => $now],
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', self::PERMISSION)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
