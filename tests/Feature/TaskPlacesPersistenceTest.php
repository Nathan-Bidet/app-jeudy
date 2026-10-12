<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\AprevoirTask;
use App\Models\Depot;
use App\Models\Sector;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function placesUser(): User
{
    $sector = Sector::query()->create(['name' => fake()->unique()->company(), 'slug' => fake()->unique()->slug()]);
    $role = Role::findOrCreate('places-test-'.fake()->unique()->word(), 'web');
    foreach (['a_prevoir.view', 'a_prevoir.create', 'a_prevoir.update'] as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function placesPayload(array $overrides = []): array
{
    return array_merge([
        'date' => '2026-06-12',
        'fin_date' => '2026-06-12',
        'task' => 'Livraison',
        'loading_place' => 'Le Montet',
        'delivery_place' => 'Le Marché',
        'is_direct' => true,
        'is_boursagri' => false,
    ], $overrides);
}

function seedDepots(): void
{
    Depot::query()->create(['name' => 'Le Marché', 'address_line1' => 'Le Plan de Foire', 'postal_code' => '03240', 'city' => 'Le Montet']);
    Depot::query()->create(['name' => 'Le Montet', 'address_line1' => '35 Route Départementale', 'postal_code' => '03240', 'city' => 'Le Montet']);
}

it('stores the selected depot names verbatim on create and update, and reloads them from the database', function (): void {
    $user = placesUser();

    $this->actingAs($user)->post(route('a_prevoir.tasks.store'), placesPayload())->assertSessionHasNoErrors();

    $task = AprevoirTask::query()->sole();
    expect($task->fresh()->loading_place)->toBe('Le Montet')
        ->and($task->fresh()->delivery_place)->toBe('Le Marché');

    $this->actingAs($user)
        ->put(route('a_prevoir.tasks.update', $task), placesPayload(['loading_place' => "Le Marché\nFerme Dupont", 'delivery_place' => 'Le Montet']))
        ->assertSessionHasNoErrors();

    expect($task->fresh()->loading_place)->toBe("Le Marché\nFerme Dupont")
        ->and($task->fresh()->delivery_place)->toBe('Le Montet');
});

it('serves the exact depot map so the list resolves Le Montet and Le Marché separately', function (): void {
    seedDepots();
    $user = placesUser();
    AprevoirTask::query()->create(placesPayload() + ['created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('a_prevoir.index'))->assertOk();
    preg_match('/data-page="([^"]*)"/', $response->getContent(), $matches);
    $props = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];

    expect($props['groups'][0]['tasks'][0]['loading_place'])->toBe('Le Montet')
        ->and($props['groups'][0]['tasks'][0]['delivery_place'])->toBe('Le Marché')
        ->and($props['reference']['depot_place_map'])->toBe([
            'Le Marché' => 'Le Plan de Foire, 03240 Le Montet, FR',
            'Le Montet' => '35 Route Départementale, 03240 Le Montet, FR',
        ]);
});
