<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Validation\ValidationStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

/*
 * « Début saisie heures » (users.hours_tracking_starts_at) encadre les
 * NOUVELLES saisies. L'avancer depuis l'administration ne doit ni supprimer,
 * ni modifier, ni masquer les journées déjà enregistrées avant cette date.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Mail::fake();
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Europe/Paris'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Change « Début saisie heures » comme le fait l'écran d'administration. */
function setTrackingStartFromAdmin(User $user, ?string $date): void
{
    $admin = hoursUser(['admin.users.manage']);

    test()->actingAs($admin)->put(route('admin.users.account.update', $user), [
        'first_name' => $user->first_name ?: 'Prénom',
        'last_name' => $user->last_name ?: 'Nom',
        'email' => $user->email,
        'hours_tracking_starts_at' => $date,
    ])->assertSessionHasNoErrors();

    // `actingAs` réutilise l'instance passée : elle doit porter la valeur
    // enregistrée, comme le ferait une nouvelle requête du salarié.
    $user->refresh();
    expect($user->hours_tracking_starts_at?->toDateString())->toBe($date);
}

/** Props Inertia de la page Heures du salarié. */
function trackingHoursProps(User $viewer): array
{
    return test()->actingAs($viewer)->get(route('hours.index'))->viewData('page')['props'];
}

/** État complet des journées d'un salarié, pour comparer avant / après. */
function hourSheetsSnapshot(User $user): array
{
    return HourSheet::query()
        ->where('user_id', $user->id)
        ->orderBy('id')
        ->get()
        ->map(fn (HourSheet $sheet): array => $sheet->getAttributes())
        ->all();
}

/**
 * Salarié saisissant depuis le 1er septembre, avec un historique varié :
 * journée antérieure au circuit, validée, refusée, en attente, et une journée
 * posée exactement sur la future date de début.
 *
 * @return array{0: User, 1: User, 2: User, 3: array<string, HourSheet>}
 */
function employeeWithHistory(): array
{
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $employee->forceFill(['hours_tracking_starts_at' => '2026-09-01'])->save();

    $legacy = HourSheet::query()->create([
        'user_id' => $employee->id,
        'work_date' => '2026-09-02',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '17:00',
        'total_minutes' => 420,
        'description' => 'Saisie historique',
        'has_lunch' => true,
        'status' => null,
    ]);

    $approved = submitHourSheet($employee, '2026-09-15');
    test()->actingAs($v1)->post(route('hours.approve', $approved->id));
    test()->actingAs($v2)->post(route('hours.approve', $approved->id));

    $refused = submitHourSheet($employee, '2026-09-16');
    test()->actingAs($v1)->post(route('hours.refuse', $refused->id), ['refusal_reason' => 'Horaires incohérents']);
    test()->actingAs($v2)->post(route('hours.refuse', $refused->id), ['refusal_reason' => 'Horaires incohérents']);

    $pending = submitHourSheet($employee, '2026-09-22');
    $onStartDate = submitHourSheet($employee, '2026-09-28');

    expect($approved->fresh()->status)->toBe(ValidationStage::APPROVED)
        ->and($refused->fresh()->status)->toBe(ValidationStage::REFUSED)
        ->and($pending->fresh()->status)->toBe(ValidationStage::PENDING);

    return [$employee, $v1, $v2, [
        'legacy' => $legacy->fresh(),
        'approved' => $approved->fresh(),
        'refused' => $refused->fresh(),
        'pending' => $pending->fresh(),
        'on_start_date' => $onStartDate->fresh(),
    ]];
}

function servedSheetIds(User $employee): array
{
    return collect(trackingHoursProps($employee)['hourSheets'])->pluck('id')->sort()->values()->all();
}

it('avancer la date de début ne supprime ni ne modifie aucune journée en base', function (): void {
    [$employee] = employeeWithHistory();
    $before = hourSheetsSnapshot($employee);

    setTrackingStartFromAdmin($employee, '2026-09-28');

    expect(hourSheetsSnapshot($employee))->toBe($before);
});

it('les journées antérieures à la nouvelle date restent dans l\'historique du salarié, statut inchangé', function (): void {
    [$employee, , , $sheets] = employeeWithHistory();

    setTrackingStartFromAdmin($employee, '2026-09-28');

    $props = trackingHoursProps($employee);
    $served = collect($props['hourSheets'])->keyBy('id');

    expect($props['minVisibleDate'])->toBe('2026-09-28')
        ->and($served->keys()->sort()->values()->all())
        ->toBe(collect($sheets)->pluck('id')->sort()->values()->all());

    // Statut vu par le propriétaire : terminées « Traitée », en attente
    // toujours en attente, antérieure au circuit toujours marquée comme telle.
    expect($served[$sheets['approved']->id]['status'])->toBe('processed')
        ->and($served[$sheets['refused']->id]['status'])->toBe('processed')
        ->and($served[$sheets['pending']->id]['status'])->toBe(ValidationStage::PENDING)
        ->and($served[$sheets['legacy']->id]['status'])->toBeNull();

    // Détail complet : horaires, total, description, cases.
    expect($served[$sheets['legacy']->id])->toMatchArray([
        'work_date' => '2026-09-02',
        'morning_start' => '08:00',
        'afternoon_end' => '17:00',
        'total_minutes' => 420,
        'description' => 'Saisie historique',
        'has_lunch' => true,
    ]);
});

it('une journée en attente antérieure à la nouvelle date reste dans la file de ses valideurs', function (): void {
    [$employee, $v1, $v2, $sheets] = employeeWithHistory();

    setTrackingStartFromAdmin($employee, '2026-10-05');

    foreach ([$v1, $v2] as $validator) {
        expect(collect(trackingHoursProps($validator)['hourSheetsToValidate'])->pluck('id'))
            ->toContain($sheets['pending']->id);
    }
});

it('avancer la date plusieurs fois ne fait disparaître aucune journée', function (): void {
    [$employee, , , $sheets] = employeeWithHistory();
    $expected = collect($sheets)->pluck('id')->sort()->values()->all();

    foreach (['2026-09-10', '2026-09-20', '2026-10-01', '2026-10-15'] as $date) {
        setTrackingStartFromAdmin($employee, $date);

        expect(servedSheetIds($employee))->toBe($expected);
    }
});

it('reculer la date garde l\'historique et rouvre la saisie sur la période', function (): void {
    [$employee, , , $sheets] = employeeWithHistory();
    setTrackingStartFromAdmin($employee, '2026-09-28');

    setTrackingStartFromAdmin($employee, '2026-08-01');

    $created = submitHourSheet($employee, '2026-08-17');

    expect(servedSheetIds($employee))
        ->toBe(collect($sheets)->pluck('id')->push($created->id)->sort()->values()->all());
});

it('sans changement de date, la page sert exactement les mêmes journées', function (): void {
    [$employee, , , $sheets] = employeeWithHistory();
    $before = trackingHoursProps($employee);

    setTrackingStartFromAdmin($employee, '2026-09-01');

    $after = trackingHoursProps($employee);
    expect($after['hourSheets'])->toBe($before['hourSheets'])
        ->and($after['minVisibleDate'])->toBe('2026-09-01')
        ->and(count($after['hourSheets']))->toBe(count($sheets));
});

it('modifier la date d\'un salarié sans historique ne provoque aucune erreur', function (): void {
    $employee = hoursUser();

    setTrackingStartFromAdmin($employee, '2026-10-01');

    $props = trackingHoursProps($employee);
    expect($props['hourSheets'])->toBe([])
        ->and($props['minVisibleDate'])->toBe('2026-10-01');
});

it('créer une journée avant la date de début reste interdit, le jour même est accepté', function (): void {
    [$employee] = employeeWithHistory();
    setTrackingStartFromAdmin($employee, '2026-10-05');

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-02',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'description' => 'Trop tôt',
    ])->assertSessionHasErrors('work_date');

    expect(HourSheet::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-10-02')->exists())
        ->toBeFalse();

    // Le jour exact de la date de début : borne incluse.
    expect(submitHourSheet($employee, '2026-10-05')->work_date->toDateString())->toBe('2026-10-05');
});

it('une journée existante antérieure à la date de début est consultable mais plus modifiable', function (): void {
    [$employee, , , $sheets] = employeeWithHistory();
    setTrackingStartFromAdmin($employee, '2026-09-28');
    $before = $sheets['pending']->fresh()->getAttributes();

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-09-22',
        'morning_start' => '06:00',
        'morning_end' => '12:00',
        'description' => 'Réécriture',
    ])->assertSessionHasErrors('work_date');

    expect($sheets['pending']->fresh()->getAttributes())->toBe($before);
});

it('la journée posée exactement sur la date de début reste servie et modifiable', function (): void {
    [$employee, , , $sheets] = employeeWithHistory();
    setTrackingStartFromAdmin($employee, '2026-09-28');

    expect(servedSheetIds($employee))->toContain($sheets['on_start_date']->id);

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-09-28',
        'morning_start' => '07:00',
        'morning_end' => '12:00',
        'afternoon_start' => '13:00',
        'afternoon_end' => '17:00',
        'description' => 'Corrigée',
    ])->assertSessionHasNoErrors();

    expect($sheets['on_start_date']->fresh()->description)->toBe('Corrigée');
});

it('juste après minuit (heure de Paris), le jour de la date de début est bien le jour même', function (): void {
    // 22:30 UTC la veille = 00:30 à Paris le 28 : aucune bascule d'un jour.
    Carbon::setTestNow(Carbon::parse('2026-09-27 22:30:00', 'UTC'));
    $employee = hoursUser();
    setTrackingStartFromAdmin($employee, '2026-09-28');

    $sheet = submitHourSheet($employee, '2026-09-28');

    expect(servedSheetIds($employee))->toBe([$sheet->id]);
});

it('les congés tombant sur une journée historique restent servis, pas ceux des jours sans saisie', function (): void {
    [$employee, , , $sheets] = employeeWithHistory();

    // Après-midi de congé sur la journée validée du 15 septembre, et congé
    // un jour sans aucune saisie (le 8 septembre).
    foreach ([
        ['2026-09-15 14:00:00', '2026-09-15 18:00:00', false],
        ['2026-09-08 00:00:00', '2026-09-08 18:00:00', true],
    ] as [$startAt, $endAt, $allDay]) {
        LeaveRequest::query()->create([
            'requester_user_id' => $employee->id,
            'target_user_id' => $employee->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_all_day' => $allDay,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);
    }

    $before = trackingHoursProps($employee)['approvedLeaveDays'];
    expect($before)->toHaveKeys(['2026-09-08', '2026-09-15']);

    setTrackingStartFromAdmin($employee, '2026-09-28');

    $leaves = trackingHoursProps($employee)['approvedLeaveDays'];
    expect($leaves)->toHaveKey('2026-09-15')
        ->and($leaves['2026-09-15']['afternoon'])->toBeTrue()
        ->and($leaves)->not->toHaveKey('2026-09-08');
});
