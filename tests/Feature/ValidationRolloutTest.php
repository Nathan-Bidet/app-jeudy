<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\HoursAttachedToValidationNotification;
use App\Services\Settings\AppSettings;
use App\Services\Validation\ValidationRolloutService;
use App\Support\Validation\ValidationStage;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // Le service met les réglages en cache pour la durée d'une requête : deux
    // tests successifs ne doivent pas hériter de la valeur du précédent.
    app(AppSettings::class)->forget();
});

/** Fixe la date d'effet sans passer par l'écran d'administration. */
function setEffectiveDate(?string $date): void
{
    app(ValidationRolloutService::class)->setEffectiveDate($date);
    app(AppSettings::class)->forget();
}

/** Journée d'heures écrite directement, comme avant la mise en service. */
function legacyHourSheet(User $user, string $workDate, array $overrides = []): HourSheet
{
    return HourSheet::query()->create(array_merge([
        'user_id' => $user->id,
        'work_date' => $workDate,
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '18:00',
        'total_minutes' => 480,
        'description' => 'Saisie antérieure',
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
*/

it('expose la date d\'effet sur la page d\'administration', function (): void {
    setEffectiveDate('2026-09-01');

    $this->actingAs(twoStepAdmin())
        ->get(route('admin.leaves.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Leaves/Index')
            ->where('validationEffectiveDate', '2026-09-01')
        );
});

it('laisse un administrateur enregistrer la date d\'effet', function (): void {
    $this->actingAs(twoStepAdmin())
        ->put(route('admin.leaves.validation-effective-date.update'), [
            'validation_effective_date' => '2026-09-01',
        ])
        ->assertSessionHas('success');

    app(AppSettings::class)->forget();

    expect(app(ValidationRolloutService::class)->effectiveDate()?->toDateString())->toBe('2026-09-01');
});

it('accepte de vider la date d\'effet', function (): void {
    setEffectiveDate('2026-09-01');

    $this->actingAs(twoStepAdmin())
        ->put(route('admin.leaves.validation-effective-date.update'), ['validation_effective_date' => ''])
        ->assertSessionHasNoErrors();

    app(AppSettings::class)->forget();

    expect(app(ValidationRolloutService::class)->effectiveDate())->toBeNull();
});

it('refuse une date invalide', function (): void {
    $this->actingAs(twoStepAdmin())
        ->put(route('admin.leaves.validation-effective-date.update'), [
            'validation_effective_date' => '01/09/2026',
        ])
        ->assertSessionHasErrors('validation_effective_date');
});

it('refuse la modification de la date à un non-administrateur', function (): void {
    setEffectiveDate('2026-09-01');

    $this->actingAs(twoStepUser())
        ->putJson(route('admin.leaves.validation-effective-date.update'), [
            'validation_effective_date' => '2026-08-01',
        ])
        ->assertForbidden();

    app(AppSettings::class)->forget();

    expect(app(ValidationRolloutService::class)->effectiveDate()?->toDateString())->toBe('2026-09-01');
});

/*
|--------------------------------------------------------------------------
| Périmètre — Heures
|--------------------------------------------------------------------------
*/

it('laisse hors circuit une journée antérieure à la date d\'effet', function (): void {
    setEffectiveDate('2026-09-01');

    // Les valideurs ont accès au module : c'est ce qui leur permet de consulter
    // leur file sur la page Heures.
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee, '2026-08-31');

    expect($sheet->status)->toBeNull()
        ->and($sheet->validator_1_id)->toBeNull();

    $this->actingAs($v1)->get(route('hours.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pendingValidationCount', 0));
});

it('soumet une journée à partir de la date d\'effet', function (): void {
    setEffectiveDate('2026-09-01');

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    foreach (['2026-09-01', '2026-09-02'] as $date) {
        $sheet = submitHourSheet($employee, $date);

        expect($sheet->status)->toBe(ValidationStage::PENDING)
            ->and((int) $sheet->validator_1_id)->toBe((int) $v1->id)
            ->and((int) $sheet->validator_2_id)->toBe((int) $v2->id);
    }
});

it('retient la date de la journée, pas celle de la saisie', function (): void {
    // La journée est saisie aujourd'hui, mais porte sur une date antérieure à
    // la mise en service : elle reste hors périmètre.
    setEffectiveDate('2026-09-01');

    $employee = hoursUser();
    groupWith(twoStepUser(), twoStepUser(), [$employee]);

    expect(submitHourSheet($employee, '2026-08-20')->status)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Rattrapage des heures déjà saisies
|--------------------------------------------------------------------------
*/

it('rattache les journées déjà saisies qui entrent dans le périmètre', function (): void {
    Notification::fake();

    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    // Journées écrites avant la mise en service du circuit.
    legacyHourSheet($employee, '2026-08-31');
    legacyHourSheet($employee, '2026-09-01');
    legacyHourSheet($employee, '2026-09-02');
    legacyHourSheet($employee, '2026-09-03');

    $this->actingAs(twoStepAdmin())
        ->put(route('admin.leaves.validation-effective-date.update'), [
            'validation_effective_date' => '2026-09-01',
        ])
        ->assertSessionHas('success');

    // Les trois journées de septembre entrent dans le circuit, celle d'août non.
    expect(HourSheet::query()->whereDate('work_date', '2026-08-31')->value('status'))->toBeNull();

    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        $sheet = HourSheet::query()->whereDate('work_date', $date)->firstOrFail();

        expect($sheet->status)->toBe(ValidationStage::PENDING)
            ->and((int) $sheet->validator_1_id)->toBe((int) $v1->id)
            ->and((int) $sheet->validator_2_id)->toBe((int) $v2->id);
    }

    // Sans que le salarié ait eu à toucher à ses heures, elles apparaissent
    // chez les deux valideurs.
    foreach ([$v1, $v2] as $validator) {
        $this->actingAs($validator)->get(route('hours.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pendingValidationCount', 3));
    }
});

it('ne rattache pas deux fois la même journée', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    legacyHourSheet($employee, '2026-09-01');

    setEffectiveDate('2026-09-01');
    $rollout = app(ValidationRolloutService::class);

    expect($rollout->backfillHourSheets(false)['attached'])->toBe(1);

    // Le second passage ne trouve plus rien : la journée est déjà dans le
    // circuit, et une seule ligne existe pour ce couple utilisateur/date.
    expect($rollout->backfillHourSheets(false)['attached'])->toBe(0)
        ->and(HourSheet::query()->where('user_id', $employee->id)->count())->toBe(1);
});

it('ne touche pas aux journées déjà tranchées', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $decidedAt = now()->subMonth();
    $approved = legacyHourSheet($employee, '2026-09-01', [
        'status' => ValidationStage::APPROVED,
        'validator_1_id' => $v1->id,
        'validator_1_label' => 'Ancien valideur',
        'validator_1_decision' => ValidationStage::DECISION_APPROVED,
        'validator_1_decided_at' => $decidedAt,
    ]);

    setEffectiveDate('2026-09-01');
    app(ValidationRolloutService::class)->backfillHourSheets(false);

    $approved->refresh();

    expect($approved->status)->toBe(ValidationStage::APPROVED)
        ->and($approved->validator_1_label)->toBe('Ancien valideur')
        ->and($approved->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED)
        ->and($approved->validator_1_decided_at?->toDateTimeString())->toBe($decidedAt->toDateTimeString());
});

it('notifie chaque valideur une seule fois, avec le nombre de journées', function (): void {
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        legacyHourSheet($employee, $date);
    }

    setEffectiveDate('2026-09-01');
    app(ValidationRolloutService::class)->backfillHourSheets();

    // Une notification groupée par valideur, et non une par journée.
    foreach ([$v1, $v2] as $validator) {
        Notification::assertSentToTimes($validator, HoursAttachedToValidationNotification::class, 1);
    }
});

/*
|--------------------------------------------------------------------------
| Configuration incomplète
|--------------------------------------------------------------------------
*/

it('signale les journées d\'un salarié sans groupe au lieu de les perdre', function (): void {
    $orphan = hoursUser();
    legacyHourSheet($orphan, '2026-09-01');
    legacyHourSheet($orphan, '2026-09-02');

    setEffectiveDate('2026-09-01');
    $result = app(ValidationRolloutService::class)->backfillHourSheets(false);

    expect($result['attached'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and($result['anomalies'][0]['user_id'])->toBe($orphan->id)
        ->and($result['anomalies'][0]['reason'])->toContain('aucun groupe');

    // Les journées ne sont pas perdues : elles restent hors circuit et seront
    // reprises telles quelles une fois le groupe créé.
    expect(HourSheet::query()->where('user_id', $orphan->id)->whereNull('status')->count())->toBe(2);

    // L'anomalie remonte à l'administration.
    $this->actingAs(twoStepAdmin())
        ->get(route('admin.leaves.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('validationRolloutAnomalies', 1));
});

it('rattrape les journées une fois le groupe corrigé', function (): void {
    $employee = hoursUser();
    legacyHourSheet($employee, '2026-09-01');

    setEffectiveDate('2026-09-01');
    $rollout = app(ValidationRolloutService::class);

    expect($rollout->backfillHourSheets(false)['skipped'])->toBe(1);

    groupWith(twoStepUser(), twoStepUser(), [$employee]);

    expect($rollout->backfillHourSheets(false)['attached'])->toBe(1)
        ->and(HourSheet::query()->where('user_id', $employee->id)->value('status'))->toBe(ValidationStage::PENDING);
});

it('signale un groupe sans aucun valideur actif', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    $group = groupWith($v1, $v2, [$employee]);

    $v1->update(['is_active' => false]);
    $v2->update(['is_active' => false]);

    legacyHourSheet($employee, '2026-09-01');
    setEffectiveDate('2026-09-01');

    $result = app(ValidationRolloutService::class)->backfillHourSheets(false);

    expect($result['attached'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['anomalies'][0]['reason'])->toContain('aucun valideur actif');
});

/*
|--------------------------------------------------------------------------
| Changement de la date d'effet
|--------------------------------------------------------------------------
*/

it('reprend les journées nouvellement concernées quand la date recule', function (): void {
    $employee = hoursUser();
    groupWith(twoStepUser(), twoStepUser(), [$employee]);

    legacyHourSheet($employee, '2026-08-26');
    legacyHourSheet($employee, '2026-09-01');

    setEffectiveDate('2026-09-01');
    app(ValidationRolloutService::class)->backfillHourSheets(false);

    expect(HourSheet::query()->whereDate('work_date', '2026-08-26')->value('status'))->toBeNull();

    // La date recule au 25/08 : la journée du 26 entre alors dans le périmètre.
    setEffectiveDate('2026-08-25');
    app(ValidationRolloutService::class)->backfillHourSheets(false);

    expect(HourSheet::query()->whereDate('work_date', '2026-08-26')->value('status'))->toBe(ValidationStage::PENDING);
});

it('ne détruit aucune validation quand la date avance', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    legacyHourSheet($employee, '2026-09-01');
    setEffectiveDate('2026-09-01');
    app(ValidationRolloutService::class)->backfillHourSheets(false);

    $sheet = HourSheet::query()->whereDate('work_date', '2026-09-01')->firstOrFail();
    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    expect($sheet->fresh()->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED);

    // La date avance au-delà de cette journée : la validation en cours reste
    // intacte et le second valideur peut toujours conclure.
    setEffectiveDate('2026-10-01');
    app(ValidationRolloutService::class)->backfillHourSheets(false);

    $sheet->refresh();

    expect($sheet->status)->toBe(ValidationStage::PENDING)
        ->and($sheet->validator_1_decision)->toBe(ValidationStage::DECISION_APPROVED);

    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));

    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
});

/*
|--------------------------------------------------------------------------
| Périmètre — Congés
|--------------------------------------------------------------------------
*/

it('fait suivre l\'ancien circuit à un congé commençant avant la date d\'effet', function (): void {
    setEffectiveDate('2026-09-01');

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);
    $admin = twoStepAdmin();

    $leave = submitLeaveOn($requester, '2026-08-28', '2026-08-31');

    // Aucun groupe, donc aucun second valideur : un seul accord suffit, comme
    // avant la mise en service.
    expect($leave->validation_group_id)->toBeNull()
        ->and($leave->validator_2_id)->toBeNull()
        ->and((int) $leave->validator_1_id)->toBe((int) $admin->id);
});

it('fait suivre le nouveau circuit à un congé commençant à la date d\'effet', function (): void {
    setEffectiveDate('2026-09-01');

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeaveOn($requester, '2026-09-01', '2026-09-05');

    expect((int) $leave->validator_1_id)->toBe((int) $v1->id)
        ->and((int) $leave->validator_2_id)->toBe((int) $v2->id);
});

it('tranche un congé à cheval sur sa date de début', function (): void {
    setEffectiveDate('2026-09-01');

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);
    twoStepAdmin();

    // Du 30/08 au 03/09 : la date de début décide, donc ancien circuit.
    $leave = submitLeaveOn($requester, '2026-08-30', '2026-09-03');

    expect($leave->validator_2_id)->toBeNull();
});

it('n\'altère aucun congé historique lorsque la date change', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = LeaveRequest::query()->create([
        'requester_user_id' => $requester->id,
        'target_user_id' => $requester->id,
        'start_at' => '2026-09-02 00:00:00',
        'end_at' => '2026-09-03 18:00:00',
        'status' => ValidationStage::APPROVED,
        'validator_1_id' => $v1->id,
        'validator_1_label' => 'Ancien valideur',
        'validator_1_decision' => ValidationStage::DECISION_APPROVED,
        'validator_1_decided_at' => now()->subMonth(),
    ]);

    setEffectiveDate('2026-08-01');
    app(ValidationRolloutService::class)->backfillHourSheets(false);

    $leave->refresh();

    expect($leave->status)->toBe(ValidationStage::APPROVED)
        ->and($leave->validator_1_label)->toBe('Ancien valideur');
});

/*
|--------------------------------------------------------------------------
| Absence de réglage
|--------------------------------------------------------------------------
*/

it('applique le nouveau système à tout lorsqu\'aucune date n\'est configurée', function (): void {
    setEffectiveDate(null);

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    // La journée du 31 août — exclue dès qu'une date d'effet au 1er septembre
    // est posée — passe ici par le circuit : sans réglage, tout y passe. La
    // date reste postérieure au début de saisie des heures du module
    // (config hours.min_visible_date), qui est un contrôle distinct.
    expect(submitHourSheet($employee, '2026-08-31')->status)->toBe(ValidationStage::PENDING);
});
