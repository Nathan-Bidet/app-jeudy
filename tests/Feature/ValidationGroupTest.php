<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;
use App\Models\ValidationGroup;
use App\Models\ValidationGroupUser;
use App\Services\Validation\ValidationGroupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function validationAdmin(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

function validationMember(array $overrides = []): User
{
    return User::factory()->create(array_merge(['is_active' => true], $overrides));
}

function validationGroupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Atelier',
        'validator_1_id' => validationMember()->id,
        'validator_2_id' => validationMember()->id,
        'member_user_ids' => [],
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

it('crée un groupe avec ses deux valideurs et ses membres', function (): void {
    $admin = validationAdmin();
    $validator1 = validationMember();
    $validator2 = validationMember();
    $member = validationMember();

    $this->actingAs($admin)
        ->from(route('admin.leaves.index'))
        ->post(route('admin.leaves.validation-groups.store'), [
            'name' => '  Atelier  ',
            'validator_1_id' => $validator1->id,
            'validator_2_id' => $validator2->id,
            'member_user_ids' => [$member->id],
        ])
        ->assertRedirect(route('admin.leaves.index'))
        ->assertSessionHas('success');

    $group = ValidationGroup::query()->firstOrFail();

    expect($group->name)->toBe('Atelier')
        ->and((int) $group->validator_1_id)->toBe((int) $validator1->id)
        ->and((int) $group->validator_2_id)->toBe((int) $validator2->id)
        ->and($group->members()->pluck('users.id')->all())->toBe([$member->id]);
});

it('modifie un groupe et remplace sa composition', function (): void {
    $admin = validationAdmin();
    $group = app(ValidationGroupService::class)->create(validationGroupPayload([
        'member_user_ids' => [$first = validationMember()->id],
    ]));

    $second = validationMember();
    $newValidator = validationMember();

    $this->actingAs($admin)
        ->put(route('admin.leaves.validation-groups.update', $group), [
            'name' => 'Atelier 2',
            'validator_1_id' => $newValidator->id,
            'validator_2_id' => $group->validator_2_id,
            'member_user_ids' => [$second->id],
        ])
        ->assertSessionHas('success');

    $group->refresh();

    expect($group->name)->toBe('Atelier 2')
        ->and((int) $group->validator_1_id)->toBe((int) $newValidator->id)
        ->and($group->members()->pluck('users.id')->all())->toBe([$second->id]);

    // L'ancien membre est libéré, il peut rejoindre un autre groupe.
    expect(ValidationGroupUser::query()->where('user_id', $first)->exists())->toBeFalse();
});

it('supprime un groupe et libère ses membres', function (): void {
    $admin = validationAdmin();
    $member = validationMember();
    $group = app(ValidationGroupService::class)->create(validationGroupPayload([
        'member_user_ids' => [$member->id],
    ]));

    $this->actingAs($admin)
        ->delete(route('admin.leaves.validation-groups.destroy', $group))
        ->assertSessionHas('success');

    expect(ValidationGroup::query()->count())->toBe(0)
        ->and(ValidationGroupUser::query()->where('user_id', $member->id)->exists())->toBeFalse();

    // Libéré veut dire réaffectable.
    app(ValidationGroupService::class)->create(validationGroupPayload([
        'name' => 'Commerce',
        'member_user_ids' => [$member->id],
    ]));

    expect(ValidationGroupUser::query()->where('user_id', $member->id)->count())->toBe(1);
});

it('supprimer un groupe ne touche pas aux demandes de congé déjà validées', function (): void {
    // L'historique fige son valideur sur la demande elle-même : rien ne
    // référence le groupe, sa suppression ne peut donc pas le corrompre.
    $columns = DB::getSchemaBuilder()->getColumnListing('leave_requests');

    expect($columns)->toContain('validator_user_id')
        ->and($columns)->not->toContain('validation_group_id');
});

/*
|--------------------------------------------------------------------------
| Règle « un utilisateur = un seul groupe »
|--------------------------------------------------------------------------
*/

it('refuse d\'affecter un utilisateur déjà membre d\'un autre groupe', function (): void {
    $admin = validationAdmin();
    $member = validationMember();

    app(ValidationGroupService::class)->create(validationGroupPayload([
        'name' => 'Atelier',
        'member_user_ids' => [$member->id],
    ]));

    $this->actingAs($admin)
        ->post(route('admin.leaves.validation-groups.store'), [
            'name' => 'Commerce',
            'validator_1_id' => validationMember()->id,
            'validator_2_id' => validationMember()->id,
            'member_user_ids' => [$member->id],
        ])
        ->assertSessionHasErrors('member_user_ids');

    expect(ValidationGroupUser::query()->where('user_id', $member->id)->count())->toBe(1);
});

it('accepte de conserver un membre lors de la modification de son propre groupe', function (): void {
    $admin = validationAdmin();
    $member = validationMember();
    $group = app(ValidationGroupService::class)->create(validationGroupPayload([
        'member_user_ids' => [$member->id],
    ]));

    $this->actingAs($admin)
        ->put(route('admin.leaves.validation-groups.update', $group), [
            'name' => $group->name,
            'validator_1_id' => $group->validator_1_id,
            'validator_2_id' => $group->validator_2_id,
            'member_user_ids' => [$member->id],
        ])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->members()->pluck('users.id')->all())->toBe([$member->id]);
});

it('la base interdit une double appartenance, même par écriture directe', function (): void {
    $memberId = validationMember()->id;
    $service = app(ValidationGroupService::class);

    $first = $service->create(validationGroupPayload(['name' => 'Atelier', 'member_user_ids' => [$memberId]]));
    $second = $service->create(validationGroupPayload(['name' => 'Commerce']));

    ValidationGroupUser::query()->create([
        'validation_group_id' => $second->id,
        'user_id' => $memberId,
    ]);
})->throws(Illuminate\Database\QueryException::class);

it('le service rejette une affectation concurrente sans laisser le groupe à moitié modifié', function (): void {
    $service = app(ValidationGroupService::class);
    $memberId = validationMember()->id;
    $keptId = validationMember()->id;

    $service->create(validationGroupPayload(['name' => 'Atelier', 'member_user_ids' => [$memberId]]));
    $target = $service->create(validationGroupPayload(['name' => 'Commerce', 'member_user_ids' => [$keptId]]));

    // Second administrateur qui tente d'attraper un membre déjà pris.
    expect(fn () => $service->syncMembers($target, [$keptId, $memberId]))
        ->toThrow(ValidationException::class);

    // Atomicité : la composition de « Commerce » est intacte.
    expect($target->fresh()->members()->pluck('users.id')->all())->toBe([$keptId])
        ->and(ValidationGroupUser::query()->where('user_id', $memberId)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Valideurs
|--------------------------------------------------------------------------
*/

it('autorise un même utilisateur à être valideur de plusieurs groupes', function (): void {
    $admin = validationAdmin();
    $validator1 = validationMember();
    $validator2 = validationMember();

    foreach (['Atelier', 'Commerce', 'Direction'] as $name) {
        $this->actingAs($admin)
            ->post(route('admin.leaves.validation-groups.store'), [
                'name' => $name,
                'validator_1_id' => $validator1->id,
                'validator_2_id' => $validator2->id,
                'member_user_ids' => [],
            ])
            ->assertSessionHasNoErrors();
    }

    expect($validator1->validationGroupsAsValidator1()->count())->toBe(3)
        ->and($validator2->validationGroupsAsValidator2()->count())->toBe(3);
});

it('autorise un utilisateur à être Valideur 1 d\'un groupe et Valideur 2 d\'un autre', function (): void {
    $admin = validationAdmin();
    $a = validationMember();
    $b = validationMember();

    $this->actingAs($admin)->post(route('admin.leaves.validation-groups.store'), [
        'name' => 'Atelier',
        'validator_1_id' => $a->id,
        'validator_2_id' => $b->id,
        'member_user_ids' => [],
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->post(route('admin.leaves.validation-groups.store'), [
        'name' => 'Commerce',
        'validator_1_id' => $b->id,
        'validator_2_id' => $a->id,
        'member_user_ids' => [],
    ])->assertSessionHasNoErrors();

    expect($a->validationGroupsAsValidator1()->count())->toBe(1)
        ->and($a->validationGroupsAsValidator2()->count())->toBe(1);
});

it('autorise un valideur à être aussi membre d\'un groupe', function (): void {
    $admin = validationAdmin();
    $validator = validationMember();

    $this->actingAs($admin)->post(route('admin.leaves.validation-groups.store'), [
        'name' => 'Atelier',
        'validator_1_id' => $validator->id,
        'validator_2_id' => validationMember()->id,
        'member_user_ids' => [$validator->id],
    ])->assertSessionHasNoErrors();

    expect(ValidationGroup::query()->firstOrFail()->members()->pluck('users.id')->all())
        ->toBe([$validator->id]);
});

/*
|--------------------------------------------------------------------------
| Validations backend
|--------------------------------------------------------------------------
*/

it('refuse un nom vide', function (): void {
    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), validationGroupPayload(['name' => '   ']))
        ->assertSessionHasErrors('name');
});

it('refuse un nom déjà utilisé', function (): void {
    app(ValidationGroupService::class)->create(validationGroupPayload(['name' => 'Atelier']));

    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), validationGroupPayload(['name' => 'Atelier']))
        ->assertSessionHasErrors('name');
});

it('accepte de conserver son propre nom lors d\'une modification', function (): void {
    $group = app(ValidationGroupService::class)->create(validationGroupPayload(['name' => 'Atelier']));

    $this->actingAs(validationAdmin())
        ->put(route('admin.leaves.validation-groups.update', $group), [
            'name' => 'Atelier',
            'validator_1_id' => $group->validator_1_id,
            'validator_2_id' => $group->validator_2_id,
            'member_user_ids' => [],
        ])
        ->assertSessionHasNoErrors();
});

it('refuse un valideur inexistant', function (): void {
    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), validationGroupPayload([
            'validator_1_id' => 999999,
        ]))
        ->assertSessionHasErrors('validator_1_id');
});

it('refuse un Valideur 2 manquant', function (): void {
    $payload = validationGroupPayload();
    unset($payload['validator_2_id']);

    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), $payload)
        ->assertSessionHasErrors('validator_2_id');
});

it('refuse le même utilisateur comme Valideur 1 et Valideur 2', function (): void {
    $validator = validationMember();

    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), validationGroupPayload([
            'validator_1_id' => $validator->id,
            'validator_2_id' => $validator->id,
        ]))
        ->assertSessionHasErrors('validator_2_id');
});

it('refuse un valideur désactivé', function (): void {
    $inactive = validationMember(['is_active' => false]);

    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), validationGroupPayload([
            'validator_1_id' => $inactive->id,
        ]))
        ->assertSessionHasErrors('validator_1_id');
});

it('refuse un membre inexistant', function (): void {
    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), validationGroupPayload([
            'member_user_ids' => [999999],
        ]))
        ->assertSessionHasErrors('member_user_ids.0');
});

it('accepte un groupe sans aucun membre', function (): void {
    $this->actingAs(validationAdmin())
        ->post(route('admin.leaves.validation-groups.store'), validationGroupPayload(['member_user_ids' => []]))
        ->assertSessionHasNoErrors();

    expect(ValidationGroup::query()->firstOrFail()->members()->count())->toBe(0);
});

it('vide la référence au valideur supprimé sans emporter le groupe', function (): void {
    $validator1 = validationMember();
    $group = app(ValidationGroupService::class)->create(validationGroupPayload([
        'validator_1_id' => $validator1->id,
    ]));

    $validator1->delete();

    $group->refresh();

    expect($group->exists)->toBeTrue()
        ->and($group->validator_1_id)->toBeNull()
        ->and($group->validator_2_id)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

it('refuse la création, la modification et la suppression à un non-administrateur', function (): void {
    $intruder = validationMember();
    $group = app(ValidationGroupService::class)->create(validationGroupPayload());

    $this->actingAs($intruder)
        ->postJson(route('admin.leaves.validation-groups.store'), validationGroupPayload(['name' => 'Pirate']))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->putJson(route('admin.leaves.validation-groups.update', $group), validationGroupPayload())
        ->assertForbidden();

    $this->actingAs($intruder)
        ->deleteJson(route('admin.leaves.validation-groups.destroy', $group))
        ->assertForbidden();

    expect(ValidationGroup::query()->count())->toBe(1);
});

it('refuse les routes de groupes à un visiteur non authentifié', function (): void {
    $this->post(route('admin.leaves.validation-groups.store'), validationGroupPayload())
        ->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| Exploitation par les modules Congés et Heures
|--------------------------------------------------------------------------
*/

it('expose le groupe et les valideurs d\'un utilisateur', function (): void {
    $service = app(ValidationGroupService::class);
    $validator1 = validationMember();
    $validator2 = validationMember();
    $member = validationMember();

    $service->create([
        'name' => 'Chauffeurs',
        'validator_1_id' => $validator1->id,
        'validator_2_id' => $validator2->id,
        'member_user_ids' => [$member->id],
    ]);

    expect($service->groupFor($member)?->name)->toBe('Chauffeurs')
        ->and($service->resolveValidators($member)['validator_1']->id)->toBe($validator1->id)
        ->and($service->resolveValidators($member)['validator_2']->id)->toBe($validator2->id)
        ->and($service->resolvePrimaryValidator($member)->id)->toBe($validator1->id)
        ->and($member->fresh()->validationGroup?->name)->toBe('Chauffeurs')
        ->and($service->groupsValidatedBy($validator2)->pluck('name')->all())->toBe(['Chauffeurs']);
});

it('ne renvoie aucun groupe pour un utilisateur non affecté', function (): void {
    $service = app(ValidationGroupService::class);
    $orphan = validationMember();

    expect($service->groupFor($orphan))->toBeNull()
        ->and($service->resolvePrimaryValidator($orphan))->toBeNull()
        ->and($service->resolveValidators($orphan))->toBe(['validator_1' => null, 'validator_2' => null]);
});

it('bascule sur le Valideur 2 quand le Valideur 1 a été supprimé', function (): void {
    $service = app(ValidationGroupService::class);
    $validator1 = validationMember();
    $validator2 = validationMember();
    $member = validationMember();

    $service->create([
        'name' => 'Direction',
        'validator_1_id' => $validator1->id,
        'validator_2_id' => $validator2->id,
        'member_user_ids' => [$member->id],
    ]);

    $validator1->delete();

    expect($service->resolvePrimaryValidator($member)?->id)->toBe($validator2->id);
});

it('la page ADMIN - CONGÉS expose les groupes et les appartenances', function (): void {
    $admin = validationAdmin();
    $member = validationMember();

    app(ValidationGroupService::class)->create(validationGroupPayload([
        'name' => 'Atelier',
        'member_user_ids' => [$member->id],
    ]));

    $response = $this->actingAs($admin)->get(route('admin.leaves.index'));

    $response->assertOk();
    $response->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
        ->component('Admin/Leaves/Index')
        ->has('validationGroups', 1)
        ->where('validationGroups.0.name', 'Atelier')
        ->where('validationGroups.0.member_count', 1)
        ->where('validationGroupByUser.'.$member->id.'.group_name', 'Atelier')
    );
});
