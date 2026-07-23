<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Announcement;
use App\Models\AnnouncementView;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
});

function dashboardAnnouncementFor(User $user, array $overrides = []): Announcement
{
    return Announcement::query()->create(array_merge([
        'created_by_user_id' => $user->id,
        'title' => 'Annonce de test',
        'body_html' => '<p>Contenu de test</p>',
        'status' => Announcement::STATUS_SENT,
        'sector_ids' => [],
        'user_ids' => [$user->id],
        'excluded_user_ids' => [],
        'sent_at' => now(),
        'show_on_dashboard' => true,
        'dashboard_expires_at' => null,
    ], $overrides));
}

it('exposes has_been_viewed=false for an announcement the user has never seen', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    dashboardAnnouncementFor($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.dashboard_announcement.has_been_viewed', false)
        );
});

it('exposes has_been_viewed=true once an AnnouncementView row exists for that user', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $announcement = dashboardAnnouncementFor($user);

    AnnouncementView::query()->create([
        'announcement_id' => $announcement->id,
        'user_id' => $user->id,
        'viewed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.dashboard_announcement.has_been_viewed', true)
        );
});

it('does not leak the viewed state of one user onto another', function (): void {
    $viewer = User::factory()->create(['is_active' => true]);
    $other = User::factory()->create(['is_active' => true]);
    $announcement = dashboardAnnouncementFor($viewer, ['user_ids' => [$viewer->id, $other->id]]);

    AnnouncementView::query()->create([
        'announcement_id' => $announcement->id,
        'user_id' => $viewer->id,
        'viewed_at' => now(),
    ]);

    $this->actingAs($other)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.dashboard_announcement.has_been_viewed', false)
        );
});

it('returns dashboard_announcement=null when no announcement is pinned', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.dashboard_announcement', null)
        );
});
