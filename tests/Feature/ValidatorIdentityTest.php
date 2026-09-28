<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\AccessException;
use App\Models\Sector;
use App\Models\SectorPermission;
use App\Models\User;
use App\Support\Validation\ValidationStage;
use App\Support\Validation\ValidatorIdentity;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Permission conges_heures.validators_identity.view : nom des valideurs dans
 * les écrans de validation des congés et des heures. Sans elle, l'état reste
 * anonymisé et aucun nom ne quitte le serveur.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const IDENTITY_ABILITY = ValidatorIdentity::ABILITY;

/** Valideur rattaché à un secteur, avec accès au module Heures. */
function identityValidator(array $attributes, array $extraAbilities = []): User
{
    $user = hoursUser(array_merge(['heures.view', 'heures.create'], $extraAbilities));
    $user->update($attributes);

    return $user->fresh();
}

/** Circuit à deux valideurs : Alice (V1) et Bruno (V2). */
function identityCircuit(array $v1Abilities = [], array $v2Abilities = []): array
{
    $v1 = identityValidator(['first_name' => 'Alice', 'last_name' => 'Blanchet'], $v1Abilities);
    $v2 = identityValidator(['first_name' => 'Bruno', 'last_name' => 'Carré'], $v2Abilities);
    $requester = hoursUser();
    groupWith($v1, $v2, [$requester]);

    return [$v1, $v2, $requester];
}

function leavePagePayload($test, User $viewer): string
{
    $response = $test->actingAs($viewer)->get(route('leaves.index'));

    return json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
}

function hoursPagePayload($test, User $viewer): string
{
    $response = $test->actingAs($viewer)->get(route('hours.index'));

    return json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
}

/*
|--------------------------------------------------------------------------
| Avec la permission
|--------------------------------------------------------------------------
*/

it('affiche le nom des deux valideurs d\'un congé au lecteur autorisé', function (): void {
    [$v1, $v2, $requester] = identityCircuit([IDENTITY_ABILITY]);
    $leave = submitLeave($requester);
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));

    $this->actingAs($v1)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leaveRequestsToValidate.0.validation_summary.0.validator', ['name' => 'Alice Blanchet', 'state' => 'active'])
            ->where('leaveRequestsToValidate.0.validation_summary.1.validator', ['name' => 'Bruno Carré', 'state' => 'active'])
            ->where('leaveRequestsToValidate.0.validation_summary.1.label', 'Validé'));

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertOk()
        ->assertJsonPath('validation_summary.0.validator.name', 'Alice Blanchet')
        ->assertJsonPath('validation_summary.1.validator.name', 'Bruno Carré')
        ->assertJsonPath('validation_summary.1.label', 'Validé');
});

it('affiche le nom des deux valideurs d\'une journée d\'heures au lecteur autorisé', function (): void {
    [$v1, , $requester] = identityCircuit([IDENTITY_ABILITY]);
    submitHourSheet($requester);

    $this->actingAs($v1)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('hourSheetsToValidate', 1)
            ->where('hourSheetsToValidate.0.validation_summary.0.validator.name', 'Alice Blanchet')
            ->where('hourSheetsToValidate.0.validation_summary.1.validator.name', 'Bruno Carré')
            ->where('hourSheetsToValidate.0.validation_summary.1.label', 'En attente'));
});

it('ne nomme qu\'un valideur quand le circuit n\'en a qu\'un', function (): void {
    $v1 = identityValidator(['first_name' => 'Alice', 'last_name' => 'Blanchet'], [IDENTITY_ABILITY]);
    $requester = hoursUser();
    $group = groupWith($v1, identityValidator([]), [$requester]);
    $group->update(['validator_2_id' => null]);

    $leave = submitLeave($requester);

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonCount(1, 'validation_summary')
        ->assertJsonPath('validation_summary.0.validator.name', 'Alice Blanchet');
});

it('l\'administrateur voit le nom des valideurs', function (): void {
    [, , $requester] = identityCircuit();
    $leave = submitLeave($requester);

    $this->actingAs(twoStepAdmin())
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.0.validator.name', 'Alice Blanchet')
        ->assertJsonPath('validation_summary.1.validator.name', 'Bruno Carré');
});

/*
|--------------------------------------------------------------------------
| Sans la permission : comportement actuel, rien transmis
|--------------------------------------------------------------------------
*/

it('sans la permission, aucun nom de valideur ni clé validator n\'est transmis', function (): void {
    [$v1, , $requester] = identityCircuit();
    $leave = submitLeave($requester);
    submitHourSheet($requester);

    $this->actingAs($v1)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leaveRequestsToValidate.0.validation_summary', 2)
            ->missing('leaveRequestsToValidate.0.validation_summary.0.validator')
            ->where('leaveRequestsToValidate.0.validation_summary.0.label', 'En attente'));

    $json = $this->actingAs($v1)->getJson(route('leaves.show', $leave->id))->assertOk();
    $json->assertJsonMissingPath('validation_summary.0.validator');

    foreach ([leavePagePayload($this, $v1), hoursPagePayload($this, $v1), $json->getContent()] as $payload) {
        expect($payload)->not->toContain('Bruno Carré')
            ->and($payload)->not->toContain('"validator"');
    }
});

it('un Allow explicite accorde l\'affichage sans permission de rôle', function (): void {
    [$v1, , $requester] = identityCircuit();
    AccessException::query()->create([
        'user_id' => $v1->id,
        'sector_id' => null,
        'ability' => IDENTITY_ABILITY,
        'effect' => 'allow',
    ]);
    $leave = submitLeave($requester);

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.1.validator.name', 'Bruno Carré');
});

it('un Deny explicite l\'emporte sur la permission du rôle', function (): void {
    [$v1, , $requester] = identityCircuit([IDENTITY_ABILITY]);
    AccessException::query()->create([
        'user_id' => $v1->id,
        'sector_id' => null,
        'ability' => IDENTITY_ABILITY,
        'effect' => 'deny',
    ]);
    $leave = submitLeave($requester);
    submitHourSheet($requester);

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonMissingPath('validation_summary.1.validator');

    expect(hoursPagePayload($this, $v1))->not->toContain('Bruno Carré');
});

it('un Deny explicite l\'emporte sur un défaut de secteur', function (): void {
    [$v1, , $requester] = identityCircuit();
    SectorPermission::query()->create(['sector_id' => $v1->sector_id, 'ability' => IDENTITY_ABILITY]);
    $leave = submitLeave($requester);

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.1.validator.name', 'Bruno Carré');

    AccessException::query()->create([
        'user_id' => $v1->id,
        'sector_id' => null,
        'ability' => IDENTITY_ABILITY,
        'effect' => 'deny',
    ]);

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonMissingPath('validation_summary.1.validator');
});

/*
|--------------------------------------------------------------------------
| Replis : compte désactivé, supprimé, nom incomplet
|--------------------------------------------------------------------------
*/

it('signale un valideur désactivé depuis la soumission', function (): void {
    [$v1, $v2, $requester] = identityCircuit([IDENTITY_ABILITY]);
    $leave = submitLeave($requester);
    $v2->update(['is_active' => false]);

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.1.validator', ['name' => 'Bruno Carré', 'state' => 'inactive']);
});

/*
 * La suppression d'un compte vide validator_N_id. Pour le rang 2, cela
 * ramène déjà le circuit à un seul rang (hasSecondValidationLevel) : le repli
 * s'observe donc sur le rang 1, toujours affiché.
 */
it('reprend le libellé figé pour un valideur dont le compte est supprimé', function (): void {
    [$v1, $v2, $requester] = identityCircuit([], [IDENTITY_ABILITY]);
    $leave = submitLeave($requester);
    $v1->delete();

    $this->actingAs($v2)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.0.validator', ['name' => 'Alice Blanchet', 'state' => 'deleted'])
        ->assertJsonPath('validation_summary.1.validator', ['name' => 'Bruno Carré', 'state' => 'active']);
});

it('se replie proprement quand prénom ou nom manquent', function (): void {
    [$v1, $v2, $requester] = identityCircuit([IDENTITY_ABILITY]);
    $leave = submitLeave($requester);

    $v2->forceFill(['first_name' => null, 'last_name' => 'Carré'])->save();
    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.1.validator.name', 'Carré');

    $v2->forceFill(['first_name' => null, 'last_name' => null, 'name' => 'b.carre'])->save();
    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.1.validator.name', 'b.carre');

    $v2->forceFill(['name' => ''])->save();
    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.1.validator.name', 'Bruno Carré');
});

it('se replie sur « Valideur inconnu » sans compte ni libellé', function (): void {
    [$v1, $v2, $requester] = identityCircuit([], [IDENTITY_ABILITY]);
    $leave = submitLeave($requester);
    DB::table('leave_requests')->where('id', $leave->id)->update(['validator_1_label' => null]);
    $v1->delete();

    $this->actingAs($v2)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('validation_summary.0.validator', ['name' => 'Valideur inconnu', 'state' => 'unknown']);
});

/*
|--------------------------------------------------------------------------
| Périmètres : secteurs, visibilité des demandes
|--------------------------------------------------------------------------
*/

it('un défaut accordé à un autre secteur ne donne rien', function (): void {
    [$v1, , $requester] = identityCircuit();
    $otherSector = Sector::query()->create(['name' => 'Autre', 'slug' => 'autre-'.fake()->unique()->word()]);
    SectorPermission::query()->create(['sector_id' => $otherSector->id, 'ability' => IDENTITY_ABILITY]);
    AccessException::query()->create([
        'user_id' => $v1->id,
        'sector_id' => $otherSector->id,
        'ability' => IDENTITY_ABILITY,
        'effect' => 'allow',
    ]);
    $leave = submitLeave($requester);

    $this->actingAs($v1)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonMissingPath('validation_summary.1.validator');
});

it('la permission n\'élargit pas l\'accès aux demandes', function (): void {
    [, , $requester] = identityCircuit();
    $outsider = identityValidator(['first_name' => 'Zoé', 'last_name' => 'Martin'], [IDENTITY_ABILITY]);
    $requesterWithPermission = identityValidator([], [IDENTITY_ABILITY]);
    groupWith(identityValidator([]), identityValidator([]), [$requesterWithPermission], 'Bureau');

    $leave = submitLeave($requester);
    submitHourSheet($requester);
    submitLeave($requesterWithPermission);

    // Valideur d'aucun groupe : ni la demande, ni la journée.
    $this->actingAs($outsider)->getJson(route('leaves.show', $leave->id))->assertForbidden();
    $this->actingAs($outsider)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Assert $page) => $page->where('leaveRequestsToValidate', []));
    $this->actingAs($outsider)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page->where('hourSheetsToValidate', []));

    // Demandeur autorisé : « Mes demandes » reste sans détail des rangs.
    $this->actingAs($requesterWithPermission)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('myLeaveRequests', 1)
            ->where('myLeaveRequests.0.validation_summary', null));
});

/*
|--------------------------------------------------------------------------
| Non-régression et performances
|--------------------------------------------------------------------------
*/

it('garde validations, compteurs et décisions inchangés', function (): void {
    [$v1, $v2, $requester] = identityCircuit([IDENTITY_ABILITY]);
    $leave = submitLeave($requester);
    $sheet = submitHourSheet($requester);

    $this->actingAs($v1)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingValidationCount', 1));

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id))->assertSessionHasNoErrors();
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id))->assertSessionHasNoErrors();
    $this->actingAs($v1)->post(route('hours.approve', $sheet->id))->assertSessionHasNoErrors();

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED)
        ->and($sheet->fresh()->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED);

    $this->actingAs($v1)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingValidationCount', 0)
            ->where('hourSheetsToValidate', []));
});

it('charge les valideurs sans requête par demande', function (): void {
    [$v1, , $requester] = identityCircuit([IDENTITY_ABILITY]);
    submitLeave($requester);

    // Requêtes sur la table users pendant l'affichage de la file.
    $userQueries = function () use ($v1): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($v1)->get(route('leaves.index'))->assertOk();
        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "users"'))
            ->values()
            ->all();
        DB::disableQueryLog();

        return $queries;
    };

    $withOne = $userQueries();

    foreach (range(1, 4) as $index) {
        submitLeaveOn($requester, sprintf('2026-11-%02d', $index * 3), sprintf('2026-11-%02d', $index * 3 + 1));
    }

    $withFive = $userQueries();

    expect(count($withFive))->toBe(count($withOne))
        ->and(collect($withFive)->contains(fn (string $sql): bool => str_contains($sql, '"is_active"') && str_contains($sql, ' in (')))->toBeTrue();
});

it('la migration est idempotente et ne vise que le rôle admin', function (): void {
    $admin = Role::findOrCreate('admin', 'web');
    $utilisateur = Role::findOrCreate('utilisateur', 'web');
    $existingException = AccessException::query()->create([
        'user_id' => hoursUser()->id,
        'sector_id' => null,
        'ability' => 'heures.view',
        'effect' => 'deny',
    ]);

    $migration = require database_path('migrations/2026_09_28_120000_add_validators_identity_permission.php');
    $migration->up();
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::query()->where('name', IDENTITY_ABILITY)->count())->toBe(1)
        ->and($admin->fresh()->hasPermissionTo(IDENTITY_ABILITY))->toBeTrue()
        ->and($utilisateur->fresh()->hasPermissionTo(IDENTITY_ABILITY))->toBeFalse()
        ->and(AccessException::query()->count())->toBe(1)
        ->and($existingException->fresh())->not->toBeNull();
});
