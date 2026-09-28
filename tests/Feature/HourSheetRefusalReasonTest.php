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
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\PermissionRegistrar;

/*
 * Motif OBLIGATOIRE pour refuser une journée d'heures (RefuseHourSheetRequest).
 * Un motif absent ou vide n'écrit rien, ne journalise rien, ne notifie rien.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Mail::fake();
    Queue::fake();
});

function reasonCircuit(): array
{
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    return [$v1, $v2, $employee, submitHourSheet($employee, '2026-10-05')];
}

/** Rien n'a bougé : ni décision, ni motif, ni journal, ni notification. */
function assertRefusalNotRecorded(HourSheet $sheet, User $employee): void
{
    $fresh = $sheet->fresh();

    expect($fresh->status)->toBe(ValidationStage::PENDING)
        ->and($fresh->validator_1_decision)->toBeNull()
        ->and($fresh->validator_2_decision)->toBeNull()
        ->and($fresh->validator_1_decided_at)->toBeNull()
        ->and($fresh->refusal_reason)->toBeNull()
        ->and(DB::table('audit_logs')->whereIn('action', ['refuse_hour_sheet', 'refuse_hour_sheet_partial'])->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $employee->id)->count())->toBe(0);

    Queue::assertNothingPushed();
    Mail::assertNothingSent();
}

it('refuse un appel direct sans motif (formulaire)', function (): void {
    [$v1, , $employee, $sheet] = reasonCircuit();

    $this->actingAs($v1)
        ->from(route('hours.index'))
        ->post(route('hours.refuse', $sheet->id))
        ->assertRedirect(route('hours.index'))
        ->assertSessionHasErrors(['refusal_reason' => 'Le motif du refus est obligatoire.'])
        ->assertSessionMissing('success');

    assertRefusalNotRecorded($sheet, $employee);
});

it('renvoie 422 à un appel JSON sans motif', function (): void {
    [$v1, , $employee, $sheet] = reasonCircuit();

    $this->actingAs($v1)
        ->postJson(route('hours.refuse', $sheet->id), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['refusal_reason' => 'Le motif du refus est obligatoire.']);

    assertRefusalNotRecorded($sheet, $employee);
});

it('refuse un motif vide ou fait uniquement d\'espaces', function (mixed $reason): void {
    [$v1, , $employee, $sheet] = reasonCircuit();

    $this->actingAs($v1)
        ->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => $reason])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['refusal_reason' => 'Le motif du refus est obligatoire.']);

    assertRefusalNotRecorded($sheet, $employee);
})->with([
    'chaîne vide' => '',
    'espaces' => '     ',
    'tabulations et retours à la ligne' => "\t\n \r\n\t",
    'espaces insécables' => "\u{00A0}\u{00A0}",
    'null' => null,
    'tableau' => [['x']],
]);

it('refuse un motif trop long, accepte la longueur maximale', function (): void {
    [$v1, $v2, $employee, $sheet] = reasonCircuit();

    $this->actingAs($v1)
        ->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => str_repeat('a', 2001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['refusal_reason' => 'Le motif du refus ne peut pas dépasser 2000 caractères.']);
    assertRefusalNotRecorded($sheet, $employee);

    $this->actingAs($v1)
        ->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => str_repeat('a', 2000)])
        ->assertOk();

    expect(mb_strlen($sheet->fresh()->refusal_reason))->toBe(2000);
});

it('enregistre un refus avec un motif valide, nettoyé, avec le valideur et la date', function (): void {
    [$v1, $v2, $employee, $sheet] = reasonCircuit();
    $this->travelTo('2026-10-06 09:30:00');

    $this->actingAs($v1)
        ->post(route('hours.refuse', $sheet->id), ['refusal_reason' => "  \tPause <b>non</b> déclarée & « fin » à 21h\n  "])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Votre décision est enregistrée. La journée reste en attente de la seconde validation.');

    $fresh = $sheet->fresh();
    expect($fresh->validator_1_decision)->toBe(ValidationStage::DECISION_REFUSED)
        ->and((int) $fresh->validator_1_decided_by_id)->toBe((int) $v1->id)
        ->and($fresh->validator_1_decided_at?->toDateTimeString())->toBe('2026-10-06 09:30:00')
        ->and($fresh->refusal_reason)->toBe('Pause <b>non</b> déclarée & « fin » à 21h');

    $audit = DB::table('audit_logs')->where('action', 'refuse_hour_sheet_partial')->sole();
    expect(json_decode($audit->payload, true)['refusal_reason'])->toBe('Pause <b>non</b> déclarée & « fin » à 21h');

    // Le second refus clôt la journée, sans notification au salarié.
    $this->actingAs($v2)
        ->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Horaires incohérents'])
        ->assertSessionHas('success', 'Journée définitivement refusée.');

    expect($sheet->fresh()->status)->toBe(ValidationStage::REFUSED)
        ->and(DB::table('notifications')->where('notifiable_id', $employee->id)->count())->toBe(0);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('le motif reste lisible par l\'administration et invisible pour le propriétaire', function (): void {
    [$v1, $v2, $employee, $sheet] = reasonCircuit();
    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Motif interne']);
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Motif interne']);

    // Propriétaire : ni le motif, ni l'issue.
    $props = $this->actingAs($employee)->get(route('hours.index'))->viewData('page')['props'];
    $mine = collect($props['hourSheets'])->firstWhere('id', $sheet->id);
    expect($mine)->toMatchArray(['status' => 'processed', 'status_label' => 'Traitée'])
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('Motif interne');

    // Administration : export complet.
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

    expect($cells)->toContain('Refusé - Motif interne');
});

it('refuse l\'accès à un non-valideur avant toute validation du motif', function (): void {
    [, , $employee, $sheet] = reasonCircuit();
    $stranger = hoursUser();

    // Sans motif : 403, pas 422 — la validation ne doit rien révéler.
    $this->actingAs($stranger)->postJson(route('hours.refuse', $sheet->id))->assertForbidden();
    $this->actingAs($stranger)
        ->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Tentative'])
        ->assertForbidden();
    $this->actingAs($employee)
        ->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Auto-refus'])
        ->assertForbidden();

    assertRefusalNotRecorded($sheet, $employee);
});

it('double soumission : une seule décision, le second envoi est refusé', function (): void {
    [$v1, , $employee, $sheet] = reasonCircuit();

    $this->actingAs($v1)->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Premier'])->assertOk();
    $this->actingAs($v1)->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Second'])->assertForbidden();
    $this->actingAs($v1)->postJson(route('hours.refuse', $sheet->id), [])->assertForbidden();

    expect($sheet->fresh()->refusal_reason)->toBe('Premier')
        ->and(DB::table('audit_logs')->where('action', 'refuse_hour_sheet_partial')->count())->toBe(1);
});

it('la validation d\'heures ne demande toujours aucun motif', function (): void {
    [$v1, $v2, , $sheet] = reasonCircuit();

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id))->assertSessionHasNoErrors();
    $this->actingAs($v2)->post(route('hours.approve', $sheet->id))->assertSessionHasNoErrors();

    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('les congés se refusent toujours sans motif et notifient le demandeur', function (): void {
    [$v1, $v2, $requester] = reasonCircuit();
    $leave = submitLeave($requester);

    $this->actingAs($v1)->post(route('leaves.refuse', $leave->id))->assertSessionHasNoErrors();
    $this->actingAs($v2)->post(route('leaves.refuse', $leave->id))->assertSessionHasNoErrors();

    expect($leave->fresh()->status)->toBe(ValidationStage::REFUSED)
        ->and(DB::table('notifications')
            ->where('notifiable_id', $requester->id)
            ->where('type', LeaveRequestRefusedNotification::class)
            ->count())->toBe(1);

    Queue::assertPushed(
        SendWebPushNotificationJob::class,
        fn (SendWebPushNotificationJob $job): bool => (int) (new ReflectionProperty($job, 'userId'))->getValue($job) === (int) $requester->id,
    );
});
