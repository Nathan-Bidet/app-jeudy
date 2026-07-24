<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
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

function jeudyManagerUser(array $abilities = ['task.data.jeudy.view', 'task.data.jeudy.manage']): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('jeudy-test-'.fake()->unique()->word(), 'web');

    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $user = User::factory()->create([
        'sector_id' => $sector->id,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function jeudyAdminUser(): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('admin', 'web');

    $user = User::factory()->create([
        'sector_id' => $sector->id,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function jeudyPayload(array $overrides = []): array
{
    return array_merge([
        'phone' => '0102030405',
        'mobile_phone' => '0607080910',
        'depot_ids' => [],
        'operations_comment' => 'RAS',
        'display_order' => 1,
    ], $overrides);
}

// 1. Chargement des deux dates existantes dans le modal (payload Personnels Jeudy)
it('loads the existing nacelle and eco-conduite dates in the jeudy personnel listing', function (): void {
    $admin = jeudyAdminUser();
    $collaborator = User::factory()->create([
        'sector_id' => $admin->sector_id,
        'nacelle_valid_until' => '2027-05-01',
        'eco_conduite_valid_until' => '2026-11-15',
    ]);

    $this->actingAs($admin)
        ->get(route('task.data.index', ['section' => 'jeudy']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jeudyPersonnel', fn ($users) => collect($users)
                ->firstWhere('id', $collaborator->id)['nacelle_valid_until'] === '2027-05-01'
                && collect($users)->firstWhere('id', $collaborator->id)['eco_conduite_valid_until'] === '2026-11-15'
            )
        );
});

// 2 + 3. Modification des deux dates depuis Personnels Jeudy met à jour les champs existants de l'annuaire
it('lets an admin update both validity dates from the jeudy modal, updating the same user row read by the Annuaire', function (): void {
    $admin = jeudyAdminUser();
    $collaborator = User::factory()->create(['sector_id' => $admin->sector_id]);

    $this->actingAs($admin)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload([
            'nacelle_valid_until' => '2027-06-01',
            'eco_conduite_valid_until' => '2027-07-01',
        ]))
        ->assertRedirect();

    $collaborator->refresh();
    expect($collaborator->nacelle_valid_until->toDateString())->toBe('2027-06-01');
    expect($collaborator->eco_conduite_valid_until->toDateString())->toBe('2027-07-01');

    // 10. Visible immédiatement dans les deux écrans (Personnels Jeudy + Annuaire)
    $this->actingAs($admin)
        ->get(route('task.data.index', ['section' => 'jeudy']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jeudyPersonnel', fn ($users) => collect($users)->firstWhere('id', $collaborator->id)['nacelle_valid_until'] === '2027-06-01'
                && collect($users)->firstWhere('id', $collaborator->id)['eco_conduite_valid_until'] === '2027-07-01'
            )
        );

    $this->actingAs($admin)
        ->get(route('directory.edit', $collaborator))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.nacelle_valid_until', '2027-06-01')
            ->where('profile.eco_conduite_valid_until', '2027-07-01')
        );
});

// 4. Aucune donnée dupliquée créée
it('does not create any new user or record when updating validity dates', function (): void {
    $admin = jeudyAdminUser();
    $collaborator = User::factory()->create(['sector_id' => $admin->sector_id]);
    $countBefore = User::query()->count();

    $this->actingAs($admin)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload([
            'nacelle_valid_until' => '2027-06-01',
            'eco_conduite_valid_until' => '2027-07-01',
        ]))
        ->assertRedirect();

    expect(User::query()->count())->toBe($countBefore);
});

// 5. Modification d'une seule date sans altérer l'autre
it('updates only nacelle without altering eco-conduite when only nacelle is submitted', function (): void {
    $admin = jeudyAdminUser();
    $collaborator = User::factory()->create([
        'sector_id' => $admin->sector_id,
        'eco_conduite_valid_until' => '2026-01-01',
    ]);

    $this->actingAs($admin)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload([
            'nacelle_valid_until' => '2027-09-01',
            'eco_conduite_valid_until' => '2026-01-01',
        ]))
        ->assertRedirect();

    $collaborator->refresh();
    expect($collaborator->nacelle_valid_until->toDateString())->toBe('2027-09-01');
    expect($collaborator->eco_conduite_valid_until->toDateString())->toBe('2026-01-01');
});

// 6. Effacement autorisé d'une date (cohérent avec l'existant : nullable)
it('allows clearing a validity date to null', function (): void {
    $admin = jeudyAdminUser();
    $collaborator = User::factory()->create([
        'sector_id' => $admin->sector_id,
        'nacelle_valid_until' => '2026-01-01',
    ]);

    $this->actingAs($admin)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload([
            'nacelle_valid_until' => '',
            'eco_conduite_valid_until' => null,
        ]))
        ->assertRedirect();

    $collaborator->refresh();
    expect($collaborator->nacelle_valid_until)->toBeNull();
    expect($collaborator->eco_conduite_valid_until)->toBeNull();
});

// 7. Rejet d'une date invalide
it('rejects an invalid date and leaves the stored value unchanged', function (): void {
    $admin = jeudyAdminUser();
    $collaborator = User::factory()->create([
        'sector_id' => $admin->sector_id,
        'nacelle_valid_until' => '2026-01-01',
    ]);

    $this->actingAs($admin)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload([
            'nacelle_valid_until' => 'not-a-date',
        ]))
        ->assertSessionHasErrors('nacelle_valid_until');

    $collaborator->refresh();
    expect($collaborator->nacelle_valid_until->toDateString())->toBe('2026-01-01');
});

// 8. Refus d'une modification sans permission (annuaire = admin uniquement)
it('silently ignores validity date changes from a non-admin manager, while still saving the other fields', function (): void {
    $manager = jeudyManagerUser();
    $collaborator = User::factory()->create([
        'sector_id' => $manager->sector_id,
        'nacelle_valid_until' => '2026-01-01',
        'operations_comment' => 'Ancien commentaire',
    ]);

    $this->actingAs($manager)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload([
            'operations_comment' => 'Nouveau commentaire',
            'nacelle_valid_until' => '2030-01-01',
            'eco_conduite_valid_until' => '2030-01-01',
        ]))
        ->assertRedirect();

    $collaborator->refresh();
    expect($collaborator->operations_comment)->toBe('Nouveau commentaire');
    expect($collaborator->nacelle_valid_until->toDateString())->toBe('2026-01-01');
    expect($collaborator->eco_conduite_valid_until)->toBeNull();
});

it('forbids the whole update for a user without the task.data.jeudy.manage ability', function (): void {
    $outsider = jeudyManagerUser([]);
    $collaborator = User::factory()->create();

    $this->actingAs($outsider)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload())
        ->assertForbidden();
});

// 9. Personnel sans relation valide (utilisateur inexistant) : aucune écriture fantôme
it('returns 404 and writes nothing when the targeted user does not exist', function (): void {
    $admin = jeudyAdminUser();

    $this->actingAs($admin)
        ->put('/tasks/data/jeudy/999999', jeudyPayload([
            'nacelle_valid_until' => '2027-01-01',
        ]))
        ->assertNotFound();
});

// 11. Non-régression des autres champs du modal
it('still saves phone, mobile, depot and comment alongside the validity dates', function (): void {
    $admin = jeudyAdminUser();
    $depot = \App\Models\Depot::query()->create(['name' => 'Dépôt test '.fake()->unique()->word()]);
    $collaborator = User::factory()->create(['sector_id' => $admin->sector_id]);

    $this->actingAs($admin)
        ->put(route('task.data.jeudy.update', $collaborator), jeudyPayload([
            'phone' => '0102030405',
            'mobile_phone' => '0611223344',
            'depot_ids' => [$depot->id],
            'operations_comment' => 'Commentaire test',
            'display_order' => 5,
            'nacelle_valid_until' => '2027-01-01',
            'eco_conduite_valid_until' => '2027-02-01',
        ]))
        ->assertRedirect();

    $collaborator->refresh();
    expect($collaborator->phone)->toBe('0102030405');
    expect($collaborator->mobile_phone)->toBe('0611223344');
    expect($collaborator->operations_comment)->toBe('Commentaire test');
    expect($collaborator->display_order)->toBe(5);
    expect($collaborator->depots()->pluck('depots.id')->all())->toBe([$depot->id]);
    expect($collaborator->nacelle_valid_until->toDateString())->toBe('2027-01-01');
    expect($collaborator->eco_conduite_valid_until->toDateString())->toBe('2027-02-01');
});
