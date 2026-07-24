<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Depot;
use App\Models\Sector;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function vehiclesManagerUser(array $abilities = ['task.data.depots.view', 'task.data.depots.manage']): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('vehicles-test-'.fake()->unique()->word(), 'web');

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

function vehiclesAdminUser(): User
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

// Même critère d'éligibilité que Admin > Entités (VehiclesPanel.jsx) :
// secteur "chauffeur" (ou "chauffeur carb" / slug "commercial"), utilisateur actif.
function eligibleDriver(array $overrides = []): User
{
    $sector = Sector::query()->create([
        'name' => 'Chauffeur',
        'slug' => fake()->unique()->slug(),
    ]);

    return User::factory()->create(array_merge([
        'sector_id' => $sector->id,
        'is_active' => true,
    ], $overrides));
}

function vehicleTypeFor(string $section): VehicleType
{
    return match ($section) {
        'camions' => VehicleType::query()->create(['code' => 'camion', 'label' => 'Camion', 'is_active' => true, 'sort_order' => 1]),
        'remorques' => VehicleType::query()->create(['code' => 'remorques', 'label' => 'Remorque', 'is_active' => true, 'sort_order' => 2]),
        'vl' => VehicleType::query()->create(['code' => 'vl', 'label' => 'VL', 'is_active' => true, 'sort_order' => 3]),
        'ensembles_pl' => VehicleType::query()->create(['code' => 'ensemble_pl', 'label' => 'Ensemble PL', 'is_active' => true, 'sort_order' => 4]),
        default => throw new InvalidArgumentException($section),
    };
}

function makeVehicle(VehicleType $type, array $overrides = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'vehicle_type_id' => $type->id,
        'name' => 'Véhicule '.fake()->unique()->word(),
        'registration' => strtoupper(fake()->unique()->bothify('??-###-??')),
        'is_active' => true,
        'is_rental' => false,
    ], $overrides));
}

function vehiclePayload(string $section, int $vehicleTypeId, array $overrides = []): array
{
    return array_merge([
        'section' => $section,
        'vehicle_type_id' => $vehicleTypeId,
        'name' => 'Véhicule '.fake()->unique()->word(),
        'registration' => strtoupper(fake()->unique()->bothify('??-###-??')),
        'is_active' => true,
    ], $overrides);
}

$vehicleSections = ['camions', 'remorques', 'ensembles_pl', 'vl'];

// 1. Chargement du chauffeur existant (listing Tâches > Données)
it('loads the existing attached driver in the vehicle_sections listing', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $driver = eligibleDriver();
    $vehicle = makeVehicle($type, ['driver_user_id' => $driver->id]);

    $this->actingAs($manager)
        ->get(route('task.data.index', ['section' => $section]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('vehicle_sections.'.$section, fn ($vehicles) => collect($vehicles)
                ->firstWhere('id', $vehicle->id)['driver_user_id'] === $driver->id
            )
        );
})->with($vehicleSections);

// 2. Ajout d'un chauffeur à la création
it('lets a manager attach a driver when creating a vehicle', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $driver = eligibleDriver();

    $this->actingAs($manager)
        ->post(route('task.data.vehicles.store'), vehiclePayload($section, $type->id, [
            'driver_user_id' => $driver->id,
        ]))
        ->assertRedirect();

    $vehicle = Vehicle::query()->latest('id')->first();
    expect($vehicle->driver_user_id)->toBe($driver->id);
})->with($vehicleSections);

// 3. Modification du chauffeur rattaché
it('lets a manager change the attached driver from the edit modal', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $oldDriver = eligibleDriver();
    $newDriver = eligibleDriver();
    $vehicle = makeVehicle($type, ['driver_user_id' => $oldDriver->id]);

    $this->actingAs($manager)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'driver_user_id' => $newDriver->id,
        ]))
        ->assertRedirect();

    $vehicle->refresh();
    expect($vehicle->driver_user_id)->toBe($newDriver->id);
})->with($vehicleSections);

// 4. Suppression du chauffeur (relation optionnelle)
it('allows clearing the attached driver', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $driver = eligibleDriver();
    $vehicle = makeVehicle($type, ['driver_user_id' => $driver->id]);

    $this->actingAs($manager)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'driver_user_id' => '',
        ]))
        ->assertRedirect();

    $vehicle->refresh();
    expect($vehicle->driver_user_id)->toBeNull();
})->with($vehicleSections);

// 5 + 7. Écrit directement la colonne lue par Admin > Entités, visible sans synchronisation manuelle
it('updates the exact same driver_user_id column read by Admin > Entités and stays visible there immediately', function (string $section): void {
    $admin = vehiclesAdminUser();
    $type = vehicleTypeFor($section);
    $driver = eligibleDriver();
    $vehicle = makeVehicle($type);

    $this->actingAs($admin)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'driver_user_id' => $driver->id,
        ]))
        ->assertRedirect();

    $this->actingAs($admin)
        ->get(route('admin.entities'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('vehicles', fn ($vehicles) => collect($vehicles)
                ->firstWhere('id', $vehicle->id)['driver_user_id'] === $driver->id
            )
        );
})->with($vehicleSections);

// 7 bis. Une modification faite côté Admin > Entités est visible dans Tâches > Données
it('makes a driver change made from Admin > Entités immediately visible in Tâches > Données', function (): void {
    $admin = vehiclesAdminUser();
    $type = vehicleTypeFor('camions');
    $driver = eligibleDriver();
    $vehicle = makeVehicle($type);

    $this->actingAs($admin)
        ->put(route('admin.entities.vehicles.update', $vehicle), [
            'vehicle_mode' => 'vehicle',
            'vehicle_type_id' => $type->id,
            'driver_user_id' => $driver->id,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get(route('task.data.index', ['section' => 'camions']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('vehicle_sections.camions', fn ($vehicles) => collect($vehicles)
                ->firstWhere('id', $vehicle->id)['driver_user_id'] === $driver->id
            )
        );
});

// 6. Absence de toute nouvelle colonne / relation / duplication de la donnée
it('does not create any new vehicle or user record when attaching a driver', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $driver = eligibleDriver();
    $vehicle = makeVehicle($type);
    $vehicleCountBefore = Vehicle::query()->count();
    $userCountBefore = User::query()->count();

    $this->actingAs($manager)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'driver_user_id' => $driver->id,
        ]))
        ->assertRedirect();

    expect(Vehicle::query()->count())->toBe($vehicleCountBefore);
    expect(User::query()->count())->toBe($userCountBefore);
})->with($vehicleSections);

// 8. Rejet d'un chauffeur inexistant
it('rejects a nonexistent driver id', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $vehicle = makeVehicle($type);

    $this->actingAs($manager)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'driver_user_id' => 999999,
        ]))
        ->assertSessionHasErrors('driver_user_id');

    $vehicle->refresh();
    expect($vehicle->driver_user_id)->toBeNull();
})->with($vehicleSections);

// 8 bis. Rejet d'un chauffeur inactif, même s'il est transmis manuellement (pas de confiance
// uniquement dans la liste filtrée côté interface)
it('rejects an inactive driver id even though it is a real user', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $inactiveDriver = eligibleDriver(['is_active' => false]);
    $vehicle = makeVehicle($type);

    $this->actingAs($manager)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'driver_user_id' => $inactiveDriver->id,
        ]))
        ->assertSessionHasErrors('driver_user_id');

    $vehicle->refresh();
    expect($vehicle->driver_user_id)->toBeNull();
})->with($vehicleSections);

// 9. Refus de la modification sans permission
it('forbids updating a vehicle driver for a user without task.data.depots.manage', function (string $section): void {
    $outsider = vehiclesManagerUser([]);
    $type = vehicleTypeFor($section);
    $driver = eligibleDriver();
    $vehicle = makeVehicle($type);

    $this->actingAs($outsider)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'driver_user_id' => $driver->id,
        ]))
        ->assertForbidden();

    $vehicle->refresh();
    expect($vehicle->driver_user_id)->toBeNull();
})->with($vehicleSections);

// 10. Non-régression des autres champs du formulaire
it('still saves name, registration and depot alongside the driver', function (string $section): void {
    $manager = vehiclesManagerUser();
    $type = vehicleTypeFor($section);
    $depot = Depot::query()->create(['name' => 'Dépôt test '.fake()->unique()->word()]);
    $driver = eligibleDriver();
    $vehicle = makeVehicle($type);

    $this->actingAs($manager)
        ->put(route('task.data.vehicles.update', $vehicle), vehiclePayload($section, $type->id, [
            'name' => 'Nom modifié',
            'registration' => 'AA-999-ZZ',
            'depot_id' => $depot->id,
            'driver_user_id' => $driver->id,
        ]))
        ->assertRedirect();

    $vehicle->refresh();
    expect($vehicle->driver_user_id)->toBe($driver->id);
    expect($vehicle->depot_id)->toBe($depot->id);

    if ($section !== 'ensembles_pl') {
        expect($vehicle->name)->toBe('Nom modifié');
        expect($vehicle->registration)->toBe('AA-999-ZZ');
    }
})->with($vehicleSections);

// 11. Indépendance du chauffeur d'un Ensemble PL vis-à-vis du chauffeur du tracteur/des bennes
// (aucune règle de cascade n'existe dans le code actuel : on vérifie qu'on n'en introduit pas)
it('keeps an Ensemble PL driver independent from its tractor and bennes drivers', function (): void {
    $manager = vehiclesManagerUser();
    $camionType = vehicleTypeFor('camions');
    $remorqueType = vehicleTypeFor('remorques');
    $ensembleType = vehicleTypeFor('ensembles_pl');

    $tractorDriver = eligibleDriver();
    $ensembleDriver = eligibleDriver();

    $tractor = makeVehicle($camionType, ['driver_user_id' => $tractorDriver->id]);
    $benne = makeVehicle($remorqueType);

    $this->actingAs($manager)
        ->post(route('task.data.vehicles.store'), vehiclePayload('ensembles_pl', $ensembleType->id, [
            'driver_user_id' => $ensembleDriver->id,
            'tractor_vehicle_id' => $tractor->id,
            'benne_ids' => [$benne->id],
        ]))
        ->assertRedirect();

    $ensemble = Vehicle::query()->where('vehicle_type_id', $ensembleType->id)->latest('id')->first();

    expect($ensemble->driver_user_id)->toBe($ensembleDriver->id);

    $tractor->refresh();
    expect($tractor->driver_user_id)->toBe($tractorDriver->id);
});

// 12. Rendu responsive : couvert par la structure JSX (sous-grille "Dépôt de rattachement" /
// "Chauffeur rattaché" dans un même sm:col-span-2, cf. VehicleEntitiesTable.jsx) — non testable
// automatiquement en Feature test PHP, vérifié manuellement / visuellement.
