<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Jobs\SendWebPushNotificationJob;
use App\Models\HourSheet;
use App\Models\User;
use App\Notifications\LeaveRequestApprovedNotification;
use App\Notifications\LeaveRequestRefusedNotification;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Support\Validation\ValidationStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Refus d'heures : plus aucune notification au salarié, par aucun canal.
 * Refus de congé : notifications inchangées.
 *
 * Pas de Notification::fake() ici : on observe ce qui est réellement écrit en
 * base (cloche), envoyé par e-mail ou mis en file (push), comme en production.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Mail::fake();
    Queue::fake();
});

/** Circuit Heures à deux valideurs. */
function refusalCircuit(): array
{
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    return [$v1, $v2, $employee];
}

/** Destinataire d'un push web mis en file (propriété privée du Job). */
function pushRecipientId(SendWebPushNotificationJob $job): int
{
    return (int) (new ReflectionProperty($job, 'userId'))->getValue($job);
}

/** Aucune trace de notification pour ce salarié, sur aucun canal. */
function assertEmployeeNotNotified(User $employee): void
{
    expect(DB::table('notifications')->where('notifiable_id', $employee->id)->count())->toBe(0);

    Queue::assertNotPushed(
        SendWebPushNotificationJob::class,
        fn (SendWebPushNotificationJob $job): bool => pushRecipientId($job) === (int) $employee->id,
    );
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
}

it('refus par le Valideur 1 puis le Valideur 2 : refus enregistré, aucune notification', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)
        ->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Horaires incohérents'])
        ->assertSessionHas('success', 'Votre décision est enregistrée. La journée reste en attente de la seconde validation.');
    $this->actingAs($v2)
        ->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Pause non déclarée'])
        ->assertSessionHas('success', 'Journée définitivement refusée.');

    $sheet->refresh();
    expect($sheet->status)->toBe(ValidationStage::REFUSED)
        ->and($sheet->validator_1_decision)->toBe(ValidationStage::DECISION_REFUSED)
        ->and($sheet->validator_2_decision)->toBe(ValidationStage::DECISION_REFUSED)
        ->and((int) $sheet->validator_1_decided_by_id)->toBe((int) $v1->id)
        ->and($sheet->validator_2_decided_at)->not->toBeNull()
        ->and($sheet->refusal_reason)->toBe('Pause non déclarée');

    // Journal d'audit inchangé.
    expect(DB::table('audit_logs')->where('action', 'refuse_hour_sheet_partial')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'refuse_hour_sheet')->count())->toBe(1);

    assertEmployeeNotNotified($employee);
});

it('refus par le Valideur 2 en premier : motif nettoyé, aucune notification', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $sheet = submitHourSheet($employee);

    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => "  Pause absente\n"]);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => "\tHoraires à revoir  "]);

    $sheet->refresh();
    expect($sheet->status)->toBe(ValidationStage::REFUSED)
        ->and($sheet->refusal_reason)->toBe('Horaires à revoir');

    assertEmployeeNotNotified($employee);
});

it('validation par l\'un, refus par l\'autre : issue réelle, aucune notification', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $refused = submitHourSheet($employee, '2026-10-05');
    $approved = submitHourSheet($employee, '2026-10-06');

    // V1 valide, V2 refuse : le Valideur 2 tranche → refusé.
    $this->actingAs($v1)->post(route('hours.approve', $refused->id));
    $this->actingAs($v2)->post(route('hours.refuse', $refused->id), ['refusal_reason' => 'Non']);

    // V1 refuse, V2 valide : le Valideur 2 tranche → validé.
    $this->actingAs($v1)->post(route('hours.refuse', $approved->id), ['refusal_reason' => 'À revoir']);
    $this->actingAs($v2)->post(route('hours.approve', $approved->id));

    expect($refused->fresh()->status)->toBe(ValidationStage::REFUSED)
        ->and($approved->fresh()->status)->toBe(ValidationStage::APPROVED);

    assertEmployeeNotNotified($employee);
});

it('validation par l\'un, l\'autre en attente : statuts individuels exposés tels qu\'enregistrés', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    $this->actingAs($v2)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hourSheetsToValidate.0.status', ValidationStage::PENDING)
            ->where('hourSheetsToValidate.0.validation_summary.0.decision', ValidationStage::DECISION_APPROVED)
            ->where('hourSheetsToValidate.0.validation_summary.0.label', 'Validé')
            ->where('hourSheetsToValidate.0.validation_summary.1.decision', null)
            ->where('hourSheetsToValidate.0.validation_summary.1.label', 'En attente'));

    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non']);

    // Vue administrateur : le détail reflète les deux décisions réelles.
    $sheet2 = submitHourSheet($employee, '2026-10-07');
    $this->actingAs($v2)->post(route('hours.refuse', $sheet2->id), ['refusal_reason' => 'Non']);
    $this->actingAs(twoStepAdmin())
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hourSheetsToValidate.0.id', $sheet2->id)
            ->where('hourSheetsToValidate.0.validation_summary.0.decision', null)
            ->where('hourSheetsToValidate.0.validation_summary.1.decision', ValidationStage::DECISION_REFUSED)
            ->where('hourSheetsToValidate.0.validation_summary.1.label', 'Refusé'));

    assertEmployeeNotNotified($employee);
});

it('circuit à un seul valideur : le refus clôt la journée sans notification', function (): void {
    $v1 = hoursUser();
    $employee = hoursUser();
    $group = groupWith($v1, hoursUser(), [$employee]);
    $group->update(['validator_2_id' => null]);

    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non']);

    expect($sheet->fresh()->status)->toBe(ValidationStage::REFUSED);
    assertEmployeeNotNotified($employee);
});

it('requête répétée : décision unique, statut cohérent, aucune notification', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Premier'])->assertSessionHas('success');
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Doublon'])->assertDenied();
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Second']);
    $this->actingAs($v2)
        ->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Doublon'])
        ->assertForbidden();

    $sheet->refresh();
    expect($sheet->status)->toBe(ValidationStage::REFUSED)
        ->and($sheet->refusal_reason)->toBe('Second')
        ->and(DB::table('audit_logs')->whereIn('action', ['refuse_hour_sheet', 'refuse_hour_sheet_partial'])->count())->toBe(2);

    assertEmployeeNotNotified($employee);
});

it('salarié désactivé : refus enregistré, aucune notification', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $sheet = submitHourSheet($employee);
    $employee->update(['is_active' => false]);

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non']);
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non']);

    expect($sheet->fresh()->status)->toBe(ValidationStage::REFUSED);
    assertEmployeeNotNotified($employee);
});

it('les notifications de refus déjà en base sont conservées', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $legacyId = (string) Illuminate\Support\Str::uuid();
    $employee->notifications()->create([
        'id' => $legacyId,
        'type' => 'App\\Notifications\\HourSheetDecisionNotification',
        'data' => ['type' => 'hour_sheet_refused', 'message' => 'Vos heures ont été refusées.'],
    ]);

    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non']);
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non']);

    expect(DB::table('notifications')->where('notifiable_id', $employee->id)->pluck('id')->all())->toBe([$legacyId]);
});

it('compteurs de validation inchangés après un refus', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    submitHourSheet($employee, '2026-10-05');
    $sheet = submitHourSheet($employee, '2026-10-06');

    $this->actingAs($v1)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingValidationCount', 2));

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non']);

    $this->actingAs($v1)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingValidationCount', 1));
    $this->actingAs($v2)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingValidationCount', 2));
});

it('la validation d\'heures fonctionne toujours, sans notification au salarié', function (): void {
    [$v1, $v2, $employee] = refusalCircuit();
    $sheet = submitHourSheet($employee);

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id))->assertSessionHas('success');
    $this->actingAs($v2)->post(route('hours.approve', $sheet->id))->assertSessionHas('success', 'Journée définitivement validée.');

    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
    assertEmployeeNotNotified($employee);
});

/*
|--------------------------------------------------------------------------
| Congés : notifications conservées, refus compris
|--------------------------------------------------------------------------
*/

it('le refus d\'un congé notifie toujours le demandeur, en base et en push', function (): void {
    [$v1, $v2, $requester] = refusalCircuit();
    $leave = submitLeave($requester);

    $this->actingAs($v1)->post(route('leaves.refuse', $leave->id));
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));

    expect($leave->fresh()->status)->toBe(ValidationStage::REFUSED);

    $refusal = DB::table('notifications')
        ->where('notifiable_id', $requester->id)
        ->where('type', LeaveRequestRefusedNotification::class)
        ->get();
    expect($refusal)->toHaveCount(1)
        ->and(json_decode($refusal->first()->data, true)['type'])->toBe('leave_request_refused');

    Queue::assertPushed(
        SendWebPushNotificationJob::class,
        fn (SendWebPushNotificationJob $job): bool => pushRecipientId($job) === (int) $requester->id,
    );
});

it('les autres notifications de congés sont inchangées', function (): void {
    [$v1, $v2, $requester] = refusalCircuit();
    $leave = submitLeave($requester);

    // Soumission : les deux valideurs sont prévenus.
    foreach ([$v1, $v2] as $validator) {
        expect(DB::table('notifications')
            ->where('notifiable_id', $validator->id)
            ->where('type', LeaveRequestSubmittedNotification::class)
            ->count())->toBe(1);
    }

    // Validation finale : le demandeur est prévenu.
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));

    expect(DB::table('notifications')
        ->where('notifiable_id', $requester->id)
        ->where('type', LeaveRequestApprovedNotification::class)
        ->count())->toBe(1);
});
