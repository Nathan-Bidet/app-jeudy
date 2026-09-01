<?php

namespace Database\Seeders;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'directory.view',
            'a_prevoir.view',
            'a_prevoir.view.current_week_only',
            'a_prevoir.create',
            'a_prevoir.update',
            'a_prevoir.delete',
            'a_prevoir.point',
            'a_prevoir.partial_point',
            'a_prevoir.export',
            'a_prevoir.sms',
            'engrais.view',
            'engrais.view.current_week_only',
            'engrais.create',
            'engrais.update',
            'engrais.delete',
            'engrais.point',
            'engrais.export',
            'engrais.sms',
            'ldt.view',
            'ldt.view.all_assignees',
            'ldt.view.current_week_only',
            'ldt.export',
            'ldt.sms',
            'maintenance.view',
            'maintenance.view.all',
            'maintenance.create',
            'maintenance.request',
            'maintenance.comment_hidden.view',
            'maintenance.point',
            'task.data.view',
            'task.data.jeudy.view',
            'task.data.jeudy.manage',
            'task.data.transporters.view',
            'task.data.transporters.manage',
            'task.data.depots.view',
            'task.data.depots.manage',
            'task.archive.view',
            'task.archive.manage',
            'task.formatting.view',
            'task.formatting.manage',
            'calendar.view',
            'calendar.event.manage',
            'calendar.category.manage',
            'calendar.feed.manage',
            'cotations.view',
            'cotations.manage',
            'cotations.cereals.view',
            'cotations.cereals.edit',
            'cotations.fuel.view',
            'cotations.fuel.edit',
            'cotations.fuel.history.view',
            'cotations.admin',
            'heures.view',
            'heures.create',
            'heures.export',
            'admin.users.view',
            'admin.users.manage',
            'admin.sectors.view',
            'admin.sectors.manage',
            'admin.access.manage',
            'admin.logs.view',
            'admin.entities.view',
            'admin.entities.manage',
            'annonces.view',
            'annonces.create',
            'annonces.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $adminRole = Role::findOrCreate('admin', 'web');
        $userRole = Role::findOrCreate('utilisateur', 'web');

        $adminRole->syncPermissions($permissions);
        $userRole->syncPermissions([
            'dashboard.view',
            'directory.view',
        ]);

        $defaultSector = Sector::query()->firstOrCreate(
            ['slug' => 'general'],
            ['name' => 'General', 'description' => 'Secteur par défaut']
        );

        $adminUser = User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@app-jeudy.local')],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'ChangeMe123!')),
            ]
        );

        $adminUser->forceFill([
            'sector_id' => $defaultSector->id,
        ])->save();
        $adminUser->syncRoles(['admin']);

        User::query()->whereNull('sector_id')->update([
            'sector_id' => $defaultSector->id,
        ]);

        User::query()
            ->whereDoesntHave('roles')
            ->where('id', '!=', $adminUser->id)
            ->each(function (User $user): void {
                $user->syncRoles(['utilisateur']);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
