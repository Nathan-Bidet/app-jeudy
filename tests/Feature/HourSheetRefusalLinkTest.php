<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Support\Validation\ValidationStage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lien de la notification de refus d'heures.
 *
 * La notification doit ramener le salarié sur SA journée, pas sur le haut de la
 * page : l'historique en compte une par jour ouvré.
 *
 * La carte des destinations est dupliquée dans l'application — le middleware
 * Inertia la sert au chargement d'une page, NotificationController au
 * rafraîchissement du centre de notifications. Les deux sont donc éprouvés :
 * une notification ne doit pas être cliquable d'un côté et pas de l'autre.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Refuse une journée par les deux valideurs, et la renvoie. */
function refusedHourSheet(string $reason = 'Horaires incohérents'): array
{
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee, '2026-10-05');

    test()->actingAs($v1)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => $reason]);
    test()->actingAs($v2)->post(route('hours.refuse', $sheet->id), ['refusal_reason' => $reason]);

    $sheet->refresh();
    expect($sheet->status)->toBe(ValidationStage::REFUSED);

    return [$employee, $sheet];
}

it('renvoie le salarié sur la journée refusée depuis le centre de notifications', function (): void {
    [$employee, $sheet] = refusedHourSheet();

    $this->actingAs($employee)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notifications.0.type', 'hour_sheet_refused')
        ->assertJsonPath('notifications.0.url', route('hours.index', ['highlight' => $sheet->id]));
});

it('sert le même lien au chargement d\'une page', function (): void {
    [$employee, $sheet] = refusedHourSheet();

    $this->actingAs($employee)
        ->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('notifications.items.0.type', 'hour_sheet_refused')
            ->where('notifications.items.0.url', route('hours.index', ['highlight' => $sheet->id]))
        );
});

it('redirige vers la journée refusée et marque la notification comme lue', function (): void {
    [$employee, $sheet] = refusedHourSheet();

    $notification = $employee->notifications()->firstOrFail();

    $this->actingAs($employee)
        ->get(route('notifications.read_redirect', $notification->id))
        ->assertRedirect(route('hours.index', ['highlight' => $sheet->id]));

    expect($employee->fresh()->unreadNotifications()->count())->toBe(0);
});

it('expose la journée à ouvrir à la page Heures', function (): void {
    [$employee, $sheet] = refusedHourSheet();

    $this->actingAs($employee)
        ->get(route('hours.index', ['highlight' => $sheet->id]))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('highlightId', (int) $sheet->id)
            ->where('hourSheets.0.refusal_reason', 'Horaires incohérents')
            ->where('hourSheets.0.status', ValidationStage::REFUSED)
        );

    $this->actingAs($employee)
        ->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('highlightId', null));
});

it('sert le drapeau de journée continue au salarié comme au valideur', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee, '2026-10-05');
    $sheet->forceFill([
        'is_continuous_day' => true,
        'morning_end' => null,
        'afternoon_start' => null,
    ])->save();

    // Sans ce drapeau, le détail afficherait deux demi-journées vides là où le
    // valideur voit une plage continue.
    $this->actingAs($employee)
        ->get(route('hours.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('hourSheets.0.is_continuous_day', true)
        );
});

/*
|--------------------------------------------------------------------------
| Non-régression des autres destinations
|--------------------------------------------------------------------------
|
| La résolution des liens prend désormais la charge utile complète de la
| notification plutôt qu'une liste d'identifiants. Ces cas verrouillent les
| destinations qui existaient avant ce changement.
*/

it('conserve le lien des notifications de congé', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);

    $this->actingAs($v1)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notifications.0.type', 'leave_request_submitted')
        ->assertJsonPath('notifications.0.url', route('leaves.index', ['highlight' => $leave->id]));
});

it('conserve le lien du rappel de saisie des heures', function (): void {
    $employee = hoursUser();

    $employee->notify(new App\Notifications\HoursMissingEntryReminderNotification('2026-10-05'));

    $this->actingAs($employee)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notifications.0.url', route('hours.index'));
});

it('donne enfin un lien à la notification de rattrapage des heures', function (): void {
    $employee = hoursUser();

    $employee->notify(new App\Notifications\HoursAttachedToValidationNotification(3, '2026-10-05', '2026-10-07'));

    $this->actingAs($employee)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notifications.0.url', route('hours.index'));
});

it('ne casse pas les journées sans identifiant dans la notification', function (): void {
    $employee = hoursUser();

    // Charge utile tronquée : la notification reste cliquable, vers la page.
    $employee->notifications()->create([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'type' => App\Notifications\HourSheetDecisionNotification::class,
        'data' => ['type' => 'hour_sheet_refused', 'message' => 'Vos heures ont été refusées.'],
    ]);

    $this->actingAs($employee)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notifications.0.url', route('hours.index'));
});

it('ne remonte aucune journée d\'un autre salarié', function (): void {
    [, $sheet] = refusedHourSheet();
    $stranger = hoursUser();

    // L'identifiant est servi tel quel, mais la liste ne contient que les
    // journées du lecteur : le front n'y trouve aucune correspondance.
    $this->actingAs($stranger)
        ->get(route('hours.index', ['highlight' => $sheet->id]))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('highlightId', (int) $sheet->id)
            ->has('hourSheets', 0)
        );

    expect(HourSheet::query()->where('user_id', $stranger->id)->count())->toBe(0);
});
