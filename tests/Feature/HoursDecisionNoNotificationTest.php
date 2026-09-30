<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Jobs\SendWebPushNotificationJob;
use App\Models\HourSheet;
use App\Models\User;
use App\Notifications\LeaveRequestApprovedNotification;
use App\Support\Validation\ValidationStage;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

/*
 * Une décision sur des heures (validation, refus, clôture en « Traitée ») ne
 * notifie PERSONNE : ni le salarié, ni l'autre valideur, ni un administrateur,
 * par aucun canal — cloche en base, e-mail, push web, diffusion temps réel ou
 * job en file.
 *
 * Chaque décision est observée isolément : les canaux sont réinitialisés
 * juste avant, pour ne pas confondre avec ce que la SAISIE déclenche.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Mail::fake();
    Queue::fake();
});

/**
 * Exécute une décision et vérifie qu'elle n'a produit aucune notification,
 * pour aucun destinataire, sur aucun canal.
 */
function assertDecisionNotifiesNobody(callable $decision): void
{
    $notificationsBefore = DB::table('notifications')->count();

    Notification::fake();
    Mail::fake();
    Queue::fake();

    $broadcastOrNotificationEvents = [];
    Event::listen('*', function (string $name, array $payload) use (&$broadcastOrNotificationEvents): void {
        $event = $payload[0] ?? null;
        if ($event instanceof ShouldBroadcast || str_starts_with($name, 'Illuminate\\Notifications\\Events\\')) {
            $broadcastOrNotificationEvents[] = $name;
        }
    });

    $decision();

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
    Queue::assertNotPushed(SendWebPushNotificationJob::class);

    expect(DB::table('notifications')->count())->toBe($notificationsBefore)
        ->and($broadcastOrNotificationEvents)->toBe([]);
}

/** Circuit à deux valideurs, un administrateur à côté. */
function decisionCircuit(): array
{
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    hoursUser(['admin.users.manage', 'heures.view', 'heures.create', 'heures.export']);

    return [$v1, $v2, $employee];
}

function approveAs(User $validator, HourSheet $sheet): void
{
    test()->actingAs($validator)->post(route('hours.approve', $sheet->id))->assertSessionHas('success');
}

function refuseAs(User $validator, HourSheet $sheet): void
{
    test()->actingAs($validator)
        ->post(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Horaires à revoir'])
        ->assertSessionHas('success');
}

/** Statut vu par le salarié sur sa page Heures. */
function ownerStatusOf(User $employee, HourSheet $sheet): ?string
{
    return collect(test()->actingAs($employee)->get(route('hours.index'))->viewData('page')['props']['hourSheets'])
        ->firstWhere('id', $sheet->id)['status'] ?? null;
}

/*
 * [première décision (rang, action), seconde décision, statut final en base]
 * Deux accords → validée ; en désaccord, le Valideur 2 tranche.
 */
dataset('circuits', [
    'V1 valide, V2 valide' => [[1, 'approve'], [2, 'approve'], ValidationStage::APPROVED],
    'V1 valide, V2 refuse' => [[1, 'approve'], [2, 'refuse'], ValidationStage::REFUSED],
    'V1 refuse, V2 valide' => [[1, 'refuse'], [2, 'approve'], ValidationStage::APPROVED],
    'V1 refuse, V2 refuse' => [[1, 'refuse'], [2, 'refuse'], ValidationStage::REFUSED],
    'V2 valide, V1 valide' => [[2, 'approve'], [1, 'approve'], ValidationStage::APPROVED],
    'V2 refuse, V1 refuse' => [[2, 'refuse'], [1, 'refuse'], ValidationStage::REFUSED],
]);

it('aucune décision du circuit ne notifie qui que ce soit, jusqu\'à « Traitée »', function (array $first, array $second, string $finalStatus): void {
    [$v1, $v2, $employee] = decisionCircuit();
    $validators = [1 => $v1, 2 => $v2];
    $sheet = submitHourSheet($employee);

    // Première décision : la journée reste en validation.
    assertDecisionNotifiesNobody(function () use ($validators, $first, $sheet): void {
        [$level, $action] = $first;
        $action === 'approve' ? approveAs($validators[$level], $sheet) : refuseAs($validators[$level], $sheet);
    });
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING)
        ->and(ownerStatusOf($employee, $sheet))->toBe(ValidationStage::PENDING);

    // Seconde décision : le circuit se clôt, la journée passe « Traitée ».
    assertDecisionNotifiesNobody(function () use ($validators, $second, $sheet): void {
        [$level, $action] = $second;
        $action === 'approve' ? approveAs($validators[$level], $sheet) : refuseAs($validators[$level], $sheet);
    });
    expect($sheet->fresh()->status)->toBe($finalStatus)
        ->and(ownerStatusOf($employee, $sheet))->toBe('processed');
})->with('circuits');

it('circuit à un seul valideur : validation et refus clôturent sans notification', function (): void {
    $validator = hoursUser();
    $employee = hoursUser();
    $group = groupWith($validator, hoursUser(), [$employee]);
    $group->update(['validator_2_id' => null]);

    $approved = submitHourSheet($employee, '2026-10-05');
    $refused = submitHourSheet($employee, '2026-10-06');

    assertDecisionNotifiesNobody(fn () => approveAs($validator, $approved));
    assertDecisionNotifiesNobody(fn () => refuseAs($validator, $refused));

    expect($approved->fresh()->status)->toBe(ValidationStage::APPROVED)
        ->and($refused->fresh()->status)->toBe(ValidationStage::REFUSED)
        ->and(ownerStatusOf($employee, $approved))->toBe('processed')
        ->and(ownerStatusOf($employee, $refused))->toBe('processed');
});

it('appels JSON (file de validation) : décisions appliquées, sans notification', function (): void {
    [$v1, $v2, $employee] = decisionCircuit();
    $sheet = submitHourSheet($employee);

    assertDecisionNotifiesNobody(fn () => test()->actingAs($v1)
        ->postJson(route('hours.approve', $sheet->id))
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => ValidationStage::PENDING]));

    assertDecisionNotifiesNobody(fn () => test()->actingAs($v2)
        ->postJson(route('hours.refuse', $sheet->id), ['refusal_reason' => 'Non conforme'])
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => ValidationStage::REFUSED]));
});

it('décision refusée (droit, motif, journée déjà traitée) : rien n\'est écrit ni notifié', function (): void {
    [$v1, $v2, $employee] = decisionCircuit();
    $sheet = submitHourSheet($employee);

    // Sans droit de décider.
    assertDecisionNotifiesNobody(fn () => test()->actingAs($employee)
        ->postJson(route('hours.approve', $sheet->id))
        ->assertForbidden());

    // Motif absent.
    assertDecisionNotifiesNobody(fn () => test()->actingAs($v1)
        ->post(route('hours.refuse', $sheet->id), ['refusal_reason' => ''])
        ->assertSessionHasErrors('refusal_reason'));

    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING);

    approveAs($v1, $sheet);
    approveAs($v2, $sheet);

    // Journée déjà close : décision répétée.
    assertDecisionNotifiesNobody(fn () => test()->actingAs($v2)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Trop tard',
    ]));

    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED);
});

it('les notifications sans rapport avec la validation des heures fonctionnent toujours', function (): void {
    [$v1, $v2, $requester] = decisionCircuit();
    $leave = submitLeave($requester);

    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));
    $this->actingAs($v2)->post(route('leaves.approve', $leave->id));

    expect(DB::table('notifications')
        ->where('notifiable_id', $requester->id)
        ->where('type', LeaveRequestApprovedNotification::class)
        ->count())->toBe(1);
    Queue::assertPushed(SendWebPushNotificationJob::class);
});
