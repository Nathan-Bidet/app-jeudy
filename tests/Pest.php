<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Fabriques partagées — validation (Congés et Heures)
|--------------------------------------------------------------------------
|
| Définies ici plutôt que dans un fichier de test : Pest charge tests/Pest.php
| avant les tests, ce qui garantit leur disponibilité quel que soit l'ordre
| d'exécution. Déclarées dans un fichier de test, elles n'auraient existé que
| si ce fichier était chargé en premier.
*/

use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Sector;
use App\Models\User;
use App\Models\ValidationGroup;
use App\Services\Validation\ValidationGroupService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function twoStepUser(array $overrides = []): User
{
    return User::factory()->create(array_merge(['is_active' => true], $overrides));
}

function twoStepAdmin(): User
{
    $user = twoStepUser();
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

/**
 * Un salarié pouvant saisir ses heures : le module est fermé par le middleware
 * sector.access, il faut donc la permission pour atteindre les routes.
 */
function hoursUser(array $abilities = ['heures.view', 'heures.create']): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('hours-test-'.fake()->unique()->word(), 'web');

    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $user = twoStepUser(['sector_id' => $sector->id]);
    $user->assignRole($role);

    return $user;
}

/**
 * Groupe complet : deux valideurs distincts et des membres.
 *
 * @param  array<int, User>  $members
 */
function groupWith(User $validator1, User $validator2, array $members = [], string $name = 'Atelier'): ValidationGroup
{
    return app(ValidationGroupService::class)->create([
        'name' => $name,
        'validator_1_id' => $validator1->id,
        'validator_2_id' => $validator2->id,
        'member_user_ids' => array_map(fn (User $user): int => (int) $user->id, $members),
    ]);
}

function leaveTypeForTests(): LeaveType
{
    return LeaveType::query()->create([
        'name' => 'Congé payé',
        'max_days' => 30,
        'sort_order' => 0,
        'is_active' => true,
    ]);
}

/**
 * Dépose une demande de congé par la route réelle, pour que l'affectation des
 * valideurs passe exactement par le chemin de production.
 */
function submitLeave(User $requester, ?LeaveType $type = null): LeaveRequest
{
    $type ??= leaveTypeForTests();

    test()->actingAs($requester)->post(route('leaves.store'), [
        'target_user_id' => $requester->id,
        'leave_type_id' => $type->id,
        'start_at' => '2026-10-05',
        'end_at' => '2026-10-06',
        'start_portion' => 'full_day',
        'end_portion' => 'full_day',
        'is_all_day' => true,
    ])->assertSessionHasNoErrors();

    return LeaveRequest::query()->latest('id')->firstOrFail();
}

function submitHourSheet(User $user, string $date = '2026-10-05'): HourSheet
{
    test()->actingAs($user)->post(route('hours.store'), [
        'work_date' => $date,
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '18:00',
        'description' => 'Travaux réalisés',
    ])->assertSessionHasNoErrors();

    return HourSheet::query()->where('user_id', $user->id)->whereDate('work_date', $date)->firstOrFail();
}

/**
 * Dépose une demande de congé sur une période donnée.
 *
 * Utile pour éprouver la date d'effet : c'est la date de DÉBUT qui décide du
 * régime de validation appliqué.
 */
function submitLeaveOn(App\Models\User $requester, string $startAt, string $endAt, ?App\Models\LeaveType $type = null): App\Models\LeaveRequest
{
    $type ??= leaveTypeForTests();

    test()->actingAs($requester)->post(route('leaves.store'), [
        'target_user_id' => $requester->id,
        'leave_type_id' => $type->id,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'start_portion' => 'full_day',
        'end_portion' => 'full_day',
        'is_all_day' => true,
    ])->assertSessionHasNoErrors();

    return App\Models\LeaveRequest::query()->latest('id')->firstOrFail();
}
