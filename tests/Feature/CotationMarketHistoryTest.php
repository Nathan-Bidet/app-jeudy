<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Historique des cotations de céréales = table existante cotation_market_prices
 * (un relevé par contrat et par actualisation). Aucune copie, aucune tâche du soir.
 * Règle d'une journée à plusieurs relevés : le dernier relevé de la journée par contrat.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function marketUser(array $abilities): User
{
    $sector = Sector::query()->create(['name' => fake()->unique()->company(), 'slug' => fake()->unique()->slug()]);
    $role = Role::findOrCreate('mkt-hist-'.fake()->unique()->word(), 'web');
    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/** Relevé de marché à un instant exprimé en heure de Paris (stocké dans le fuseau de l'application). */
function marketRow(string $parisDateTime, string $product = 'EBM', string $label = 'Septembre', float $price = 200.0, int $harvest = 2026): void
{
    $at = CarbonImmutable::parse($parisDateTime, 'Europe/Paris')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    $refreshId = DB::table('cotation_market_refreshes')->insertGetId([
        'source_url' => 'test', 'is_success' => true, 'row_count' => 1, 'fetched_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ]);
    DB::table('cotation_market_prices')->insert([
        'refresh_id' => $refreshId,
        'product_code' => $product,
        'product_name' => ['EBM' => 'Blé', 'EMA' => 'Maïs', 'ECO' => 'Colza'][$product],
        'product_sort' => ['ECO' => 10, 'EBM' => 20, 'EMA' => 30][$product],
        'maturity_label' => $label,
        'maturity_month' => 9,
        'maturity_year' => 2026,
        'harvest_year' => $harvest,
        'price' => $price,
        'maturity_sort' => 9,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function historyDay($test, User $user, string $date)
{
    return $test->actingAs($user)->getJson(route('cotations.cereal-history.show', $date));
}

it('liste les journées issues de l’historique existant, de la plus récente à la plus ancienne', function (): void {
    marketRow('2026-09-28 15:00:00');
    marketRow('2026-10-02 09:00:00');
    marketRow('2026-10-02 17:00:00');
    marketRow('2026-10-01 12:00:00');
    $viewer = marketUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))
        ->assertOk()
        ->assertJsonPath('dates', ['2026-10-02', '2026-10-01', '2026-09-28'])
        ->assertJsonPath('latest', '2026-10-02')
        ->assertJsonPath('oldest', '2026-09-28');
});

it('rattache les relevés tardifs à la bonne journée du fuseau métier', function (): void {
    // 00h30 Paris le 02/10 est encore le 01/10 en UTC : la journée métier est le 02/10.
    marketRow('2026-10-02 00:30:00');
    marketRow('2026-10-01 23:45:00');
    $viewer = marketUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))
        ->assertJsonPath('dates', ['2026-10-02', '2026-10-01']);
    historyDay($this, $viewer, '2026-10-02')->assertJsonPath('version.row_count', 1);
});

it('reconstitue les cotations de la journée choisie, pas celles d’un autre jour ni les valeurs actuelles', function (): void {
    marketRow('2026-09-30 15:00:00', 'EBM', 'Septembre', 180);
    marketRow('2026-10-02 15:00:00', 'EBM', 'Septembre', 230);
    marketRow('2026-09-30 15:00:00', 'EMA', 'Novembre', 170);
    $viewer = marketUser(['cotations.cereals.view']);

    $day = historyDay($this, $viewer, '2026-09-30')->assertOk()->json('version');

    expect($day['business_date'])->toBe('2026-09-30')
        ->and($day['row_count'])->toBe(2)
        ->and(collect($day['groups'])->pluck('name')->all())->toBe(['Blé', 'Maïs'])
        ->and($day['groups'][0]['harvests'][0]['year'])->toBe(2026)
        ->and($day['groups'][0]['harvests'][0]['rows'][0])->toMatchArray(['label' => 'Septembre', 'maturity_year' => 2026, 'price' => 180.0]);
});

it('applique la règle de la journée à plusieurs relevés : le dernier relevé de la journée, sans rien supprimer', function (): void {
    marketRow('2026-10-01 09:00:00', 'EBM', 'Septembre', 100);
    marketRow('2026-10-01 18:00:00', 'EBM', 'Septembre', 120);
    marketRow('2026-10-01 12:00:00', 'EBM', 'Septembre', 110);
    marketRow('2026-10-01 10:00:00', 'EBM', 'Décembre', 140); // autre contrat : un seul relevé
    $viewer = marketUser(['cotations.cereals.view']);

    $rows = historyDay($this, $viewer, '2026-10-01')->json('version.groups.0.harvests.0.rows');

    expect(collect($rows)->firstWhere('label', 'Septembre')['price'])->toEqual(120)
        ->and(collect($rows)->firstWhere('label', 'Décembre')['price'])->toEqual(140)
        ->and(DB::table('cotation_market_prices')->count())->toBe(4);
});

it('sépare les différentes récoltes et indique les données non historisées', function (): void {
    marketRow('2026-10-01 12:00:00', 'EBM', 'Septembre', 200, 2026);
    marketRow('2026-10-01 12:00:00', 'EBM', 'Septembre', 215, 2027);
    $viewer = marketUser(['cotations.cereals.view']);

    $version = historyDay($this, $viewer, '2026-10-01')->json('version');

    expect(collect($version['groups'][0]['harvests'])->pluck('year')->all())->toBe([2026, 2027])
        ->and($version['not_historized'])->toContain('Marges et prix finaux');
});

it('refuse une date sans relevé avec un message clair, et une date mal formée', function (): void {
    marketRow('2026-10-01 12:00:00');
    $viewer = marketUser(['cotations.cereals.view']);

    historyDay($this, $viewer, '2026-10-05')->assertNotFound()
        ->assertJsonPath('message', "Cette version historique n'est plus disponible.");
    $this->actingAs($viewer)->getJson('/cotations/cereal-history/abc')->assertNotFound();
});

it('respecte les permissions existantes de consultation des cotations céréales', function (): void {
    marketRow('2026-10-01 12:00:00');
    $fuelOnly = marketUser(['cotations.fuel.view']);
    $editor = marketUser(['cotations.cereals.edit']);

    $this->actingAs($fuelOnly)->getJson(route('cotations.cereal-history.dates'))->assertForbidden();
    historyDay($this, $fuelOnly, '2026-10-01')->assertForbidden();
    $this->actingAs($editor)->getJson(route('cotations.cereal-history.dates'))->assertOk();
});

it('est en lecture seule : aucune route d’écriture et l’historique n’est jamais modifié', function (): void {
    marketRow('2026-10-01 12:00:00');
    $editor = marketUser(['cotations.cereals.edit']);
    $before = DB::table('cotation_market_prices')->get()->toJson();

    foreach (['put', 'patch', 'post', 'delete'] as $method) {
        $this->actingAs($editor)->{$method.'Json'}(route('cotations.cereal-history.show', '2026-10-01'), [])->assertStatus(405);
    }
    historyDay($this, $editor, '2026-10-01')->assertOk();

    expect(DB::table('cotation_market_prices')->get()->toJson())->toBe($before);
});

it('conserve le comportement sans historique : liste vide, page chargée', function (): void {
    $viewer = marketUser(['cotations.cereals.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))->assertOk()->assertJsonPath('dates', []);
    $this->actingAs($viewer)->get(route('cotations.index'))->assertOk();
});

it('n’utilise plus de table de snapshots, de commande ni de tâche du soir', function (): void {
    expect(Schema::hasTable('cotation_cereal_snapshots'))->toBeFalse()
        ->and(array_keys(Artisan::all()))->not->toContain('cotations:snapshot-cereals');

    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");
    expect($commands)->not->toContain('snapshot-cereals')->and($commands)->toContain('cotations:refresh');
});

it('la sauvegarde des cotations ne crée aucune copie d’historique', function (): void {
    marketRow('2026-10-01 12:00:00');
    $editor = marketUser(['cotations.cereals.edit']);
    $countBefore = DB::table('cotation_market_prices')->count();

    $this->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => CotationSetting::query()->get()->map(fn ($s) => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])->all(),
        'cereal_info_html' => '<p>Texte</p>',
    ])->assertSessionHasNoErrors();

    expect(DB::table('cotation_market_prices')->count())->toBe($countBefore)
        ->and(Schema::hasTable('cotation_cereal_snapshots'))->toBeFalse();
});

it('la migration de nettoyage retire la table de snapshots sans toucher à l’historique existant', function (): void {
    marketRow('2026-10-01 12:00:00');
    Schema::create('cotation_cereal_snapshots', function ($table): void {
        $table->id();
        $table->json('payload');
    });
    $migration = require base_path('database/migrations/2026_10_03_090000_drop_cotation_cereal_snapshots_table.php');

    $migration->up();
    $migration->up(); // idempotente (table déjà absente)

    expect(Schema::hasTable('cotation_cereal_snapshots'))->toBeFalse()
        ->and(Schema::hasTable('cotation_market_prices'))->toBeTrue()
        ->and(Schema::hasTable('cotation_market_refreshes'))->toBeTrue()
        ->and(DB::table('cotation_market_prices')->count())->toBe(1);
});
