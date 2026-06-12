<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\AprevoirTask;
use App\Models\EngraisTask;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function engraisUser(array $abilities): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('engrais-test-'.fake()->unique()->word(), 'web');

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

function engraisPayload(array $overrides = []): array
{
    return array_merge([
        'date' => '2026-06-12',
        'fin_date' => '2026-06-13',
        'assignee_type' => null,
        'assignee_id' => null,
        'assignee_label_free' => null,
        'vehicle_id' => null,
        'remorque_id' => null,
        'task' => 'Livraison engrais',
        'loading_place' => 'Dépôt',
        'delivery_place' => 'Client',
        'comment' => 'Test module Engrais',
        'is_direct' => true,
        'is_boursagri' => false,
        'boursagri_contract_number' => null,
    ], $overrides);
}

it('refuse access without engrais view permission', function (): void {
    $user = engraisUser([]);

    $this->actingAs($user)
        ->get(route('engrais.index'))
        ->assertForbidden();
});

it('shows the engrais page with its dedicated permission', function (): void {
    $user = engraisUser(['engrais.view']);

    $this->actingAs($user)
        ->get(route('engrais.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Engrais/Index')
            ->where('moduleConfig.key', 'engrais')
            ->where('moduleConfig.show_book', false)
        );
});

it('keeps create update delete and point permissions independent', function (): void {
    $user = engraisUser(['engrais.view']);

    $this->actingAs($user)
        ->post(route('engrais.tasks.store'), engraisPayload())
        ->assertForbidden();

    $user->givePermissionTo('engrais.create');

    $this->actingAs($user)
        ->post(route('engrais.tasks.store'), engraisPayload())
        ->assertRedirect();

    $task = EngraisTask::query()->sole();

    $this->actingAs($user)
        ->put(route('engrais.tasks.update', $task), engraisPayload(['task' => 'Modification refusée']))
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('engrais.tasks.point', $task), ['pointed' => true])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('engrais.tasks.destroy', $task))
        ->assertForbidden();
});

it('supports engrais CRUD and pointage without projecting to the work book', function (): void {
    $user = engraisUser([
        'engrais.view',
        'engrais.create',
        'engrais.update',
        'engrais.delete',
        'engrais.point',
    ]);

    $aprevoir = AprevoirTask::query()->create(engraisPayload([
        'task' => 'Tâche À prévoir existante',
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]));

    $this->actingAs($user)
        ->post(route('engrais.tasks.store'), engraisPayload())
        ->assertRedirect();

    $task = EngraisTask::query()->sole();
    expect($task->pointed)->toBeFalse();

    $this->actingAs($user)
        ->put(route('engrais.tasks.update', $task), engraisPayload(['task' => 'Engrais modifié']))
        ->assertRedirect();

    $this->actingAs($user)
        ->patch(route('engrais.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    expect($task->refresh()->pointed)->toBeTrue();
    expect(DB::table('ldt_entries')->count())->toBe(0);
    expect($aprevoir->refresh()->task)->toBe('Tâche À prévoir existante');

    $this->actingAs($user)
        ->delete(route('engrais.tasks.destroy', $task))
        ->assertRedirect();

    expect(EngraisTask::query()->count())->toBe(0);
    expect(AprevoirTask::query()->count())->toBe(1);
    expect(DB::table('ldt_entries')->count())->toBe(0);
});
