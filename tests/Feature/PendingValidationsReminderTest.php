<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\PushSubscription;
use App\Models\User;
use App\Jobs\SendWebPushNotificationJob;
use App\Notifications\PendingValidationsReminderNotification;
use App\Services\Validation\PendingValidationDigestService;
use App\Services\Validation\ValidationRolloutService;
use App\Support\Validation\ValidationStage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rappel hebdomadaire aux valideurs — jeudi 14h.
 *
 * Ce qui est éprouvé ici : le rappel compte EXACTEMENT ce que la file de
 * validation montre au valideur, ni plus ni moins, et n'atteint la notification
 * native que si l'utilisateur l'a activée.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Exécute la commande planifiée, sans dépendre du planificateur. */
function runReminder(): void
{
    test()->artisan('validation:send-pending-reminders')->assertSuccessful();
}

/** Rappels reçus par un utilisateur, tels qu'ils sont stockés en base. */
function remindersFor(User $user): Illuminate\Support\Collection
{
    // `reorder` et non `orderBy` : la relation `notifications` applique déjà
    // un tri décroissant, qu'un `orderBy` ne ferait que compléter — le plus
    // récent resterait en tête et `last()` renverrait le plus ancien.
    return $user->notifications()
        ->reorder('created_at', 'asc')
        ->get()
        ->filter(fn ($notification): bool => ($notification->data['type'] ?? null) === PendingValidationsReminderNotification::TYPE)
        ->values();
}

/** Contenu du dernier rappel reçu. */
function lastReminder(User $user): array
{
    $reminder = remindersFor($user)->last();

    expect($reminder)->not->toBeNull();

    return $reminder->data;
}

/*
|--------------------------------------------------------------------------
| Rien à valider
|--------------------------------------------------------------------------
*/

it('n\'envoie rien quand aucun valideur n\'a d\'élément en attente', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    groupWith($v1, $v2, [twoStepUser()]);

    Notification::fake();
    runReminder();

    Notification::assertNothingSent();
});

it('n\'envoie rien au valideur qui a déjà tranché tout ce qui lui revenait', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $leave = submitLeave($requester);
    $this->actingAs($v1)->post(route('leaves.approve', $leave->id));

    runReminder();

    expect(remindersFor($v1))->toHaveCount(0)
        ->and(remindersFor($v2))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Congés, heures, et les deux
|--------------------------------------------------------------------------
*/

it('rappelle les demandes de congés en attente', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    runReminder();

    $data = lastReminder($v1);

    expect($data['title'])->toBe('Validations en attente')
        ->and($data['leave_count'])->toBe(1)
        ->and($data['hours_count'])->toBe(0)
        ->and($data['total_count'])->toBe(1)
        ->and($data['message'])->toBe('Vous avez 1 demande de congé en attente de validation.');
});

it('accorde le pluriel des congés', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $type = leaveTypeForTests();
    submitLeaveOn($requester, '2026-10-05', '2026-10-06', $type);
    submitLeaveOn($requester, '2026-10-12', '2026-10-13', $type);
    submitLeaveOn($requester, '2026-10-19', '2026-10-20', $type);

    runReminder();

    expect(lastReminder($v1)['message'])
        ->toBe('Vous avez 3 demandes de congés en attente de validation.');
});

it('rappelle les journées d\'heures en attente', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    submitHourSheet($employee, '2026-10-05');
    submitHourSheet($employee, '2026-10-06');

    runReminder();

    $data = lastReminder($v2);

    expect($data['leave_count'])->toBe(0)
        ->and($data['hours_count'])->toBe(2)
        ->and($data['message'])->toBe('Vous avez 2 journées d\'heures en attente de validation.');
});

it('regroupe congés et heures dans une notification unique', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $type = leaveTypeForTests();
    submitLeaveOn($employee, '2026-10-05', '2026-10-06', $type);
    submitLeaveOn($employee, '2026-10-12', '2026-10-13', $type);
    submitHourSheet($employee, '2026-10-19');
    submitHourSheet($employee, '2026-10-20');
    submitHourSheet($employee, '2026-10-21');

    runReminder();

    expect(remindersFor($v1))->toHaveCount(1);

    $data = lastReminder($v1);

    expect($data['leave_count'])->toBe(2)
        ->and($data['hours_count'])->toBe(3)
        ->and($data['total_count'])->toBe(5)
        ->and($data['message'])->toBe(
            'Vous avez 5 éléments en attente de validation : 2 congés et 3 journées d\'heures.'
        );
});

/*
|--------------------------------------------------------------------------
| Ce qui ne doit jamais être compté
|--------------------------------------------------------------------------
*/

it('ne compte pas un élément sur lequel le valideur s\'est déjà prononcé', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $sheet = submitHourSheet($employee, '2026-10-05');
    submitHourSheet($employee, '2026-10-06');

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    runReminder();

    // Le Valideur 1 n'a plus qu'une journée ; le Valideur 2 en a toujours deux,
    // sa décision restant attendue sur celle que le Valideur 1 a validée.
    expect(lastReminder($v1)['hours_count'])->toBe(1)
        ->and(lastReminder($v2)['hours_count'])->toBe(2);
});

it('ne compte pas les demandes définitivement validées ou refusées', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    $type = leaveTypeForTests();
    $approved = submitLeaveOn($requester, '2026-10-05', '2026-10-06', $type);
    $refused = submitLeaveOn($requester, '2026-10-12', '2026-10-13', $type);

    $this->actingAs($v1)->post(route('leaves.approve', $approved->id));
    $this->actingAs($v2)->post(route('leaves.approve', $approved->id));
    $this->actingAs($v1)->post(route('leaves.refuse', $refused->id), ['reason' => 'Non']);
    $this->actingAs($v2)->post(route('leaves.refuse', $refused->id), ['reason' => 'Non']);

    expect($approved->fresh()->status)->toBe(ValidationStage::APPROVED)
        ->and($refused->fresh()->status)->toBe(ValidationStage::REFUSED);

    Notification::fake();
    runReminder();

    Notification::assertNothingSent();
});

it('ne compte pas les heures antérieures à la date d\'effet du système', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    app(ValidationRolloutService::class)->setEffectiveDate('2026-10-10');

    submitHourSheet($employee, '2026-10-05'); // hors périmètre : statut NULL
    submitHourSheet($employee, '2026-10-12'); // dans le périmètre

    expect(HourSheet::query()->whereNull('status')->count())->toBe(1);

    runReminder();

    expect(lastReminder($v1)['hours_count'])->toBe(1);
});

it('inclut les heures anciennes rattrapées par la date d\'effet', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $rollout = app(ValidationRolloutService::class);

    // Journées saisies alors que le système ne s'appliquait pas encore.
    $rollout->setEffectiveDate('2026-11-01');
    submitHourSheet($employee, '2026-10-05');
    submitHourSheet($employee, '2026-10-06');

    runReminder();
    expect(remindersFor($v1))->toHaveCount(0);

    // La date recule : les deux journées entrent dans le circuit.
    $rollout->setEffectiveDate('2026-10-01');
    $rollout->backfillHourSheets(notify: false);

    // Nouveau jour, sinon la garde d'idempotence bloquerait le second rappel.
    $this->travel(1)->days();
    runReminder();

    expect(lastReminder($v1)['hours_count'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Unicité
|--------------------------------------------------------------------------
*/

it('n\'envoie qu\'une notification à un valideur de plusieurs groupes', function (): void {
    $validator = hoursUser();
    $other = hoursUser();
    $employeeA = hoursUser();
    $employeeB = hoursUser();

    // Valideur 1 ici, Valideur 2 là : les deux responsabilités se cumulent.
    groupWith($validator, $other, [$employeeA], 'Atelier');
    groupWith($other, $validator, [$employeeB], 'Bureau');

    submitHourSheet($employeeA, '2026-10-05');
    submitHourSheet($employeeB, '2026-10-05');

    runReminder();

    expect(remindersFor($validator))->toHaveCount(1)
        ->and(lastReminder($validator)['hours_count'])->toBe(2);
});

it('ne compte qu\'une fois un élément dont il est valideur aux deux rangs', function (): void {
    $validator = hoursUser();
    $other = hoursUser();
    $employee = hoursUser();
    groupWith($validator, $other, [$employee]);

    $sheet = submitHourSheet($employee, '2026-10-05');

    // Cas de bord hérité : un instantané où la même personne occupe les deux
    // rangs. L'écran de création l'interdit, mais l'historique peut le porter.
    $sheet->forceFill(['validator_2_id' => $validator->id])->save();

    runReminder();

    expect(lastReminder($validator)['hours_count'])->toBe(1);
});

it('ne renvoie pas un second rappel le même jour', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    runReminder();
    runReminder();
    runReminder();

    expect(remindersFor($v1))->toHaveCount(1)
        ->and(remindersFor($v2))->toHaveCount(1);
});

it('rappelle de nouveau la semaine suivante', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    runReminder();

    $this->travel(7)->days();
    runReminder();

    expect(remindersFor($v1))->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Notification native
|--------------------------------------------------------------------------
*/

it('envoie aussi une notification native quand l\'utilisateur l\'a activée', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    PushSubscription::query()->create([
        'user_id' => $v1->id,
        'endpoint' => 'https://push.example.test/abc',
        'endpoint_hash' => hash('sha256', 'https://push.example.test/abc'),
        'p256dh' => 'cle-publique',
        'auth' => 'secret',
    ]);

    // Après la soumission : seuls les envois du rappel sont comptés.
    Bus::fake();
    runReminder();

    // Interne pour les deux, native pour le seul abonné.
    expect(remindersFor($v1))->toHaveCount(1)
        ->and(remindersFor($v2))->toHaveCount(1);

    Bus::assertDispatchedTimes(SendWebPushNotificationJob::class, 1);
});

it('n\'envoie aucune notification native sans abonnement', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    Bus::fake();
    runReminder();

    expect(remindersFor($v1))->toHaveCount(1);

    Bus::assertNotDispatched(SendWebPushNotificationJob::class);
});

/*
|--------------------------------------------------------------------------
| Lien et destinataires
|--------------------------------------------------------------------------
*/

it('oriente le lien vers le module concerné', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    submitHourSheet($employee, '2026-10-05');

    runReminder();

    expect(lastReminder($v1)['target'])->toBe('hours');

    // Un congé s'ajoute : le lien bascule sur les Congés, seule destination
    // capable de porter les deux files en une adresse.
    $this->travel(1)->days();
    submitLeaveOn($employee, '2026-10-12', '2026-10-13');
    runReminder();

    expect(lastReminder($v1)['target'])->toBe('leaves');
});

it('expose le rappel dans le centre de notifications, avec son lien', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    // La notification de soumission et le rappel tomberaient sinon à la même
    // seconde, et l'ordre de la liste ne serait pas déterminé.
    $this->travel(1)->minutes();
    runReminder();

    $this->actingAs($v1)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notifications.0.type', PendingValidationsReminderNotification::TYPE)
        ->assertJsonPath('notifications.0.title', 'Validations en attente')
        ->assertJsonPath('notifications.0.url', route('leaves.index'))
        ->assertJsonPath('notifications.0.read_at', null);
});

it('ne rappelle pas un utilisateur désactivé', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWith($v1, $v2, [$requester]);

    submitLeave($requester);

    $v1->forceFill(['is_active' => false])->save();

    runReminder();

    expect(remindersFor($v1->fresh()))->toHaveCount(0)
        ->and(remindersFor($v2))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Équivalence avec la file de validation
|--------------------------------------------------------------------------
*/

it('compte exactement ce que la file de validation montre à chaque valideur', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $v3 = hoursUser();
    $employeeA = hoursUser();
    $employeeB = hoursUser();

    groupWith($v1, $v2, [$employeeA], 'Atelier');
    groupWith($v2, $v3, [$employeeB], 'Bureau');

    $type = leaveTypeForTests();
    submitLeaveOn($employeeA, '2026-10-05', '2026-10-06', $type);
    submitLeaveOn($employeeB, '2026-10-12', '2026-10-13', $type);
    $sheet = submitHourSheet($employeeA, '2026-10-19');
    submitHourSheet($employeeB, '2026-10-19');

    // Une décision partielle, pour que les deux valideurs d'un même élément
    // n'aient pas le même compte.
    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    $counts = app(PendingValidationDigestService::class)->pendingCountsByValidator();

    foreach ([$v1, $v2, $v3] as $validator) {
        $expectedLeaves = LeaveRequest::query()->awaitingDecisionBy($validator)->count();
        $expectedHours = HourSheet::query()->awaitingDecisionBy($validator)->count();
        $actual = $counts[(int) $validator->id] ?? ['leaves' => 0, 'hours' => 0, 'total' => 0];

        expect($actual['leaves'])->toBe($expectedLeaves)
            ->and($actual['hours'])->toBe($expectedHours)
            ->and($actual['total'])->toBe($expectedLeaves + $expectedHours);
    }

    // Le jeu de données doit réellement contenir du travail en attente, sans
    // quoi le test passerait sur des zéros.
    expect(array_sum(array_column($counts, 'total')))->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| Planification
|--------------------------------------------------------------------------
*/

it('est planifiée tous les jeudis à 14h dans le fuseau de l\'application', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'validation:send-pending-reminders'))
        ->values();

    expect($events)->toHaveCount(1);

    $event = $events->first();

    // 0 14 * * 4 : à 14h00, le jeudi.
    expect($event->expression)->toBe('0 14 * * 4')
        ->and($event->timezone)->toBe(config('app.timezone', 'Europe/Paris'));
});
