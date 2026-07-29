<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Announcement;
use App\Models\AnnouncementPoll;
use App\Models\AnnouncementPollOption;
use App\Models\AnnouncementPollResponse;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Le centre de notifications, la page d'accueil et la page détaillée des
 * annonces affichent tous le même sondage via le même AnnouncementPoll-
 * Presenter et répondent au nom d'un destinataire via le même endpoint
 * (annonces.poll-response-for) : ces tests vérifient que can_answer_for_others
 * et les résultats sont exposés de façon cohérente sur ces trois surfaces,
 * sans logique dupliquée ni permission divergente.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function surfaceSector(): Sector
{
    return Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);
}

function surfaceUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'sector_id' => surfaceSector()->id,
        'is_active' => true,
    ], $overrides));
}

function surfaceAdmin(): User
{
    $user = surfaceUser();
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

function pollAnnouncementForDashboard(User $creator, array $recipientIds, array $overrides = []): Announcement
{
    return Announcement::query()->create(array_merge([
        'created_by_user_id' => $creator->id,
        'title' => 'Sondage sécurité',
        'body_html' => '<p>Merci de répondre.</p>',
        'status' => Announcement::STATUS_SENT,
        'sector_ids' => [],
        'user_ids' => $recipientIds,
        'excluded_user_ids' => [],
        'sent_at' => now(),
        'show_on_dashboard' => true,
        'dashboard_expires_at' => null,
    ], $overrides));
}

// 8. Cohérence avec la page détaillée : la page d'accueil expose can_answer_for_others
// pour l'admin et pas pour un destinataire ordinaire, avec les mêmes listes.
it('exposes can_answer_for_others and the pending/responded lists on the dashboard for an admin', function (): void {
    $admin = surfaceAdmin();
    $creator = surfaceUser();
    $recipient = surfaceUser();
    $announcement = pollAnnouncementForDashboard($creator, [$admin->id, $recipient->id]);
    $poll = AnnouncementPoll::query()->create([
        'announcement_id' => $announcement->id,
        'poll_type' => 'single',
        'allow_other' => false,
    ]);
    AnnouncementPollOption::query()->create(['announcement_poll_id' => $poll->id, 'label' => 'Oui', 'sort_order' => 1]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.dashboard_announcement.poll.can_answer_for_others', true)
            ->where(
                'dashboard.dashboard_announcement.poll.results.pending_users',
                fn ($users) => collect($users)->pluck('id')->contains($recipient->id),
            )
            ->where('dashboard.dashboard_announcement.status', Announcement::STATUS_SENT)
        );
});

it('does not expose can_answer_for_others (nor results) on the dashboard for an ordinary recipient', function (): void {
    $creator = surfaceUser();
    $recipient = surfaceUser();
    $announcement = pollAnnouncementForDashboard($creator, [$recipient->id]);
    $poll = AnnouncementPoll::query()->create([
        'announcement_id' => $announcement->id,
        'poll_type' => 'single',
        'allow_other' => false,
    ]);
    AnnouncementPollOption::query()->create(['announcement_poll_id' => $poll->id, 'label' => 'Oui', 'sort_order' => 1]);

    $this->actingAs($recipient)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.dashboard_announcement.poll.can_answer_for_others', false)
            ->missing('dashboard.dashboard_announcement.poll.results')
        );
});

// 3. Depuis une annonce ouverte via le centre de notifications : la charge
// utile /notifications/latest expose le même can_answer_for_others.
it('exposes can_answer_for_others on the announcement notification payload for the creator', function (): void {
    $creator = surfaceUser();
    $recipient = surfaceUser();
    $announcement = pollAnnouncementForDashboard($creator, [$creator->id, $recipient->id], ['show_on_dashboard' => false]);
    $poll = AnnouncementPoll::query()->create([
        'announcement_id' => $announcement->id,
        'poll_type' => 'single',
        'allow_other' => false,
    ]);
    AnnouncementPollOption::query()->create(['announcement_poll_id' => $poll->id, 'label' => 'Oui', 'sort_order' => 1]);

    $creator->notify(new AnnouncementNotification($announcement));

    $response = $this->actingAs($creator)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notifications.0.poll.can_answer_for_others', true);

    $pendingIds = collect($response->json('notifications.0.poll.results.pending_users'))->pluck('id');
    expect($pendingIds)->toContain($recipient->id);
});

// 8 + 14. Bout en bout : une réponse enregistrée depuis l'endpoint partagé
// est immédiatement reflétée dans la charge utile de la page d'accueil
// (passage de "En attente" à "Ont répondu").
it('reflects an on-behalf answer submitted through the shared endpoint on the next dashboard load', function (): void {
    $admin = surfaceAdmin();
    $creator = surfaceUser();
    $recipient = surfaceUser();
    $announcement = pollAnnouncementForDashboard($creator, [$admin->id, $recipient->id]);
    $poll = AnnouncementPoll::query()->create([
        'announcement_id' => $announcement->id,
        'poll_type' => 'single',
        'allow_other' => false,
    ]);
    $option = AnnouncementPollOption::query()->create(['announcement_poll_id' => $poll->id, 'label' => 'Oui', 'sort_order' => 1]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'dashboard.dashboard_announcement.poll.results.pending_users',
                fn ($users) => collect($users)->pluck('id')->contains($recipient->id),
            )
            ->where(
                'dashboard.dashboard_announcement.poll.results.responded_users',
                fn ($users) => ! collect($users)->pluck('id')->contains($recipient->id),
            )
        );

    $this->actingAs($admin)
        ->post(route('annonces.poll-response-for', [$announcement, $recipient]), [
            'selected_option_ids' => [$option->id],
            'expected_exists' => false,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'dashboard.dashboard_announcement.poll.results.pending_users',
                fn ($users) => ! collect($users)->pluck('id')->contains($recipient->id),
            )
            ->where(
                'dashboard.dashboard_announcement.poll.results.responded_users',
                fn ($users) => collect($users)->pluck('id')->contains($recipient->id),
            )
        );

    expect(AnnouncementPollResponse::query()->where('announcement_poll_id', $poll->id)->count())->toBe(1);
});
