<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Announcement;
use App\Models\AnnouncementPoll;
use App\Models\AnnouncementPollOption;
use App\Models\AnnouncementPollResponse;
use App\Models\Sector;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function pollSector(): Sector
{
    return Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);
}

function pollUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'sector_id' => pollSector()->id,
        'is_active' => true,
    ], $overrides));
}

function pollAdminUser(): User
{
    $user = pollUser();
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

/**
 * @param  array<int, User>  $recipients
 */
function pollAnnouncement(User $creator, array $recipients, array $overrides = []): Announcement
{
    return Announcement::query()->create(array_merge([
        'created_by_user_id' => $creator->id,
        'title' => 'Annonce test',
        'body_html' => '<p>Contenu</p>',
        'status' => Announcement::STATUS_SENT,
        'sector_ids' => [],
        'user_ids' => array_map(fn (User $user): int => $user->id, $recipients),
        'excluded_user_ids' => [],
        'sent_at' => now(),
    ], $overrides));
}

function pollFor(Announcement $announcement, string $type = AnnouncementPoll::TYPE_SINGLE, bool $allowOther = false): AnnouncementPoll
{
    return AnnouncementPoll::query()->create([
        'announcement_id' => $announcement->id,
        'poll_type' => $type,
        'title' => 'Quelle option ?',
        'allow_other' => $allowOther,
    ]);
}

function pollOption(AnnouncementPoll $poll, string $label, int $sortOrder = 1): AnnouncementPollOption
{
    return AnnouncementPollOption::query()->create([
        'announcement_poll_id' => $poll->id,
        'label' => $label,
        'sort_order' => $sortOrder,
    ]);
}

function expectNoExisting(): array
{
    return ['expected_exists' => false];
}

// 1 + 7. Un admin répond pour un destinataire en attente -> crée sa réponse
it('lets an admin answer on behalf of a pending recipient', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$option->id],
            ...expectNoExisting(),
        ])
        ->assertRedirect();

    $response = AnnouncementPollResponse::query()
        ->where('announcement_poll_id', $poll->id)
        ->where('user_id', $recipient->id)
        ->first();

    expect($response)->not->toBeNull();
    expect($response->selected_option_ids)->toBe([$option->id]);
});

// 2. Le créateur répond pour un destinataire de son propre sondage
it('lets the announcement creator answer on behalf of their own recipient', function (): void {
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($creator)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$option->id],
            ...expectNoExisting(),
        ])
        ->assertRedirect();

    expect(AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->where('user_id', $recipient->id)->exists())
        ->toBeTrue();
});

// 3. Le créateur ne peut pas agir sur le sondage d'un autre créateur
it('forbids a creator from answering on behalf of a recipient on another creator\'s announcement', function (): void {
    $creator = pollUser();
    $otherCreator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($otherCreator, [$recipient]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($creator)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$option->id],
            ...expectNoExisting(),
        ])
        ->assertForbidden();

    expect(AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->exists())->toBeFalse();
});

// 4 + 5. Un utilisateur ordinaire (ni admin, ni créateur) ne peut pas répondre pour autrui,
// y compris via un appel direct à l'API.
it('forbids an ordinary user from answering on behalf of anyone', function (): void {
    $creator = pollUser();
    $outsider = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($outsider)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$option->id],
            ...expectNoExisting(),
        ])
        ->assertForbidden();

    expect(AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->exists())->toBeFalse();
});

it('requires authentication', function (): void {
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    pollFor($announcement);

    $this->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
        'selected_option_ids' => [],
        ...expectNoExisting(),
    ])->assertRedirect(route('login'));
});

// 6. La personne ciblée doit réellement faire partie des destinataires du sondage
it('rejects a target user who is not a recipient of the poll', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $notARecipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $notARecipient]), [
            'selected_option_ids' => [$option->id],
            ...expectNoExisting(),
        ])
        ->assertSessionHasErrors('selected_option_ids');

    expect(AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->exists())->toBeFalse();
});

// 8 + 9. Préremplissage/modification d'une réponse existante, sans doublon
it('updates an existing response instead of creating a duplicate, when the expected content matches', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $optionYes = pollOption($poll, 'Oui', 1);
    $optionNo = pollOption($poll, 'Non', 2);

    AnnouncementPollResponse::query()->create([
        'announcement_id' => $announcement->id,
        'announcement_poll_id' => $poll->id,
        'user_id' => $recipient->id,
        'selected_option_ids' => [$optionYes->id],
        'other_text' => null,
        'responded_at' => now()->subHour(),
    ]);

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$optionNo->id],
            'expected_exists' => true,
            'expected_selected_option_ids' => [$optionYes->id],
            'expected_other_text' => '',
        ])
        ->assertRedirect();

    $rows = AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->where('user_id', $recipient->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->selected_option_ids)->toBe([$optionNo->id]);
});

// 10. Sondage à choix multiples
it('supports multiple-choice polls', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement, AnnouncementPoll::TYPE_MULTIPLE);
    $optionA = pollOption($poll, 'A', 1);
    $optionB = pollOption($poll, 'B', 2);

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$optionA->id, $optionB->id],
            ...expectNoExisting(),
        ])
        ->assertRedirect();

    $response = AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->where('user_id', $recipient->id)->first();
    expect($response->selected_option_ids)->toEqualCanonicalizing([$optionA->id, $optionB->id]);
});

it('rejects more than one selected option on a single-choice poll', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement, AnnouncementPoll::TYPE_SINGLE);
    $optionA = pollOption($poll, 'A', 1);
    $optionB = pollOption($poll, 'B', 2);

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$optionA->id, $optionB->id],
            ...expectNoExisting(),
        ])
        ->assertSessionHasErrors('selected_option_ids');

    expect(AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->exists())->toBeFalse();
});

// 11. Rejet d'une option appartenant à un autre sondage
it('silently discards an option id that belongs to a different poll', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $ownOption = pollOption($poll, 'Oui');

    $otherAnnouncement = pollAnnouncement($creator, [$recipient]);
    $otherPoll = pollFor($otherAnnouncement);
    $foreignOption = pollOption($otherPoll, 'Étrangère');

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$foreignOption->id],
            ...expectNoExisting(),
        ])
        ->assertSessionHasErrors('selected_option_ids');

    expect(AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->exists())->toBeFalse();
});

// 12. Un sondage non "envoyé" (donc jamais ouvert aux réponses) reste fermé,
// y compris pour un administrateur ou le créateur — aucun contournement.
it('blocks answering on behalf of someone when the announcement is not sent', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient], ['status' => Announcement::STATUS_DRAFT, 'sent_at' => null]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$option->id],
            ...expectNoExisting(),
        ])
        ->assertNotFound();
});

// 15. Traçabilité : l'auteur réel de l'action est journalisé, le répondant
// affiché dans les résultats reste le destinataire ciblé.
it('logs the real actor in the audit trail while keeping the target user as the respondent', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$option->id],
            ...expectNoExisting(),
        ])
        ->assertRedirect();

    $response = AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->first();
    expect((int) $response->user_id)->toBe($recipient->id);

    $log = DB::table('audit_logs')
        ->where('module', 'annonces')
        ->where('action', 'create_poll_response_on_behalf')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect((int) $log->user_id)->toBe($admin->id);
    $payload = json_decode((string) $log->payload, true);
    expect((int) $payload['target_user_id'])->toBe($recipient->id);
});

// 17. Soumissions concurrentes / double clic : une réponse modifiée entre
// l'ouverture du modal et la validation n'est jamais écrasée silencieusement.
it('rejects a stale submission when the response changed since the modal was opened', function (): void {
    $admin = pollAdminUser();
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $optionYes = pollOption($poll, 'Oui', 1);
    $optionNo = pollOption($poll, 'Non', 2);

    // Quelqu'un d'autre a déjà répondu entretemps.
    AnnouncementPollResponse::query()->create([
        'announcement_id' => $announcement->id,
        'announcement_poll_id' => $poll->id,
        'user_id' => $recipient->id,
        'selected_option_ids' => [$optionYes->id],
        'other_text' => null,
        'responded_at' => now(),
    ]);

    // Le modal avait été ouvert avant cette réponse : il croit encore qu'il n'y en a pas.
    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$optionNo->id],
            ...expectNoExisting(),
        ])
        ->assertSessionHasErrors('selected_option_ids');

    $response = AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->where('user_id', $recipient->id)->first();
    expect($response->selected_option_ids)->toBe([$optionYes->id]);
});

// 18. Non-régression : la réponse normale d'un utilisateur pour lui-même continue de fonctionner.
it('still allows a recipient to answer for themselves through the normal endpoint', function (): void {
    $creator = pollUser();
    $recipient = pollUser();
    $announcement = pollAnnouncement($creator, [$recipient]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($recipient)
        ->post(route('annonces.poll-response', $announcement), [
            'selected_option_ids' => [$option->id],
        ])
        ->assertRedirect();

    $response = AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->where('user_id', $recipient->id)->first();
    expect($response)->not->toBeNull();
    expect($response->selected_option_ids)->toBe([$option->id]);
});

it('still forbids a recipient from answering for themselves if they are not an actual recipient', function (): void {
    $creator = pollUser();
    $notARecipient = pollUser();
    $announcement = pollAnnouncement($creator, [pollUser()]);
    $poll = pollFor($announcement);
    $option = pollOption($poll, 'Oui');

    $this->actingAs($notARecipient)
        ->post(route('annonces.poll-response', $announcement), [
            'selected_option_ids' => [$option->id],
        ])
        ->assertForbidden();
});
