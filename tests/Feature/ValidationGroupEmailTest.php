<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Mail\HourSheetSubmittedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\User;
use App\Models\ValidationGroup;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\Validation\ValidationRolloutService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/**
 * Destinataires email d'un groupe de validation.
 *
 * Ces emails S'AJOUTENT aux notifications existantes : plusieurs tests
 * vérifient explicitement que les valideurs continuent de recevoir les leurs.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Charge utile du formulaire de groupe. */
function groupPayload(User $v1, User $v2, array $overrides = []): array
{
    return array_merge([
        'name' => 'Atelier',
        'validator_1_id' => $v1->id,
        'validator_2_id' => $v2->id,
        'member_user_ids' => [],
    ], $overrides);
}

/** Crée un groupe par la route réelle et renvoie la réponse. */
function postGroup(User $admin, array $payload)
{
    return test()->actingAs($admin)->post(route('admin.leaves.validation-groups.store'), $payload);
}

/*
|--------------------------------------------------------------------------
| Configuration du groupe
|--------------------------------------------------------------------------
*/

it('crée un groupe sans envoi par email', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser()))
        ->assertSessionHasNoErrors();

    $group = ValidationGroup::query()->firstOrFail();

    expect($group->notify_by_email)->toBeFalse()
        ->and($group->emailRecipients())->toBe([]);
});

it('refuse l\'option activée sans aucune adresse', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser(), [
        'notify_by_email' => true,
        'notification_emails' => '   ',
    ]))->assertSessionHasErrors('notification_emails');

    expect(ValidationGroup::query()->count())->toBe(0);
});

it('accepte une adresse unique', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser(), [
        'notify_by_email' => true,
        'notification_emails' => 'rh@jeudy-sa.fr',
    ]))->assertSessionHasNoErrors();

    expect(ValidationGroup::query()->firstOrFail()->emailRecipients())
        ->toBe(['rh@jeudy-sa.fr']);
});

it('accepte plusieurs adresses séparées par des virgules', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser(), [
        'notify_by_email' => true,
        'notification_emails' => 'rh@jeudy-sa.fr, comptabilite@jeudy-sa.fr ,responsable@jeudy-sa.fr',
    ]))->assertSessionHasNoErrors();

    expect(ValidationGroup::query()->firstOrFail()->emailRecipients())
        ->toBe(['rh@jeudy-sa.fr', 'comptabilite@jeudy-sa.fr', 'responsable@jeudy-sa.fr']);
});

it('refuse une adresse invalide en nommant la fautive', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser(), [
        'notify_by_email' => true,
        'notification_emails' => 'rh@jeudy-sa.fr, pas-une-adresse',
    ]))->assertSessionHasErrors(['notification_emails' => 'Cette adresse email est invalide : pas-une-adresse.']);

    expect(ValidationGroup::query()->count())->toBe(0);
});

it('dédoublonne les adresses sans tenir compte de la casse', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser(), [
        'notify_by_email' => true,
        'notification_emails' => 'rh@test.fr, RH@test.fr, compta@test.fr, rh@test.fr',
    ]))->assertSessionHasNoErrors();

    // Deux destinataires, pas quatre : la première graphie est conservée.
    expect(ValidationGroup::query()->firstOrFail()->emailRecipients())
        ->toBe(['rh@test.fr', 'compta@test.fr']);
});

it('accepte le point-virgule comme séparateur', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser(), [
        'notify_by_email' => true,
        'notification_emails' => 'rh@test.fr; compta@test.fr',
    ]))->assertSessionHasNoErrors();

    expect(ValidationGroup::query()->firstOrFail()->emailRecipients())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Modification
|--------------------------------------------------------------------------
*/

it('conserve les adresses lorsque l\'option est désactivée', function (): void {
    $admin = twoStepAdmin();
    $v1 = twoStepUser();
    $v2 = twoStepUser();

    postGroup($admin, groupPayload($v1, $v2, [
        'notify_by_email' => true,
        'notification_emails' => 'rh@test.fr, compta@test.fr',
    ]))->assertSessionHasNoErrors();

    $group = ValidationGroup::query()->firstOrFail();

    $this->actingAs($admin)
        ->put(route('admin.leaves.validation-groups.update', $group->id), groupPayload($v1, $v2, [
            'notify_by_email' => false,
            'notification_emails' => '',
        ]))
        ->assertSessionHasNoErrors();

    $group->refresh();

    // Les adresses restent en base — recocher la case suffit à les réactiver —
    // mais elles ne servent plus à rien tant que l'option est désactivée.
    expect($group->notify_by_email)->toBeFalse()
        ->and($group->notification_emails)->toBe(['rh@test.fr', 'compta@test.fr'])
        ->and($group->emailRecipients())->toBe([]);
});

it('permet de modifier, ajouter et retirer des adresses', function (): void {
    $admin = twoStepAdmin();
    $v1 = twoStepUser();
    $v2 = twoStepUser();

    postGroup($admin, groupPayload($v1, $v2, [
        'notify_by_email' => true,
        'notification_emails' => 'rh@test.fr',
    ]))->assertSessionHasNoErrors();

    $group = ValidationGroup::query()->firstOrFail();

    $this->actingAs($admin)
        ->put(route('admin.leaves.validation-groups.update', $group->id), groupPayload($v1, $v2, [
            'notify_by_email' => true,
            'notification_emails' => 'compta@test.fr, direction@test.fr',
        ]))
        ->assertSessionHasNoErrors();

    expect($group->fresh()->emailRecipients())
        ->toBe(['compta@test.fr', 'direction@test.fr']);
});

it('sert la configuration à l\'écran d\'administration', function (): void {
    $admin = twoStepAdmin();

    postGroup($admin, groupPayload(twoStepUser(), twoStepUser(), [
        'notify_by_email' => true,
        'notification_emails' => 'rh@test.fr, compta@test.fr',
    ]))->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->get(route('admin.leaves.index'))
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('validationGroups.0.notify_by_email', true)
            ->where('validationGroups.0.notification_emails', ['rh@test.fr', 'compta@test.fr'])
        );
});

/*
|--------------------------------------------------------------------------
| Congés
|--------------------------------------------------------------------------
*/

/** Groupe configuré avec des destinataires email. */
function groupWithEmails(User $v1, User $v2, array $members, array $emails, string $name = 'Atelier'): ValidationGroup
{
    $group = groupWith($v1, $v2, $members, $name);
    $group->forceFill([
        'notify_by_email' => true,
        'notification_emails' => $emails,
    ])->save();

    return $group->refresh();
}

it('envoie un email aux adresses configurées lors d\'une demande de congé', function (): void {
    Mail::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWithEmails($v1, $v2, [$requester], ['rh@test.fr', 'compta@test.fr']);

    submitLeave($requester);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, 2);
    Mail::assertQueued(
        LeaveRequestSubmittedMail::class,
        fn (LeaveRequestSubmittedMail $mail): bool => $mail->hasTo('rh@test.fr'),
    );
    Mail::assertQueued(
        LeaveRequestSubmittedMail::class,
        fn (LeaveRequestSubmittedMail $mail): bool => $mail->hasTo('compta@test.fr'),
    );
});

it('n\'expose pas les autres destinataires', function (): void {
    Mail::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWithEmails($v1, $v2, [$requester], ['rh@test.fr', 'compta@test.fr']);

    submitLeave($requester);

    // Un envoi par destinataire : personne ne voit à qui d'autre l'email part.
    Mail::assertQueued(
        LeaveRequestSubmittedMail::class,
        fn (LeaveRequestSubmittedMail $mail): bool => count($mail->to) === 1
            && $mail->cc === []
            && $mail->bcc === [],
    );
});

it('n\'envoie aucun email quand l\'option est désactivée', function (): void {
    Mail::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();

    // Adresses présentes mais option baissée.
    $group = groupWith($v1, $v2, [$requester]);
    $group->forceFill(['notify_by_email' => false, 'notification_emails' => ['rh@test.fr']])->save();

    submitLeave($requester);

    Mail::assertNothingQueued();
});

it('n\'envoie aucun email pour un demandeur sans groupe', function (): void {
    Mail::fake();

    submitLeave(twoStepUser());

    Mail::assertNothingQueued();
});

it('conserve les notifications internes des deux valideurs', function (): void {
    Mail::fake();
    Notification::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWithEmails($v1, $v2, [$requester], ['rh@test.fr']);

    submitLeave($requester);

    // L'email s'ajoute, il ne remplace rien.
    Notification::assertSentTo($v1, LeaveRequestSubmittedNotification::class);
    Notification::assertSentTo($v2, LeaveRequestSubmittedNotification::class);
});

it('détaille la demande de congé dans l\'email', function (): void {
    Mail::fake();

    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser(['first_name' => 'Nathan', 'last_name' => 'Bidet']);
    groupWithEmails($v1, $v2, [$requester], ['rh@test.fr']);

    submitLeave($requester);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->details['requester_label'] === 'Nathan Bidet'
            && $mail->details['leave_type'] === 'Congé payé'
            && $mail->details['start_at'] === '05/10/2026'
            && $mail->details['end_at'] === '06/10/2026'
            && $mail->details['group_name'] === 'Atelier';
    });
});

/*
|--------------------------------------------------------------------------
| Heures
|--------------------------------------------------------------------------
*/

it('envoie un email aux adresses configurées lors d\'une saisie d\'heures', function (): void {
    Mail::fake();

    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr', 'compta@test.fr']);

    submitHourSheet($employee, '2026-10-05');

    Mail::assertQueued(HourSheetSubmittedMail::class, 2);
});

it('détaille la journée dans l\'email', function (): void {
    Mail::fake();

    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    $employee->forceFill(['first_name' => 'Nathan', 'last_name' => 'Bidet'])->save();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr']);

    // Lundi 5 octobre, 08:00-12:00 / 14:00-18:00 : 8 h, la durée normale.
    submitHourSheet($employee, '2026-10-05');

    Mail::assertQueued(HourSheetSubmittedMail::class, function (HourSheetSubmittedMail $mail): bool {
        return $mail->details['user_label'] === 'Nathan Bidet'
            && $mail->details['work_date'] === '05/10/2026'
            && $mail->details['schedule'] === '08:00 - 12:00 / 14:00 - 18:00'
            && $mail->details['total'] === '08h00'
            // Aucun dépassement : la ligne n'apparaît pas dans l'email.
            && $mail->details['overtime'] === null
            && $mail->details['description'] === 'Travaux réalisés';
    });
});

it('annonce les heures supplémentaires au même format qu\'ailleurs', function (): void {
    Mail::fake();

    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr']);

    // Vendredi 9 octobre : 8 h travaillées pour 7 h de référence.
    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-09',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '18:00',
        'description' => 'Travaux réalisés',
    ])->assertSessionHasNoErrors();

    Mail::assertQueued(
        HourSheetSubmittedMail::class,
        fn (HourSheetSubmittedMail $mail): bool => $mail->details['overtime'] === '01h00',
    );
});

it('n\'invente pas d\'heures supplémentaires sur une journée non travaillée', function (): void {
    Mail::fake();

    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr']);

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'is_not_worked' => true,
        'description' => 'Arrêt maladie',
    ])->assertSessionHasNoErrors();

    Mail::assertQueued(HourSheetSubmittedMail::class, function (HourSheetSubmittedMail $mail): bool {
        return $mail->details['is_not_worked'] === true
            && $mail->details['overtime'] === null
            && $mail->details['schedule'] === null;
    });
});

/*
|--------------------------------------------------------------------------
| Date d'effet et rattrapage
|--------------------------------------------------------------------------
*/

it('n\'envoie aucun email pour une journée hors du nouveau système', function (): void {
    Mail::fake();

    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr']);

    app(ValidationRolloutService::class)->setEffectiveDate('2026-11-01');

    // Journée antérieure à la date d'effet : aucun circuit, donc aucun email.
    submitHourSheet($employee, '2026-10-05');

    Mail::assertNothingQueued();
});

it('n\'envoie aucun email lors du rattrapage des heures anciennes', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr']);

    $rollout = app(ValidationRolloutService::class);
    $rollout->setEffectiveDate('2026-11-01');

    submitHourSheet($employee, '2026-10-05');
    submitHourSheet($employee, '2026-10-06');

    // La date recule : les deux journées entrent d'un coup dans le circuit.
    Mail::fake();
    $rollout->setEffectiveDate('2026-10-01');
    $result = $rollout->backfillHourSheets(notify: false);

    expect($result['attached'])->toBe(2);

    // Un changement de configuration ne doit pas déclencher une salve d'emails
    // sur des journées déjà saisies : le rattrapage ne passe pas par le
    // contrôleur, seul point d'envoi.
    Mail::assertNothingQueued();
});

/*
|--------------------------------------------------------------------------
| Robustesse
|--------------------------------------------------------------------------
*/

it('enregistre la demande même si l\'envoi échoue', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser();
    groupWithEmails($v1, $v2, [$requester], ['rh@test.fr']);

    // Transport en panne : l'envoi lève, la soumission doit tenir.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP indisponible'));

    $leave = submitLeave($requester);

    expect($leave->exists)->toBeTrue()
        ->and($leave->status)->toBe(App\Support\Validation\ValidationStage::PENDING);
});

/*
|--------------------------------------------------------------------------
| Rendu réel des gabarits
|--------------------------------------------------------------------------
|
| Mail::fake() n'exécute jamais le Blade : un gabarit cassé passerait tous les
| tests ci-dessus pour n'échouer qu'au premier envoi réel. Ces tests rendent
| donc l'email pour de bon.
*/

it('rend l\'email de congé', function (): void {
    $v1 = twoStepUser();
    $v2 = twoStepUser();
    $requester = twoStepUser(['first_name' => 'Nathan', 'last_name' => 'Bidet']);
    groupWithEmails($v1, $v2, [$requester], ['rh@test.fr']);

    $leave = submitLeave($requester);
    $leave->load('leaveType');

    $html = (new LeaveRequestSubmittedMail(
        LeaveRequestSubmittedMail::detailsFor($leave, 'Nathan Bidet')
    ))->render();

    expect($html)->toContain('Nouvelle demande de congé')
        ->and($html)->toContain('Nathan Bidet')
        ->and($html)->toContain('Congé payé')
        ->and($html)->toContain('05/10/2026')
        ->and($html)->toContain('Atelier')
        ->and($html)->toContain('en attente de validation')
        // Purement informatif : aucun lien de décision.
        ->and($html)->not->toContain('leaves/approve')
        ->and($html)->not->toContain('Valider');
});

it('rend l\'email des heures, cases cochées comprises', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr']);

    // Mercredi 7 octobre, 08:00-12:00 / 14:00-19:00 : 9 h, soit 1 h de plus.
    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-07',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '19:00',
        'description' => 'Entretien du matériel',
        'has_lunch' => true,
        'has_long_night' => true,
    ])->assertSessionHasNoErrors();

    $sheet = App\Models\HourSheet::query()->whereDate('work_date', '2026-10-07')->firstOrFail();

    $html = (new HourSheetSubmittedMail(
        HourSheetSubmittedMail::detailsFor($sheet, 'Nathan Bidet')
    ))->render();

    expect($html)->toContain('Heures à valider')
        ->and($html)->toContain('07/10/2026')
        ->and($html)->toContain('08:00 - 12:00 / 14:00 - 19:00')
        ->and($html)->toContain('09h00')
        ->and($html)->toContain('01h00')
        ->and($html)->toContain('Déjeuner')
        ->and($html)->toContain('Nuit (déplacement long)')
        ->and($html)->toContain('Entretien du matériel');
});

it('rend l\'email d\'une journée non travaillée', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWithEmails($v1, $v2, [$employee], ['rh@test.fr']);

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'is_not_worked' => true,
        'description' => 'Arrêt maladie',
    ])->assertSessionHasNoErrors();

    $sheet = App\Models\HourSheet::query()->whereDate('work_date', '2026-10-05')->firstOrFail();

    $html = (new HourSheetSubmittedMail(
        HourSheetSubmittedMail::detailsFor($sheet, 'Nathan Bidet')
    ))->render();

    expect($html)->toContain('non travaillée')
        ->and($html)->toContain('Arrêt maladie')
        // Ni horaires ni total sur une journée sans travail.
        ->and($html)->not->toContain('Total travaillé');
});
