<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Le bouton « Envoyer » de la page Cotations ouvre la messagerie de
 * l'utilisateur (mailto: construit côté client) : aucune route d'envoi
 * serveur, et le bouton reste réservé à la permission de l'export PDF.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function sendButtonUser(string $ability): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);
    $role = Role::findOrCreate('cotation-send-'.fake()->unique()->word(), 'web');
    $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

it('réserve le bouton à la permission d\'export PDF et fournit le fuseau de l\'application', function (): void {
    $this->actingAs(sendButtonUser('cotations.cereals.edit'))->get(route('cotations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.can_manage', true)
            ->where('appTimezone', config('app.timezone'))
            ->where('routes.export_pdf', route('cotations.export-pdf')));

    $this->actingAs(sendButtonUser('cotations.cereals.view'))->get(route('cotations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions.can_manage', false));
});

it('n\'expose plus aucune route de composition ou d\'envoi d\'e-mail', function (): void {
    expect(Route::has('cotations.mail-draft'))->toBeFalse()
        ->and(Route::has('cotations.send-mail'))->toBeFalse();

    $this->actingAs(sendButtonUser('cotations.cereals.edit'))->get(route('cotations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('routes.mail_draft')
            ->missing('routes.send_mail'));
});

it('ne modifie pas l\'export PDF', function (): void {
    $this->actingAs(sendButtonUser('cotations.cereals.view'))->get(route('cotations.export-pdf'))->assertRedirect();
    $this->actingAs(sendButtonUser('cotations.cereals.edit'))->get(route('cotations.export-pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
