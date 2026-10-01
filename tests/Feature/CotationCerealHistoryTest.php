<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\CotationCerealSnapshot;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use App\Services\Cotations\CotationCerealHistoryService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Historique des cotations céréales : instantanés complets, jamais recomposés.
 * Règle d'une journée à plusieurs versions : la dernière de la journée.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function histUser(array $abilities): User
{
    $sector = Sector::query()->create(['name' => fake()->unique()->company(), 'slug' => fake()->unique()->slug()]);
    $role = Role::findOrCreate('cot-hist-'.fake()->unique()->word(), 'web');
    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function histPayload(string $info, int $price = 490): array
{
    return [
        'market' => [
            'groups' => [['code' => 'EBM', 'name' => 'Blé', 'harvests' => ['left' => ['rows' => [['final_price' => $price]]]]]],
            'cereal_info_html' => $info,
            'harvest_years' => ['left' => 2026, 'right' => 2027],
            'fetched_at' => now()->toIso8601String(),
        ],
        'transport_grid' => ['title' => 'Transport'],
    ];
}

function saveHist(string $date, array $payload, string $time = '12:00:00'): ?CotationCerealSnapshot
{
    $service = app(CotationCerealHistoryService::class);

    return $service->record($payload, 'edit', null, CarbonImmutable::parse("$date $time", 'Europe/Paris'));
}

it('enregistre une version complète et conserve intégralement les anciennes valeurs', function (): void {
    $first = saveHist('2026-10-01', histPayload('Info A', 490));
    $second = saveHist('2026-10-02', histPayload('Info B', 510));

    expect($first->fresh()->payload['market']['groups'][0]['harvests']['left']['rows'][0]['final_price'])->toBe(490)
        ->and($first->fresh()->payload['market']['cereal_info_html'])->toBe('Info A')
        ->and($second->fresh()->payload['market']['cereal_info_html'])->toBe('Info B')
        ->and($first->fresh()->payload['transport_grid']['title'])->toBe('Transport');
});

it('ne crée pas de version quand seul l’horodatage d’actualisation change', function (): void {
    saveHist('2026-10-01', histPayload('Info A'));
    $payload = histPayload('Info A');
    $payload['market']['fetched_at'] = now()->addHour()->toIso8601String();

    expect(saveHist('2026-10-01', $payload, '13:00:00'))->toBeNull()
        ->and(CotationCerealSnapshot::query()->count())->toBe(1);
});

it('regroupe par journée dans le fuseau métier', function (): void {
    // 23:30 à Paris = 21:30 UTC : même journée métier que 08:00 à Paris.
    saveHist('2026-10-01', histPayload('Matin'), '08:00:00');
    saveHist('2026-10-01', histPayload('Soir'), '23:30:00');

    expect(CotationCerealSnapshot::query()->pluck('business_date')->map->toDateString()->unique()->all())->toBe(['2026-10-01']);
});

it('liste uniquement les dates possédant un historique, de la plus récente à la plus ancienne', function (): void {
    saveHist('2026-09-28', histPayload('A'));
    saveHist('2026-10-02', histPayload('B'));
    saveHist('2026-10-01', histPayload('C'));
    $viewer = histUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))
        ->assertOk()
        ->assertJsonPath('dates', ['2026-10-02', '2026-10-01', '2026-09-28'])
        ->assertJsonPath('latest', '2026-10-02')
        ->assertJsonPath('oldest', '2026-09-28');
});

it('renvoie la dernière version quand une journée en contient plusieurs, et garde les autres en base', function (): void {
    saveHist('2026-10-01', histPayload('Matin', 480), '08:00:00');
    saveHist('2026-10-01', histPayload('Soir', 520), '18:00:00');
    $viewer = histUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.show', '2026-10-01'))
        ->assertOk()
        ->assertJsonPath('version.business_date', '2026-10-01')
        ->assertJsonPath('version.market.cereal_info_html', 'Soir')
        ->assertJsonPath('version.market.groups.0.harvests.left.rows.0.final_price', 520);

    expect(CotationCerealSnapshot::query()->count())->toBe(2);
});

it('affiche la journée réellement demandée, pas l’état courant', function (): void {
    saveHist('2026-09-30', histPayload('Ancien', 450));
    saveHist('2026-10-02', histPayload('Récent', 550));
    $viewer = histUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.show', '2026-09-30'))
        ->assertJsonPath('version.market.cereal_info_html', 'Ancien')
        ->assertJsonPath('version.market.groups.0.harvests.left.rows.0.final_price', 450);
});

it('refuse une date sans historique ou supprimée entre-temps, avec un message clair', function (): void {
    $version = saveHist('2026-10-01', histPayload('A'));
    $viewer = histUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.show', '2026-10-05'))
        ->assertNotFound()->assertJsonPath('message', "Cette version historique n'est plus disponible.");

    $version->delete();
    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.show', '2026-10-01'))->assertNotFound();
    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))->assertJsonPath('dates', []);
});

it('rejette une date mal formée', function (): void {
    $viewer = histUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson('/cotations/cereal-history/pas-une-date')->assertNotFound();
});

it('respecte les permissions : consultation réservée aux droits cotations céréales', function (): void {
    saveHist('2026-10-01', histPayload('A'));
    $fuelOnly = histUser(['cotations.fuel.view']);
    $editor = histUser(['cotations.cereals.edit']);

    $this->actingAs($fuelOnly)->getJson(route('cotations.cereal-history.dates'))->assertForbidden();
    $this->actingAs($fuelOnly)->getJson(route('cotations.cereal-history.show', '2026-10-01'))->assertForbidden();
    $this->actingAs($editor)->getJson(route('cotations.cereal-history.dates'))->assertOk();
});

it('n’offre aucune route pour modifier ou écraser une version historique', function (): void {
    $version = saveHist('2026-10-01', histPayload('A'));
    $editor = histUser(['cotations.cereals.edit']);

    foreach (['put', 'patch', 'post', 'delete'] as $method) {
        $this->actingAs($editor)->{$method.'Json'}(route('cotations.cereal-history.show', '2026-10-01'), ['payload' => []])
            ->assertStatus(405);
    }

    expect($version->fresh()->payload['market']['cereal_info_html'])->toBe('A');
});

it('crée une version lors de la sauvegarde des cotations, sans écraser la précédente', function (): void {
    $editor = histUser(['cotations.cereals.edit']);
    $settings = fn () => CotationSetting::query()->get()
        ->map(fn (CotationSetting $s): array => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])->all();

    $this->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => $settings(),
        'cereal_info_html' => '<p>Premier texte</p>',
    ])->assertSessionHasNoErrors();
    $this->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => $settings(),
        'cereal_info_html' => '<p>Second texte</p>',
    ])->assertSessionHasNoErrors();

    $versions = CotationCerealSnapshot::query()->orderBy('id')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[0]->payload['market']['cereal_info_html'])->toContain('Premier texte')
        ->and($versions[1]->payload['market']['cereal_info_html'])->toContain('Second texte')
        ->and($versions[1]->source)->toBe('edit')
        ->and($versions[1]->created_by_user_id)->toBe($editor->id);

    // Une sauvegarde sans changement ne multiplie pas les versions.
    $this->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => $settings(),
        'cereal_info_html' => '<p>Second texte</p>',
    ]);
    expect(CotationCerealSnapshot::query()->count())->toBe(2);
});

it('la commande quotidienne enregistre un relevé puis ignore un contenu inchangé', function (): void {
    $this->artisan('cotations:snapshot-cereals')->assertSuccessful();
    $this->artisan('cotations:snapshot-cereals')->assertSuccessful();

    expect(CotationCerealSnapshot::query()->count())->toBe(1)
        ->and(CotationCerealSnapshot::query()->first()->source)->toBe('daily');
});

it('conserve le comportement actuel sans historique : page chargée, liste de dates vide', function (): void {
    $viewer = histUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))->assertOk()->assertJsonPath('dates', []);
    $this->actingAs($viewer)->get(route('cotations.index'))->assertOk();
});
