<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Jobs\SendWebPushNotificationJob;
use App\Models\HourSheet;
use App\Models\User;
use App\Notifications\LeaveRequestRefusedNotification;
use App\Support\Validation\ValidationStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\PermissionRegistrar;

/*
 * Ce que le PROPRIÉTAIRE voit de ses heures : « En validation », puis
 * « Traitée » — ni l'issue, ni le motif. L'état réel reste en base et
 * continue d'être servi aux valideurs, à l'administration et à l'export.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Mail::fake();
    Queue::fake();
});

const OWNER_REASON = 'Horaires <b>incohérents</b> & « pause » non déclarée — '.'détail très long';

function ownerCircuit(): array
{
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    return [$v1, $v2, $employee];
}

/** Props Inertia de la page Heures, telles que reçues par le navigateur. */
function ownerHoursProps($test, User $viewer): array
{
    return $test->actingAs($viewer)->get(route('hours.index'))->viewData('page')['props'];
}

function ownerSheetPayload($test, User $owner, HourSheet $sheet): array
{
    return collect(ownerHoursProps($test, $owner)['hourSheets'])->firstWhere('id', $sheet->id);
}

it('une journée validée apparaît « Traitée » à son propriétaire', function (): void {
    [$v1, $v2, $employee] = ownerCircuit();
    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    $this->actingAs($v2)->post(route('hours.approve', $sheet->id));

    expect(ownerSheetPayload($this, $employee, $sheet))
        ->toMatchArray(['status' => 'processed', 'status_label' => 'Traitée'])
        ->and($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('une journée refusée apparaît « Traitée », sans motif transmis', function (): void {
    [$v1, $v2, $employee] = ownerCircuit();
    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);

    $payload = ownerSheetPayload($this, $employee, $sheet);
    expect($payload)->toMatchArray(['status' => 'processed', 'status_label' => 'Traitée'])
        ->and($payload)->not->toHaveKey('refusal_reason')
        ->and($payload)->not->toHaveKey('validation_summary');

    // Rien, nulle part dans la page, ne trahit le refus ni son motif.
    $raw = json_encode(ownerHoursProps($this, $employee), JSON_UNESCAPED_UNICODE);
    expect($raw)->not->toContain('incohérents')
        ->and($raw)->not->toContain('refusal_reason')
        ->and($raw)->not->toContain('"refused"')
        ->and($raw)->not->toContain('Refusé');

    // Réponse Inertia en JSON (navigation client, appel direct) : idem.
    $json = $this->actingAs($employee)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get(route('hours.index'))
        ->getContent();
    expect($json)->not->toContain('incohérents')->and($json)->not->toContain('"refused"');

    // L'état réel et le motif restent en base, intacts.
    $fresh = $sheet->fresh();
    expect($fresh->status)->toBe(ValidationStage::REFUSED)
        ->and($fresh->refusal_reason)->toBe(OWNER_REASON)
        ->and($fresh->validator_1_decision)->toBe(ValidationStage::DECISION_REFUSED);
});

it('une journée encore en validation garde son statut', function (): void {
    [$v1, , $employee] = ownerCircuit();
    $sheet = submitHourSheet($employee);

    expect(ownerSheetPayload($this, $employee, $sheet))
        ->toMatchArray(['status' => ValidationStage::PENDING, 'status_label' => 'En attente de validation']);

    // Premier valideur refuse, l'autre n'a pas répondu : pas encore traitée.
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);

    $payload = ownerSheetPayload($this, $employee, $sheet);
    expect($payload)->toMatchArray(['status' => ValidationStage::PENDING, 'status_label' => 'En attente de validation'])
        ->and(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain('incohérents');
});

it('circuit à un seul valideur : « Traitée » dès sa décision', function (): void {
    $v1 = hoursUser();
    $employee = hoursUser();
    $group = groupWith($v1, hoursUser(), [$employee]);
    $group->update(['validator_2_id' => null]);

    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id));

    expect(ownerSheetPayload($this, $employee, $sheet)['status'])->toBe('processed');
});

it('les anciennes journées déjà closes bénéficient du nouvel affichage, sans migration', function (): void {
    [, , $employee] = ownerCircuit();
    $approved = submitHourSheet($employee, '2026-10-05');
    $refused = submitHourSheet($employee, '2026-10-06');
    $legacy = submitHourSheet($employee, '2026-10-07');

    // Données telles qu'elles existent déjà en base.
    DB::table('hour_sheets')->where('id', $approved->id)->update(['status' => ValidationStage::APPROVED]);
    DB::table('hour_sheets')->where('id', $refused->id)->update(['status' => ValidationStage::REFUSED, 'refusal_reason' => 'Ancien motif']);
    DB::table('hour_sheets')->where('id', $legacy->id)->update(['status' => null]);

    expect(ownerSheetPayload($this, $employee, $approved)['status_label'])->toBe('Traitée')
        ->and(ownerSheetPayload($this, $employee, $refused)['status_label'])->toBe('Traitée')
        ->and(ownerSheetPayload($this, $employee, $legacy))->toMatchArray(['status' => null, 'status_label' => 'Saisie antérieure à la validation'])
        ->and(json_encode(ownerHoursProps($this, $employee), JSON_UNESCAPED_UNICODE))->not->toContain('Ancien motif')
        ->and(DB::table('hour_sheets')->where('id', $refused->id)->value('status'))->toBe(ValidationStage::REFUSED);
});

it('le valideur voit toujours le véritable état de chaque rang', function (): void {
    [$v1, $v2, $employee] = ownerCircuit();
    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);

    $this->actingAs($v2)
        ->get(route('hours.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hourSheetsToValidate.0.id', $sheet->id)
            ->where('hourSheetsToValidate.0.validation_summary.0.decision', ValidationStage::DECISION_REFUSED)
            ->where('hourSheetsToValidate.0.validation_summary.0.label', 'Refusé')
            ->where('hourSheetsToValidate.0.validation_summary.1.label', 'En attente'));
});

it('l\'administrateur voit le véritable état et le motif dans l\'export, qui reste inchangé', function (): void {
    [$v1, $v2, $employee] = ownerCircuit();
    $sheet = submitHourSheet($employee, '2026-10-05');
    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Pause non déclarée']);

    $response = $this->actingAs(twoStepAdmin())
        ->get(route('hours.export', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']))
        ->assertOk();

    $reader = new Reader();
    $reader->open($response->baseResponse->getFile()->getPathname());
    $cells = [];
    foreach ($reader->getSheetIterator() as $xlsxSheet) {
        foreach ($xlsxSheet->getRowIterator() as $row) {
            $cells = array_merge($cells, array_map(fn ($value): string => (string) $value, $row->toArray()));
        }
        break;
    }
    $reader->close();

    expect($cells)->toContain('Validé')
        ->and($cells)->toContain('Refusé - Pause non déclarée')
        ->and($cells)->not->toContain('Traitée');
});

it('un administrateur qui consulte SES heures les voit, lui aussi, « Traitée »', function (): void {
    $admin = twoStepAdmin();
    $admin->forceFill(['sector_id' => hoursUser()->sector_id])->save();
    $v1 = hoursUser();
    $v2 = hoursUser();
    groupWith($v1, $v2, [$admin]);

    $sheet = submitHourSheet($admin);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);

    expect(ownerSheetPayload($this, $admin, $sheet))->toMatchArray(['status' => 'processed', 'status_label' => 'Traitée']);
});

it('aucune notification, e-mail ni push au propriétaire lors d\'un refus', function (): void {
    [$v1, $v2, $employee] = ownerCircuit();
    $sheet = submitHourSheet($employee);
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => OWNER_REASON]);

    expect(DB::table('notifications')->where('notifiable_id', $employee->id)->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNotPushed(
        SendWebPushNotificationJob::class,
        fn (SendWebPushNotificationJob $job): bool => (int) (new ReflectionProperty($job, 'userId'))->getValue($job) === (int) $employee->id,
    );
});

it('un congé refusé garde son statut réel et sa notification', function (): void {
    [$v1, $v2, $requester] = ownerCircuit();
    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.refuse', $leave->id));
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id));

    $this->actingAs($requester)
        ->get(route('leaves.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('myLeaveRequests.0.status', ValidationStage::REFUSED)
            ->where('myLeaveRequests.0.status_label', 'Refusé'));

    $this->actingAs($requester)
        ->getJson(route('leaves.show', $leave->id))
        ->assertJsonPath('status', ValidationStage::REFUSED)
        ->assertJsonPath('status_label', 'Refusé');

    expect(DB::table('notifications')
        ->where('notifiable_id', $requester->id)
        ->where('type', LeaveRequestRefusedNotification::class)
        ->count())->toBe(1);

    // Le message de la notification de congé n'est pas neutralisé.
    $this->actingAs($requester)
        ->getJson(route('notifications.latest'))
        ->assertJsonPath('notifications.0.type', 'leave_request_refused');
});
