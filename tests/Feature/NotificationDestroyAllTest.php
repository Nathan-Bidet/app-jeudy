<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
});

function notificationUser(): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    return User::factory()->create([
        'sector_id' => $sector->id,
        'is_active' => true,
    ]);
}

function insertNotification(User $user, ?string $readAt): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['message' => 'Test']),
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

// 5 + 8. Supprime toutes les notifications lues de l'utilisateur, y compris
// au-delà des 10 dernières affichées par le centre de notifications.
it('deletes all read notifications of the authenticated user, beyond the displayed limit', function (): void {
    $user = notificationUser();

    for ($i = 0; $i < 15; $i++) {
        insertNotification($user, now()->toDateTimeString());
    }

    expect($user->notifications()->count())->toBe(15);

    $this->actingAs($user)
        ->delete(route('notifications.destroy_all'))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(0);
});

// Préserve les notifications non lues (comportement sûr si une nouvelle
// notification arrive pendant l'opération : elle est forcément non lue).
it('keeps unread notifications untouched', function (): void {
    $user = notificationUser();

    insertNotification($user, now()->toDateTimeString());
    insertNotification($user, now()->toDateTimeString());
    $unreadId = insertNotification($user, null);

    $this->actingAs($user)
        ->delete(route('notifications.destroy_all'))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(1);
    expect($user->unreadNotifications()->count())->toBe(1);
    expect($user->notifications()->whereKey($unreadId)->exists())->toBeTrue();
});

// 7. Un utilisateur ne peut jamais supprimer les notifications d'un autre.
it('never deletes another user\'s notifications', function (): void {
    $user = notificationUser();
    $other = notificationUser();

    insertNotification($user, now()->toDateTimeString());
    insertNotification($other, now()->toDateTimeString());
    insertNotification($other, now()->toDateTimeString());

    $this->actingAs($user)
        ->delete(route('notifications.destroy_all'))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(0);
    expect($other->notifications()->count())->toBe(2);
});

// Rien à supprimer : ne provoque pas d'erreur, réponse toujours réussie.
it('succeeds without error when there is nothing to delete', function (): void {
    $user = notificationUser();

    $this->actingAs($user)
        ->delete(route('notifications.destroy_all'))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(0);
});

// 10. Le compteur non-lu (badge) reste cohérent après l'opération.
it('leaves the unread counter accurate after deleting all read notifications', function (): void {
    $user = notificationUser();

    insertNotification($user, now()->toDateTimeString());
    insertNotification($user, null);
    insertNotification($user, null);

    $this->actingAs($user)
        ->delete(route('notifications.destroy_all'))
        ->assertRedirect();

    $this->actingAs($user)
        ->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJson(['unread_count' => 2]);
});

// 12. Non-régression : la suppression individuelle continue de fonctionner
// et reste limitée aux notifications déjà lues de l'utilisateur connecté.
it('still allows deleting a single read notification without affecting the rest', function (): void {
    $user = notificationUser();
    $keepId = insertNotification($user, now()->toDateTimeString());
    $deleteId = insertNotification($user, now()->toDateTimeString());

    $this->actingAs($user)
        ->delete(route('notifications.destroy', $deleteId))
        ->assertRedirect();

    expect($user->notifications()->whereKey($deleteId)->exists())->toBeFalse();
    expect($user->notifications()->whereKey($keepId)->exists())->toBeTrue();
});

it('still forbids deleting an unread notification individually', function (): void {
    $user = notificationUser();
    $unreadId = insertNotification($user, null);

    $this->actingAs($user)
        ->delete(route('notifications.destroy', $unreadId))
        ->assertStatus(409);

    expect($user->notifications()->whereKey($unreadId)->exists())->toBeTrue();
});

// 12. Non-régression : "Tout marquer comme lu" continue de fonctionner.
it('still marks all notifications as read without deleting them', function (): void {
    $user = notificationUser();
    insertNotification($user, null);
    insertNotification($user, null);

    $this->actingAs($user)
        ->post(route('notifications.read_all'))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(2);
    expect($user->unreadNotifications()->count())->toBe(0);
});

it('requires authentication to delete all notifications', function (): void {
    $this->delete(route('notifications.destroy_all'))
        ->assertRedirect(route('login'));
});
