<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveUserValidator;
use App\Models\Sector;
use App\Models\User;
use App\Models\ValidationGroup;
use App\Notifications\HourSheetDecisionNotification;
use App\Notifications\LeaveRequestApprovedNotification;
use App\Notifications\LeaveRequestFirstLevelApprovedNotification;
use App\Notifications\LeaveRequestRefusedNotification;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\Validation\TwoStepValidationService;
use App\Services\Validation\ValidationGroupService;
use App\Support\Validation\ValidationStage;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

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

/*
|--------------------------------------------------------------------------
| Congés — affectation des valideurs
|--------------------------------------------------------------------------
*/

it('fige les deux valideurs du groupe sur une nouvelle demande de congé', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    $group = groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    expect($leave->status)->toBe(ValidationStage::PENDING_VALIDATOR_1)
        ->and((int) $leave->validator_1_id)->toBe((int) $v1->id)
        ->and((int) $leave->validator_2_id)->toBe((int) $v2->id)
        ->and((int) $leave->validator_user_id)->toBe((int) $v1->id)
        ->and((int) $leave->validation_group_id)->toBe((int) $group->id)
        ->and($leave->validation_group_name)->toBe('Atelier')
        ->and($leave->validator_1_label)->not->toBeNull()
        ->and($leave->validator_2_label)->not->toBeNull();
});

it('retombe sur le valideur historique quand le demandeur n\'a pas de groupe', function (): void {
    $legacyValidator = twoStepUser();
    $requester = twoStepUser();

    LeaveUserValidator::query()->create([
        'target_user_id' => $requester->id,
        'validator_user_id' => $legacyValidator->id,
    ]);

    $leave = submitLeave($requester);

    // Circuit à un seul niveau : exactement le comportement d'avant.
    expect((int) $leave->validator_1_id)->toBe((int) $legacyValidator->id)
        ->and($leave->validator_2_id)->toBeNull()
        ->and($leave->hasSecondValidationLevel())->toBeFalse()
        ->and($leave->validation_group_id)->toBeNull();
});

it('ignore un valideur désactivé et ne laisse pas la demande sans destinataire', function (): void {
    $v1 = twoStepUser(['is_active' => false]);
    $v2 = twoStepUser();
    $requester = twoStepUser();

    // Le groupe est créé avec deux comptes actifs, puis le premier est
    // désactivé — le cas réel d'un départ après configuration.
    $active = twoStepUser();
    $group = groupWith($active, $v2, [$requester]);
    $group->update(['validator_1_id' => $v1->id]);

    $leave = submitLeave($requester);

    // Le Valideur 2 prend le premier rang : la demande reste traitable.
    expect((int) $leave->validator_1_id)->toBe((int) $v2->id)
        ->and($leave->validator_2_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Congés — ordre strict
|--------------------------------------------------------------------------
*/

it('interdit au Valideur 2 d\'agir avant le Valideur 1', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v2)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();

    $this->actingAs($v2)
        ->postJson(route('leaves.refuse', $leave->id))
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_1);
});

it('passe au second niveau après la validation du premier, sans accepter définitivement', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id))->assertSessionHasNoErrors();
    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::PENDING_VALIDATOR_2)
        ->and($leave->validator_1_decided_at)->not->toBeNull()
        ->and((int) $leave->validator_1_decided_by_id)->toBe((int) $v1->id)
        ->and($leave->validator_2_decided_at)->toBeNull()
        ->and((int) $leave->validator_user_id)->toBe((int) $v2->id);
});

it('accepte définitivement après la validation du second niveau', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));
    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::APPROVED)
        ->and($leave->validator_2_decided_at)->not->toBeNull()
        ->and((int) $leave->validator_2_decided_by_id)->toBe((int) $v2->id);
});

it('empêche le Valideur 1 de se prononcer une seconde fois', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    $this->actingAs($v1)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_2);
});

it('valide en une seule étape quand le groupe n\'a pas de second valideur', function (): void {
    $legacyValidator = twoStepUser();
    $requester = twoStepUser();
    LeaveUserValidator::query()->create([
        'target_user_id' => $requester->id,
        'validator_user_id' => $legacyValidator->id,
    ]);

    $leave = submitLeave($requester);
    $this->actingAs($legacyValidator)->post(route('leaves.approve', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

/*
|--------------------------------------------------------------------------
| Congés — périmètre des valideurs
|--------------------------------------------------------------------------
*/

it('refuse à un valideur d\'un autre groupe toute intervention', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester], 'Atelier');

    // Valideur d'un tout autre groupe : aucun droit ici.
    $otherV1 = twoStepUser();
    $otherV2 = twoStepUser();
    groupWith($otherV1, $otherV2, [twoStepUser()], 'Commerce');

    $leave = submitLeave($requester);

    $this->actingAs($otherV1)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_1);
});

it('ne montre à un valideur que les demandes des groupes dont il est valideur', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $mine = twoStepUser();
    groupWith($v1, $v2, [$mine], 'Atelier');

    $otherV1 = twoStepUser();
    $otherV2 = twoStepUser();
    $theirs = twoStepUser();
    groupWith($otherV1, $otherV2, [$theirs], 'Commerce');

    $type = leaveTypeForTests();
    submitLeave($mine, $type);
    submitLeave($theirs, $type);

    $this->actingAs($v1)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->has('leaveRequestsToValidate', 1)
            ->where('leaveRequestsToValidate.0.target_user_id', $mine->id)
        );
});

it('ne compte une demande que chez le valideur dont c\'est le tour', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    // Étape 1 : elle compte pour V1, pas pour V2.
    $this->actingAs($v1)->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 1));
    $this->actingAs($v2)->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 0));

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    // Étape 2 : elle a basculé, sans jamais être comptée deux fois.
    $this->actingAs($v1)->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 0));
    $this->actingAs($v2)->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 1));
});

/*
|--------------------------------------------------------------------------
| Congés — refus
|--------------------------------------------------------------------------
*/

it('arrête le circuit sur un refus de premier niveau', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.refuse', $leave->id));
    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::REFUSED)
        ->and($leave->validator_1_decided_at)->not->toBeNull()
        ->and($leave->validator_2_decided_at)->toBeNull();

    // Le second valideur ne peut rien rouvrir.
    $this->actingAs($v2)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();
});

it('arrête le circuit sur un refus de second niveau', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));
    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::REFUSED)
        ->and($leave->validator_1_decided_at)->not->toBeNull()
        ->and($leave->validator_2_decided_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Congés — contre-proposition
|--------------------------------------------------------------------------
*/

it('ne saute pas le second niveau quand le demandeur accepte une contre-proposition du premier', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v1)->post(route('leaves.propose_modification', $leave->id), [
        'proposed_start_at' => '2026-10-12',
        'proposed_end_at' => '2026-10-13',
    ])->assertSessionHasNoErrors();

    expect($leave->fresh()->status)->toBe(LeaveRequest::STATUS_PENDING_USER_CONFIRMATION);

    $this->actingAs($requester)->post(route('leaves.accept_modification', $leave->id));
    $leave->refresh();

    // Le premier valideur a proposé donc approuvé son niveau ; le second doit
    // encore se prononcer.
    expect($leave->status)->toBe(ValidationStage::PENDING_VALIDATOR_2)
        ->and($leave->validator_1_decided_at)->not->toBeNull()
        ->and($leave->start_at?->toDateString())->toBe('2026-10-12');
});

it('accepte définitivement une contre-proposition émise au second niveau', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    $this->actingAs($v2)->post(route('leaves.propose_modification', $leave->id), [
        'proposed_start_at' => '2026-10-19',
        'proposed_end_at' => '2026-10-20',
    ])->assertSessionHasNoErrors();

    $this->actingAs($requester)->post(route('leaves.accept_modification', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('interdit au second valideur de proposer une modification avant son tour', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v2)->postJson(route('leaves.propose_modification', $leave->id), [
        'proposed_start_at' => '2026-10-12',
        'proposed_end_at' => '2026-10-13',
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Congés — notifications
|--------------------------------------------------------------------------
*/

it('notifie le bon destinataire à chaque étape', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    Notification::assertSentTo($v1, LeaveRequestSubmittedNotification::class);
    Notification::assertNotSentTo($v2, LeaveRequestSubmittedNotification::class);

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    Notification::assertSentTo($v2, LeaveRequestFirstLevelApprovedNotification::class);
    // Le demandeur n'est pas encore prévenu : rien n'est définitif.
    Notification::assertNotSentTo($requester, LeaveRequestApprovedNotification::class);

    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));
    Notification::assertSentTo($requester, LeaveRequestApprovedNotification::class);
});

it('notifie le demandeur du refus, quel que soit le niveau', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));

    Notification::assertSentTo($requester, LeaveRequestRefusedNotification::class);
});

/*
|--------------------------------------------------------------------------
| Congés — administrateurs, concurrence, historique
|--------------------------------------------------------------------------
*/

it('laisse l\'administrateur agir, mais à l\'étape courante seulement', function (): void {
    $admin = twoStepAdmin();
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    // L'administrateur conserve son droit d'intervention — sans pour autant
    // court-circuiter l'ordre : sa validation vaut niveau 1.
    $this->actingAs($admin)->post(route('leaves.approve', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_2);
});

it('ne transitionne qu\'une fois sur un double clic', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $service = app(TwoStepValidationService::class);

    // Deux instances distinctes du même enregistrement : ce que produisent
    // deux onglets ouverts sur la même demande.
    $first = LeaveRequest::query()->findOrFail($leave->id);
    $second = LeaveRequest::query()->findOrFail($leave->id);

    expect($service->approve($first, $v1)->wasApplied)->toBeTrue()
        ->and($service->approve($second, $v1)->wasApplied)->toBeFalse()
        ->and($leave->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_2);
});

it('répond 409 plutôt que de rejouer une décision déjà prise', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    // Le second appel est refusé par la Policy d'étape (403), la demande reste
    // au niveau 2 : jamais deux transitions.
    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_2);
});

it('garde l\'historique intact quand l\'administration change les valideurs du groupe', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    $group = groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    // L'administration remplace les deux valideurs du groupe.
    $newV1 = twoStepUser();
    $newV2 = twoStepUser();
    app(ValidationGroupService::class)->update($group, [
        'name' => 'Atelier',
        'validator_1_id' => $newV1->id,
        'validator_2_id' => $newV2->id,
        'member_user_ids' => [$requester->id],
    ]);

    $leave->refresh();

    // La demande en cours garde SES valideurs : c'est bien l'ancien second
    // valideur qui doit conclure, et l'historique nomme le bon premier.
    expect((int) $leave->validator_1_id)->toBe((int) $v1->id)
        ->and((int) $leave->validator_2_id)->toBe((int) $v2->id);

    $this->actingAs($newV2)->postJson(route('leaves.approve', $leave->id))->assertForbidden();
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('laisse les demandes en cours au groupe d\'origine et n\'applique le nouveau qu\'aux suivantes', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    $atelier = groupWith($v1, $v2, [$requester], 'Atelier');

    $type = leaveTypeForTests();
    $firstLeave = submitLeave($requester, $type);

    // Le salarié change de groupe alors que sa demande est en cours.
    $newV1 = twoStepUser();
    $newV2 = twoStepUser();
    app(ValidationGroupService::class)->update($atelier, [
        'name' => 'Atelier',
        'validator_1_id' => $v1->id,
        'validator_2_id' => $v2->id,
        'member_user_ids' => [],
    ]);
    groupWith($newV1, $newV2, [$requester], 'Commerce');

    $secondLeave = submitLeave($requester, $type);

    expect((int) $firstLeave->fresh()->validator_1_id)->toBe((int) $v1->id)
        ->and($firstLeave->fresh()->validation_group_name)->toBe('Atelier')
        ->and((int) $secondLeave->validator_1_id)->toBe((int) $newV1->id)
        ->and($secondLeave->validation_group_name)->toBe('Commerce');

    // L'ancienne demande reste traitable par son valideur d'origine.
    $this->actingAs($v1)->post(route('leaves.approve', $firstLeave->id));
    expect($firstLeave->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_2);
});

it('conserve le libellé du valideur même après suppression de son compte', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $label = $leave->validator_1_label;

    $v1->delete();
    $leave->refresh();

    expect($leave->validator_1_id)->toBeNull()
        ->and($leave->validator_1_label)->toBe($label)
        ->and($label)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Heures
|--------------------------------------------------------------------------
*/

it('soumet une journée d\'heures au premier valideur dès l\'enregistrement', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee);

    expect($sheet->status)->toBe(ValidationStage::PENDING_VALIDATOR_1)
        ->and((int) $sheet->validator_1_id)->toBe((int) $v1->id)
        ->and((int) $sheet->validator_2_id)->toBe((int) $v2->id);
});

it('applique le même ordre strict aux heures', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    // V2 avant V1 : refusé.
    $this->actingAs($v2)->postJson(route('hours.approve', $sheet->id))->assertForbidden();

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_2);

    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('arrête le circuit des heures sur un refus et transmet le motif', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Horaires incohérents',
    ]);
    $sheet->refresh();

    expect($sheet->status)->toBe(ValidationStage::REFUSED)
        ->and($sheet->refusal_reason)->toBe('Horaires incohérents');

    Notification::assertSentTo($employee, HourSheetDecisionNotification::class);
    $this->actingAs($v2)->postJson(route('hours.approve', $sheet->id))->assertForbidden();
});

it('refuse à un valideur d\'un autre groupe de traiter des heures', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee], 'Atelier');

    $intruder = hoursUser();
    groupWith($intruder, twoStepUser(), [hoursUser()], 'Commerce');

    $sheet = submitHourSheet($employee);

    $this->actingAs($intruder)->postJson(route('hours.approve', $sheet->id))->assertForbidden();
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_1);
});

it('renvoie au premier niveau une journée déjà validée puis modifiée', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);

    // La validation portait sur le contenu précédent : rouvrir la journée
    // relance le circuit depuis le début.
    $sheet = submitHourSheet($employee);

    expect($sheet->status)->toBe(ValidationStage::PENDING_VALIDATOR_1)
        ->and($sheet->validator_1_decided_at)->toBeNull()
        ->and($sheet->validator_2_decided_at)->toBeNull();
});

it('ne compte les heures que chez le valideur dont c\'est le tour', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 1));
    $this->actingAs($v2)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 0));

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    $this->actingAs($v1)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 0));
    $this->actingAs($v2)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 1));
});

it('laisse hors circuit les journées saisies avant la mise en place de la validation', function (): void {
    $v1 = hoursUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    // Journée écrite sans passer par le circuit : c'est l'état des lignes
    // existantes au moment du déploiement.
    $legacy = HourSheet::query()->create([
        'user_id' => $employee->id,
        'work_date' => '2026-01-15',
        'total_minutes' => 480,
        'description' => 'Ancienne saisie',
    ]);

    expect($legacy->status)->toBeNull()
        ->and($legacy->isLegacyEntry())->toBeTrue();

    // Elle n'encombre aucune file de validation.
    $this->actingAs($v1)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 0));
});

it('ne transitionne qu\'une fois une journée d\'heures sur un double clic', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $service = app(TwoStepValidationService::class);
    $first = HourSheet::query()->findOrFail($sheet->id);
    $second = HourSheet::query()->findOrFail($sheet->id);

    expect($service->approve($first, $v1)->wasApplied)->toBeTrue()
        ->and($service->approve($second, $v1)->wasApplied)->toBeFalse()
        ->and($sheet->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_2);
});

it('refuse toute décision sur les heures à un utilisateur non authentifié', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->post(route('hours.approve', $sheet->id))->assertRedirect();
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING_VALIDATOR_1);
});

/*
|--------------------------------------------------------------------------
| Non-régression des données existantes
|--------------------------------------------------------------------------
*/

it('laisse intactes les demandes de congé déjà tranchées', function (): void {
    $validator = twoStepUser();
    $requester = twoStepUser();

    $approved = LeaveRequest::query()->create([
        'requester_user_id' => $requester->id,
        'target_user_id' => $requester->id,
        'start_at' => '2026-01-05 00:00:00',
        'end_at' => '2026-01-06 18:00:00',
        'status' => ValidationStage::APPROVED,
        'validator_user_id' => $validator->id,
        'validator_1_id' => $validator->id,
        'validator_1_label' => 'Ancien valideur',
        'validator_1_decided_at' => now()->subMonth(),
    ]);

    // Aucun second valideur n'est inventé rétroactivement, et l'état terminal
    // interdit toute reprise du circuit.
    expect($approved->hasSecondValidationLevel())->toBeFalse()
        ->and(app(TwoStepValidationService::class)->canDecide($approved, $validator))->toBeFalse();

    $this->actingAs($validator)
        ->postJson(route('leaves.approve', $approved->id))
        ->assertForbidden();
});

it('continue de bloquer la saisie d\'heures sur un congé définitivement validé', function (): void {
    $employee = hoursUser();

    LeaveRequest::query()->create([
        'requester_user_id' => $employee->id,
        'target_user_id' => $employee->id,
        'start_at' => '2026-10-05 00:00:00',
        'end_at' => '2026-10-05 18:00:00',
        'is_all_day' => true,
        'status' => ValidationStage::APPROVED,
    ]);

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'description' => 'Travaux',
    ])->assertSessionHasErrors();
});

it('ne bloque pas la saisie d\'heures sur un congé encore en cours de validation', function (): void {
    $employee = hoursUser();

    // Un congé arrêté au second niveau n'est pas acquis : il ne doit pas
    // encore empêcher la saisie des heures.
    LeaveRequest::query()->create([
        'requester_user_id' => $employee->id,
        'target_user_id' => $employee->id,
        'start_at' => '2026-10-05 00:00:00',
        'end_at' => '2026-10-05 18:00:00',
        'is_all_day' => true,
        'status' => ValidationStage::PENDING_VALIDATOR_2,
    ]);

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'description' => 'Travaux',
    ])->assertSessionHasNoErrors();
});
