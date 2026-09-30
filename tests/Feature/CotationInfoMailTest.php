<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Mail\CotationInfoMail;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bouton « Envoyer » de la page Cotations : même permission que l'export PDF
 * (cotations.cereals.edit), corps issu du bloc « Information ».
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(fn () => Carbon::setTestNow());

function mailUser(array $abilities): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);
    $role = Role::findOrCreate('cotation-mail-'.fake()->unique()->word(), 'web');
    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function setCotationInfo(?string $html): void
{
    CotationSetting::query()->updateOrCreate(
        ['key' => 'cereal_info_html'],
        ['section' => 'cereal_info', 'label' => 'Information', 'note' => $html, 'sort_order' => 10],
    );
}

const RICH_INFO = '<p><span style="color: #b91c1c; font-weight: bold">Marché haussier</span></p><p><br></p><p><em style="color:#1d4ed8">Prochaine cotation lundi</em></p>';

it('expose le bouton Envoyer uniquement à qui peut exporter le PDF', function (): void {
    $this->actingAs(mailUser(['cotations.cereals.edit']))->get(route('cotations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.can_manage', true)
            ->where('routes.send_mail', route('cotations.send-mail'))
            ->where('routes.export_pdf', route('cotations.export-pdf')));

    $this->actingAs(mailUser(['cotations.cereals.view']))->get(route('cotations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions.can_manage', false));
});

it('refuse côté serveur la préparation et l\'envoi sans la permission d\'export', function (): void {
    Mail::fake();
    setCotationInfo(RICH_INFO);
    $viewer = mailUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.mail-draft'))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('cotations.send-mail'), ['to' => ['a@example.fr']])->assertForbidden();

    Mail::assertNothingSent();
});

it('prépare l\'objet avec la date du jour au fuseau de l\'application', function (): void {
    // 23h30 UTC le 29/09 = 01h30 le 30/09 à Paris.
    Carbon::setTestNow(Carbon::parse('2026-09-29 23:30:00', 'UTC'));
    setCotationInfo(RICH_INFO);

    $this->actingAs(mailUser(['cotations.cereals.edit']))->getJson(route('cotations.mail-draft'))
        ->assertOk()
        ->assertJsonPath('subject', 'Cotation du 30/09/2026')
        ->assertJsonPath('is_empty', false);
});

it('reprend le message d\'information avec sa mise en forme', function (): void {
    setCotationInfo(RICH_INFO);

    $body = $this->actingAs(mailUser(['cotations.cereals.edit']))->getJson(route('cotations.mail-draft'))
        ->json('body_html');

    expect($body)->toContain('color: #b91c1c')
        ->toContain('font-weight: bold')
        ->toContain('Marché haussier')
        ->toContain('<p><br></p>')
        ->toContain('<em style="color: #1d4ed8">Prochaine cotation lundi</em>');
    expect(strpos($body, 'Marché haussier'))->toBeLessThan(strpos($body, 'Prochaine cotation'));
});

it('assainit le contenu avant de le mettre dans le courriel', function (): void {
    Mail::fake();
    setCotationInfo('<p onclick="x()">Texte</p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">lien</a><span style="color:red;background:url(http://evil)">ok</span>');

    $this->actingAs(mailUser(['cotations.cereals.edit']))
        ->postJson(route('cotations.send-mail'), ['to' => ['a@example.fr']])->assertOk();

    Mail::assertSent(CotationInfoMail::class, function (CotationInfoMail $mail): bool {
        return ! str_contains($mail->bodyHtml, '<script')
            && ! str_contains($mail->bodyHtml, 'onclick')
            && ! str_contains($mail->bodyHtml, '<img')
            && ! str_contains($mail->bodyHtml, 'javascript:')
            && ! str_contains($mail->bodyHtml, 'url(')
            && str_contains($mail->bodyHtml, 'Texte');
    });
});

it('envoie le message aux destinataires avec l\'objet et le HTML du bloc Information', function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Europe/Paris'));
    setCotationInfo(RICH_INFO);
    $editor = mailUser(['cotations.cereals.edit']);

    $this->actingAs($editor)->postJson(route('cotations.send-mail'), [
        'to' => ['a@example.fr', 'b@example.fr'],
        'subject' => '',
    ])->assertOk()->assertJson(['ok' => true, 'sent' => 2]);

    Mail::assertSent(CotationInfoMail::class, function (CotationInfoMail $mail): bool {
        $mail->assertHasSubject('Cotation du 30/09/2026');
        $mail->assertSeeInHtml('color: #b91c1c', false);
        $mail->assertSeeInText('Marché haussier');

        return $mail->hasTo('a@example.fr') && $mail->hasTo('b@example.fr');
    });
});

it('valide les destinataires', function (array $payload): void {
    Mail::fake();
    setCotationInfo(RICH_INFO);

    $this->actingAs(mailUser(['cotations.cereals.edit']))
        ->postJson(route('cotations.send-mail'), $payload)
        ->assertUnprocessable()
        ->assertJsonStructure(['errors']);

    Mail::assertNothingSent();
})->with([
    'aucun' => [[]],
    'vide' => [['to' => []]],
    'invalide' => [['to' => ['pas-une-adresse']]],
    'doublon' => [['to' => ['a@example.fr', 'A@example.fr']]],
    'trop nombreux' => [['to' => array_map(fn ($i) => "u{$i}@example.fr", range(1, 21))]],
]);

it('refuse d\'envoyer un message d\'information vide', function (): void {
    Mail::fake();
    setCotationInfo('<p><br></p>');
    $editor = mailUser(['cotations.cereals.edit']);

    $this->actingAs($editor)->getJson(route('cotations.mail-draft'))->assertJsonPath('is_empty', true);
    $this->actingAs($editor)->postJson(route('cotations.send-mail'), ['to' => ['a@example.fr']])
        ->assertUnprocessable();

    Mail::assertNothingSent();
});

it('renvoie une erreur claire quand le service de messagerie échoue', function (): void {
    setCotationInfo(RICH_INFO);
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

    $this->actingAs(mailUser(['cotations.cereals.edit']))
        ->postJson(route('cotations.send-mail'), ['to' => ['a@example.fr']])
        ->assertStatus(502)
        ->assertJsonPath('message', "L'e-mail n'a pas pu être envoyé. Réessayez dans un instant.");
});

it('ne modifie pas l\'export PDF', function (): void {
    setCotationInfo(RICH_INFO);

    $this->actingAs(mailUser(['cotations.cereals.view']))->get(route('cotations.export-pdf'))->assertRedirect();
    $this->actingAs(mailUser(['cotations.cereals.edit']))->get(route('cotations.export-pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
