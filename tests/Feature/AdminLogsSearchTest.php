<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Http\Middleware\LogUserActivity;
use App\Models\AuditLog;
use App\Models\Sector;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsureTwoFactorIsVerified::class,
        LogUserActivity::class,
    ]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function adminLogsUser(): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('admin-logs-test-'.fake()->unique()->word(), 'web');
    $role->givePermissionTo(Permission::findOrCreate('admin.logs.view', 'web'));

    $user = User::factory()->create([
        'sector_id' => $sector->id,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

it('filters audit logs by description search case insensitively', function (): void {
    $user = adminLogsUser();

    AuditLog::query()->create([
        'user_id' => $user->id,
        'user_name' => $user->name,
        'action' => 'update_task',
        'module' => 'a_prevoir',
        'description' => 'Modification du bon de livraison Alpha',
        'payload' => ['details' => 'texte sans importance'],
        'created_at' => now()->subMinutes(2),
    ]);

    AuditLog::query()->create([
        'user_id' => $user->id,
        'user_name' => $user->name,
        'action' => 'update_task',
        'module' => 'a_prevoir',
        'description' => 'Mise à jour planning',
        'payload' => ['details' => 'bon de livraison alpha'],
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.logs.index', ['search' => 'LIVRAISON alpha']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Logs/Index')
            ->where('filters.search', 'LIVRAISON alpha')
            ->has('logs.data', 1)
            ->where('logs.data.0.description', 'Modification du bon de livraison Alpha')
        );
});
