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

    expect($leave->status)->toBe(ValidationStage::PENDING)
        ->and((int) $leave->validator_1_id)->toBe((int) $v1->id)
        ->and((int) $leave->validator_2_id)->toBe((int) $v2->id)
        ->and($leave->validator_1_decision)->toBeNull()
        ->and($leave->validator_2_decision)->toBeNull()
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

    // Un seul valideur : un seul accord suffira.
    expect((int) $leave->validator_1_id)->toBe((int) $legacyValidator->id)
        ->and($leave->validator_2_id)->toBeNull()
        ->and($leave->hasSecondValidationLevel())->toBeFalse()
        ->and($leave->validation_group_id)->toBeNull();
});

it('ignore un valideur désactivé et ne laisse pas la demande sans destinataire', function (): void {
    $inactive = twoStepUser(['is_active' => false]);
    $v2 = twoStepUser();
    $requester = twoStepUser();

    $active = twoStepUser();
    $group = groupWith($active, $v2, [$requester]);
    $group->update(['validator_1_id' => $inactive->id]);

    $leave = submitLeave($requester);

    expect((int) $leave->validator_1_id)->toBe((int) $v2->id)
        ->and($leave->validator_2_id)->toBeNull();
});

it('refuse qu\'une même personne occupe les deux rangs', function (): void {
    // L'administration l'interdit déjà à la création du groupe...
    $admin = twoStepUser();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $same = twoStepUser();

    $this->actingAs($admin)
        ->post(route('admin.leaves.validation-groups.store'), [
            'name' => 'Doublon',
            'validator_1_id' => $same->id,
            'validator_2_id' => $same->id,
            'member_user_ids' => [],
        ])
        ->assertSessionHasErrors('validator_2_id');

    // ... et le moteur ne l'accepterait pas davantage si la base contenait
    // une configuration héritée de ce type.
    $requester = twoStepUser();
    $group = groupWith($same, twoStepUser(), [$requester]);
    $group->update(['validator_2_id' => $same->id]);

    $leave = submitLeave($requester);

    expect((int) $leave->validator_1_id)->toBe((int) $same->id)
        ->and($leave->validator_2_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Congés — les deux valideurs agissent en parallèle
|--------------------------------------------------------------------------
*/

it('rend la demande accessible aux DEUX valideurs dès sa création', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    foreach ([$v1, $v2] as $validator) {
        $this->actingAs($validator)
            ->get(route('leaves.index'))
            ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
                ->has('leaveRequestsToValidate', 1)
                ->where('pendingValidationCount', 1)
                ->where('leaveRequestsToValidate.0.awaiting_my_decision', true)
            );
    }
});

it('laisse le Valideur 2 valider en premier', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v2)->post(route('leaves.approve', $leave->id))->assertSessionHasNoErrors();
    $leave->refresh();

    // Un seul accord ne suffit pas : la demande reste en attente.
    expect($leave->status)->toBe(ValidationStage::PENDING)
        ->and($leave->validator_2_decision)->toBe(ValidationStage::DECISION_APPROVED)
        ->and($leave->validator_1_decision)->toBeNull();

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('laisse le Valideur 1 valider en premier', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::PENDING)
        ->and($leave->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED)
        ->and($leave->validator_2_decision)->toBeNull();

    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('retire la demande de la file de celui qui a validé, pas de celle de l\'autre', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));

    $this->actingAs($v2)->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('pendingValidationCount', 0)
            ->where('leaveRequestsToValidate.0.awaiting_my_decision', false)
        );

    $this->actingAs($v1)->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('pendingValidationCount', 1)
            ->where('leaveRequestsToValidate.0.awaiting_my_decision', true)
        );
});

it('empêche un valideur de se prononcer deux fois', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    $this->actingAs($v1)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();

    // L'autre valideur, lui, reste libre d'agir.
    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING);
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));
    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('valide en un seul accord quand aucun second valideur n\'est désigné', function (): void {
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

    $otherV1 = twoStepUser();
    $otherV2 = twoStepUser();
    groupWith($otherV1, $otherV2, [twoStepUser()], 'Commerce');

    $leave = submitLeave($requester);

    $this->actingAs($otherV1)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();
    $this->actingAs($otherV2)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING);
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

    // Vrai pour les deux rangs : le Valideur 2 n'hérite d'aucune visibilité
    // supplémentaire sur les autres groupes.
    foreach ([$v1, $v2] as $validator) {
        $this->actingAs($validator)
            ->get(route('leaves.index'))
            ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
                ->has('leaveRequestsToValidate', 1)
                ->where('leaveRequestsToValidate.0.target_user_id', $mine->id)
            );
    }
});

/*
|--------------------------------------------------------------------------
| Congés — refus
|--------------------------------------------------------------------------
*/

it('refuse globalement la demande sur un refus du Valideur 1', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.refuse', $leave->id));
    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::REFUSED)
        ->and($leave->validator_1_decision)->toBe(ValidationStage::DECISION_REFUSED)
        ->and($leave->validator_2_decision)->toBeNull();

    // L'autre valideur ne peut plus rien rouvrir.
    $this->actingAs($v2)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();
    expect($leave->fresh()->status)->toBe(ValidationStage::REFUSED);
});

it('refuse globalement la demande sur un refus du Valideur 2', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::REFUSED);

    $this->actingAs($v1)
        ->postJson(route('leaves.approve', $leave->id))
        ->assertForbidden();
    expect($leave->fresh()->status)->toBe(ValidationStage::REFUSED);
});

it('refuse la demande même après l\'accord de l\'autre valideur', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));
    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::REFUSED)
        ->and($leave->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED)
        ->and($leave->validator_2_decision)->toBe(ValidationStage::DECISION_REFUSED);
});

/*
|--------------------------------------------------------------------------
| Congés — contre-proposition
|--------------------------------------------------------------------------
*/

it('ne clôt pas la demande quand le demandeur accepte la contre-proposition d\'un seul valideur', function (): void {
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

    // Le proposant a de fait donné son accord ; l'autre doit encore se
    // prononcer, et le fera sur la nouvelle période.
    expect($leave->status)->toBe(ValidationStage::PENDING)
        ->and($leave->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED)
        ->and($leave->validator_2_decision)->toBeNull()
        ->and($leave->start_at?->toDateString())->toBe('2026-10-12');

    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));
    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('clôt la demande quand la contre-proposition acceptée était le dernier accord manquant', function (): void {
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

it('laisse le Valideur 2 proposer une modification dès la création', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v2)->post(route('leaves.propose_modification', $leave->id), [
        'proposed_start_at' => '2026-10-12',
        'proposed_end_at' => '2026-10-13',
    ])->assertSessionHasNoErrors();

    expect($leave->fresh()->status)->toBe(LeaveRequest::STATUS_PENDING_USER_CONFIRMATION);
});

it('interdit de proposer une modification à un valideur qui a déjà tranché', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    $this->actingAs($v1)->postJson(route('leaves.propose_modification', $leave->id), [
        'proposed_start_at' => '2026-10-12',
        'proposed_end_at' => '2026-10-13',
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Congés — notifications
|--------------------------------------------------------------------------
*/

it('notifie les DEUX valideurs dès la création', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    Notification::assertSentTo($v1, LeaveRequestSubmittedNotification::class);
    Notification::assertSentTo($v2, LeaveRequestSubmittedNotification::class);
});

it('ne notifie le demandeur qu\'une fois les deux accords réunis', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));
    Notification::assertNotSentTo($requester, LeaveRequestApprovedNotification::class);

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    Notification::assertSentTo($requester, LeaveRequestApprovedNotification::class);
});

it('notifie le demandeur du refus, par quelque valideur qu\'il vienne', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));

    Notification::assertSentTo($requester, LeaveRequestRefusedNotification::class);
});

/*
|--------------------------------------------------------------------------
| Congés — anonymat, administrateurs, concurrence, historique
|--------------------------------------------------------------------------
*/

it('n\'expose aucun nom de valideur dans les données de la page Congés', function (): void {
    $v1 = twoStepUser(['first_name' => 'Alice', 'last_name' => 'Blanchet']);
    $v2 = twoStepUser(['first_name' => 'Floriane', 'last_name' => 'Blanchet']);
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    // Les libellés existent bien en base — c'est l'historique.
    expect($leave->validator_1_label)->toBe('Alice Blanchet')
        ->and($leave->validator_2_label)->toBe('Floriane Blanchet');

    // ... mais ne sortent jamais vers le navigateur.
    $response = $this->actingAs($requester)->get(route('leaves.index'));
    $payload = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);

    expect($payload)->not->toContain('Alice Blanchet')
        ->and($payload)->not->toContain('Floriane Blanchet')
        ->and($payload)->not->toContain('validator_1_label')
        ->and($payload)->not->toContain('validator_2_label');
});

it('expose un unique bloc d\'état de validation par demande', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    $this->actingAs($v1)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            // Une seule clé, deux entrées : un rang, un état, pas de doublon.
            ->has('leaveRequestsToValidate.0.validation_summary', 2)
            ->where('leaveRequestsToValidate.0.validation_summary.0.label', 'Validé')
            ->where('leaveRequestsToValidate.0.validation_summary.1.label', 'En attente')
            ->where('leaveRequestsToValidate.0.status_label', 'En attente de validation')
        );
});

it('laisse l\'administrateur trancher la demande d\'un seul geste', function (): void {
    $admin = twoStepAdmin();
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    // Un administrateur qui n'est valideur d'aucun rang décide pour les rangs
    // restants : c'est le pouvoir qu'il avait avant la double validation.
    $this->actingAs($admin)->post(route('leaves.approve', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('limite l\'administrateur à son propre rang lorsqu\'il est lui-même valideur', function (): void {
    $admin = twoStepAdmin();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($admin, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($admin)->post(route('leaves.approve', $leave->id));

    // Désigné au rang 1, il n'a validé que celui-là.
    expect($leave->fresh()->status)->toBe(ValidationStage::PENDING)
        ->and($leave->fresh()->validator_2_decision)->toBeNull();
});

it('ne décide qu\'une fois sur un double clic', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $service = app(TwoStepValidationService::class);

    // Deux instances du même enregistrement : ce que produisent deux onglets.
    $first = LeaveRequest::query()->findOrFail($leave->id);
    $second = LeaveRequest::query()->findOrFail($leave->id);

    expect($service->approve($first, $v1)->wasApplied)->toBeTrue()
        ->and($service->approve($second, $v1)->wasApplied)->toBeFalse()
        ->and($leave->fresh()->status)->toBe(ValidationStage::PENDING)
        ->and($leave->fresh()->validator_2_decision)->toBeNull();
});

it('ignore la décision d\'un valideur sur une demande déjà refusée par l\'autre', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $service = app(TwoStepValidationService::class);

    $stale = LeaveRequest::query()->findOrFail($leave->id);
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));

    // $stale a été chargé avant le refus : le verrou relit l'état réel.
    expect($service->approve($stale, $v1)->wasApplied)->toBeFalse()
        ->and($leave->fresh()->status)->toBe(ValidationStage::REFUSED);
});

it('garde l\'historique intact quand l\'administration change les valideurs du groupe', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    $group = groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    $newV1 = twoStepUser();
    $newV2 = twoStepUser();
    app(ValidationGroupService::class)->update($group, [
        'name' => 'Atelier',
        'validator_1_id' => $newV1->id,
        'validator_2_id' => $newV2->id,
        'member_user_ids' => [$requester->id],
    ]);

    $leave->refresh();

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

    $this->actingAs($v1)->post(route('leaves.approve', $firstLeave->id));
    expect($firstLeave->fresh()->status)->toBe(ValidationStage::PENDING);
});

it('conserve le libellé et l\'auteur de la décision après suppression du compte valideur', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $leave->refresh();

    $label = $leave->validator_1_label;
    $decidedAt = $leave->validator_1_decided_at;

    $v1->delete();
    $leave->refresh();

    expect($leave->validator_1_id)->toBeNull()
        ->and($leave->validator_1_label)->toBe($label)
        ->and($label)->not->toBeNull()
        ->and($leave->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED)
        ->and($leave->validator_1_decided_at?->toIso8601String())->toBe($decidedAt?->toIso8601String());
});

/*
|--------------------------------------------------------------------------
| Heures
|--------------------------------------------------------------------------
*/

it('soumet une journée d\'heures aux deux valideurs dès l\'enregistrement', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee);

    expect($sheet->status)->toBe(ValidationStage::PENDING)
        ->and((int) $sheet->validator_1_id)->toBe((int) $v1->id)
        ->and((int) $sheet->validator_2_id)->toBe((int) $v2->id)
        ->and($sheet->validator_1_decision)->toBeNull()
        ->and($sheet->validator_2_decision)->toBeNull();
});

it('laisse le Valideur 2 des heures agir en premier', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING);

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('laisse le Valideur 1 des heures agir en premier', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING);

    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('empêche un valideur des heures de se prononcer deux fois', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    $this->actingAs($v1)->postJson(route('hours.approve', $sheet->id))->assertForbidden();

    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING);
});

it('refuse globalement des heures dès le refus de l\'un des deux valideurs', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Horaires incohérents',
    ]);
    $sheet->refresh();

    expect($sheet->status)->toBe(ValidationStage::REFUSED)
        ->and($sheet->validator_2_decision)->toBe(ValidationStage::DECISION_REFUSED)
        ->and($sheet->refusal_reason)->toBe('Horaires incohérents');

    Notification::assertSentTo($employee, HourSheetDecisionNotification::class);

    $this->actingAs($v1)->postJson(route('hours.approve', $sheet->id))->assertForbidden();
    expect($sheet->fresh()->status)->toBe(ValidationStage::REFUSED);
});

it('ne notifie le salarié de ses heures qu\'après les deux accords', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    Notification::assertNotSentTo($employee, HourSheetDecisionNotification::class);

    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));
    Notification::assertSentTo($employee, HourSheetDecisionNotification::class);
});

it('rend les heures visibles aux deux valideurs dès la saisie', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    foreach ([$v1, $v2] as $validator) {
        $this->actingAs($validator)->get(route('hours.index'))
            ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
                ->where('pendingValidationCount', 1)
                ->has('hourSheetsToValidate', 1)
            );
    }

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    $this->actingAs($v1)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 0));
    $this->actingAs($v2)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 1));
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
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING);
});

it('n\'expose aucun nom de valideur dans les données de la page Heures', function (): void {
    $v1 = hoursUser();
    $v1->update(['first_name' => 'Alice', 'last_name' => 'Blanchet']);
    $v2 = twoStepUser(['first_name' => 'Floriane', 'last_name' => 'Blanchet']);
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    submitHourSheet($employee);

    foreach ([$employee, $v1] as $viewer) {
        $response = $this->actingAs($viewer)->get(route('hours.index'));
        $payload = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);

        expect($payload)->not->toContain('Floriane Blanchet')
            ->and($payload)->not->toContain('validator_1_label')
            ->and($payload)->not->toContain('validator_2_label');
    }
});

it('renvoie au circuit complet une journée déjà validée puis modifiée', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));
    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);

    // La validation portait sur le contenu précédent : rouvrir la journée
    // remet les deux accords à zéro.
    $sheet = submitHourSheet($employee);

    expect($sheet->status)->toBe(ValidationStage::PENDING)
        ->and($sheet->validator_1_decision)->toBeNull()
        ->and($sheet->validator_2_decision)->toBeNull();
});

it('laisse hors circuit les journées saisies avant la mise en place de la validation', function (): void {
    $v1 = hoursUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $legacy = HourSheet::query()->create([
        'user_id' => $employee->id,
        'work_date' => '2026-01-15',
        'total_minutes' => 480,
        'description' => 'Ancienne saisie',
    ]);

    expect($legacy->status)->toBeNull()
        ->and($legacy->isLegacyEntry())->toBeTrue();

    $this->actingAs($v1)->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('pendingValidationCount', 0));
});

it('ne décide qu\'une fois une journée d\'heures sur un double clic', function (): void {
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
        ->and($sheet->fresh()->status)->toBe(ValidationStage::PENDING);
});

it('refuse toute décision sur les heures à un utilisateur non authentifié', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $sheet = submitHourSheet($employee);

    $this->post(route('hours.approve', $sheet->id))->assertRedirect();
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING);
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
        'validator_1_decision' => ValidationStage::DECISION_APPROVED,
        'validator_1_decided_at' => now()->subMonth(),
    ]);

    expect(app(TwoStepValidationService::class)->canDecide($approved, $validator))->toBeFalse();

    $this->actingAs($validator)
        ->postJson(route('leaves.approve', $approved->id))
        ->assertForbidden();

    expect($approved->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('rend agissables les demandes héritées du circuit séquentiel', function (): void {
    // Ligne telle que la migration l'a laissée : accord du rang 1 déjà acquis,
    // statut global ramené sur `pending`, rang 2 encore ouvert.
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();

    $leave = LeaveRequest::query()->create([
        'requester_user_id' => $requester->id,
        'target_user_id' => $requester->id,
        'start_at' => '2026-11-02 00:00:00',
        'end_at' => '2026-11-03 18:00:00',
        'status' => ValidationStage::PENDING,
        'validator_user_id' => $v2->id,
        'validator_1_id' => $v1->id,
        'validator_1_label' => 'Ancien valideur 1',
        'validator_1_decision' => ValidationStage::DECISION_APPROVED,
        'validator_1_decided_at' => now()->subWeek(),
        'validator_2_id' => $v2->id,
        'validator_2_label' => 'Ancien valideur 2',
    ]);

    // Le rang 1 est clos, le rang 2 reste à trancher.
    $this->actingAs($v1)->postJson(route('leaves.approve', $leave->id))->assertForbidden();
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::APPROVED);
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

it('ne bloque pas la saisie d\'heures sur un congé partiellement validé', function (): void {
    $employee = hoursUser();

    // Un seul accord obtenu : le congé n'est pas acquis, il ne doit pas encore
    // empêcher la saisie des heures.
    LeaveRequest::query()->create([
        'requester_user_id' => $employee->id,
        'target_user_id' => $employee->id,
        'start_at' => '2026-10-05 00:00:00',
        'end_at' => '2026-10-05 18:00:00',
        'is_all_day' => true,
        'status' => ValidationStage::PENDING,
        'validator_1_decision' => ValidationStage::DECISION_APPROVED,
    ]);

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'description' => 'Travaux',
    ])->assertSessionHasNoErrors();
});
