<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\CotationManualPrice;
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

function parisToStorage(string $parisDateTime): string
{
    return CarbonImmutable::parse($parisDateTime, 'Europe/Paris')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
}

/** Crée une actualisation (relevé) à une heure de Paris et renvoie son id. */
function marketRefresh(string $parisDateTime): int
{
    $at = parisToStorage($parisDateTime);

    return DB::table('cotation_market_refreshes')->insertGetId([
        'source_url' => 'test', 'is_success' => true, 'row_count' => 1, 'fetched_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

/** Cours MATIF d'un contrat dans une actualisation donnée. */
function marketPrice(int $refreshId, string $parisDateTime, string $product = 'EBM', string $label = 'Septembre', float $price = 200.0, int $harvest = 2026, int $maturityYear = 2026, int $month = 9): void
{
    $at = parisToStorage($parisDateTime);
    DB::table('cotation_market_prices')->insert([
        'refresh_id' => $refreshId,
        'product_code' => $product,
        'product_name' => ['EBM' => 'Blé', 'EMA' => 'Maïs', 'ECO' => 'Colza'][$product],
        'product_sort' => ['ECO' => 10, 'EBM' => 20, 'EMA' => 30][$product],
        'maturity_label' => $label,
        'maturity_month' => $month,
        'maturity_year' => $maturityYear,
        'harvest_year' => $harvest,
        'price' => $price,
        'maturity_sort' => $month,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/** Relevé complet à un instant : une actualisation et un cours. */
function marketRow(string $parisDateTime, string $product = 'EBM', string $label = 'Septembre', float $price = 200.0, int $harvest = 2026): void
{
    marketPrice(marketRefresh($parisDateTime), $parisDateTime, $product, $label, $price, $harvest);
}

function setHarvestYears(int $left = 2026, int $right = 2027): void
{
    foreach (['harvest_left_year' => $left, 'harvest_right_year' => $right] as $key => $value) {
        CotationSetting::query()->updateOrCreate(['key' => $key], ['section' => 'display', 'label' => $key, 'value' => $value, 'sort_order' => 1]);
    }
}

/** Ligne de cotation actuelle (liée au cours MATIF de Blé Septembre 2026). */
function manualLine(array $overrides = []): CotationManualPrice
{
    $hash = CotationManualPrice::identityHash('EBM', 2026, 2026, 9, 'Septembre');

    // Ligne ancienne (créée avant les relevés testés), sauf indication contraire.
    $createdAt = $overrides['created_at'] ?? '2026-06-20 10:00:00';
    unset($overrides['created_at']);

    $line = CotationManualPrice::query()->create(array_merge([
        'identity_hash' => $hash,
        'market_identity_hash' => $hash,
        'line_type' => 'matif',
        'product_code' => 'EBM',
        'product_name' => 'Blé',
        'product_sort' => 20,
        'maturity_label' => 'Septembre',
        'maturity_month' => 9,
        'maturity_year' => 2026,
        'harvest_year' => 2026,
        'base' => 20,
        'base_operation' => 'subtract',
        'sort_order' => 1,
    ], $overrides));
    DB::table('cotation_manual_prices')->where('id', $line->id)->update(['created_at' => $createdAt]);

    return $line->refresh();
}

function snapshotOf(CotationManualPrice $line, array $overrides = []): array
{
    return array_merge([
        'identity_hash' => $line->identity_hash,
        'market_identity_hash' => $line->market_identity_hash,
        'line_type' => 'matif',
        'product_code' => 'EBM',
        'product_name' => 'Blé',
        'display_label' => null,
        'maturity_label' => 'Septembre',
        'maturity_month' => 9,
        'maturity_year' => 2026,
        'harvest_year' => 2026,
        'manual_matif' => null,
        'final_price_reference_key' => null,
        'base' => 20.0,
        'base_operation' => 'subtract',
        'sort_order' => 1,
    ], $overrides);
}

/** Entrée du journal d'audit à un instant de Paris. */
function auditEntry(string $parisDateTime, array $payload, string $action = 'update_cotation_settings_and_manual_prices'): void
{
    $at = parisToStorage($parisDateTime);
    DB::table('audit_logs')->insert([
        'action' => $action, 'module' => 'cotations', 'payload' => json_encode($payload), 'created_at' => $at,
    ]);
}

function historyDay($test, User $user, string $date)
{
    return $test->actingAs($user)->getJson(route('cotations.cereal-history.show', $date));
}

it('liste uniquement les journées avec un relevé de 15 h exploitable, de la plus récente à la plus ancienne', function (): void {
    marketRow('2026-09-28 15:00:04');
    marketRow('2026-10-02 15:00:03');
    marketRow('2026-10-02 23:59:05');
    marketRow('2026-10-01 15:04:00');          // dans la tolérance (10 min)
    marketRow('2026-09-30 23:59:05');          // seulement 23 h 59 : pas de relevé de 15 h
    marketRow('2026-09-29 15:20:00');          // hors tolérance
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))
        ->assertOk()
        ->assertJsonPath('dates', ['2026-10-02', '2026-10-01', '2026-09-28'])
        ->assertJsonPath('latest', '2026-10-02')
        ->assertJsonPath('oldest', '2026-09-28');
});

it('retient le relevé de 15 h et jamais celui de 23 h 59, avec toutes les lignes du même relevé', function (): void {
    setHarvestYears();
    manualLine(['base' => 20]);
    $fifteen = marketRefresh('2026-10-01 15:00:04');
    marketPrice($fifteen, '2026-10-01 15:00:04', 'EBM', 'Septembre', 210);
    $late = marketRefresh('2026-10-01 23:59:05');
    marketPrice($late, '2026-10-01 23:59:05', 'EBM', 'Septembre', 250);
    marketPrice($late, '2026-10-01 23:59:05', 'EBM', 'Décembre', 260, 2026, 2026, 12); // absent du relevé de 15 h
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $version = historyDay($this, $viewer, '2026-10-01')->assertOk()->json('version');
    $rows = $version['market']['groups'][0]['harvests']['left']['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['matif'])->toEqual(210)
        ->and($rows[0]['final_price'])->toEqual(190)
        ->and($version['recorded_at'])->toStartWith('2026-10-01T15:00:04')
        ->and($version['target_time'])->toBe('15:00');
});

it('choisit le relevé le plus proche de 15 h s’il n’y en a pas à 15 h précises', function (): void {
    setHarvestYears();
    manualLine();
    foreach (['14:57:00' => 100, '15:02:00' => 120, '15:30:00' => 140] as $time => $price) {
        $id = marketRefresh("2026-10-01 $time");
        marketPrice($id, "2026-10-01 $time", 'EBM', 'Septembre', $price);
    }
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    // 14:57 est à 3 min, 15:02 à 2 min : c'est 15:02.
    expect(historyDay($this, $viewer, '2026-10-01')->json('version.market.groups.0.harvests.left.rows.0.matif'))->toEqual(120);
});

it('affiche toutes les colonnes de la vue d’origine : échéance, MATIF, base, prix final', function (): void {
    setHarvestYears();
    manualLine(['base' => 15, 'base_operation' => 'add', 'display_label' => 'Livraison port']);
    marketRow('2026-10-01 15:00:04', 'EBM', 'Septembre', 200);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $row = historyDay($this, $viewer, '2026-10-01')->json('version.market.groups.0.harvests.left.rows.0');

    expect($row)->toMatchArray([
        'display_label' => 'Livraison port',
        'label' => 'Septembre',
        'maturity_year' => 2026,
        'matif' => 200,
        'base' => 15,
        'base_operation' => 'add',
        'final_price' => 215,
    ]);
});

it('restitue la base telle qu’elle était à la date, même modifiée depuis', function (): void {
    setHarvestYears();
    $line = manualLine(['base' => 30]);                          // base actuelle : 30
    marketRow('2026-10-01 15:00:04', 'EBM', 'Septembre', 200);
    // Le 03/10 la base est passée de 10 à 30 (après le relevé du 01/10).
    auditEntry('2026-10-03 09:00:00', ['manual_prices' => [['before' => snapshotOf($line, ['base' => 10.0]), 'after' => snapshotOf($line, ['base' => 30.0])]]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $row = historyDay($this, $viewer, '2026-10-01')->json('version.market.groups.0.harvests.left.rows.0');

    expect($row['base'])->toEqual(10)->and($row['final_price'])->toEqual(190);
});

it('n’applique pas à une journée une base modifiée avant son relevé', function (): void {
    setHarvestYears();
    $line = manualLine(['base' => 30]);
    marketRow('2026-10-04 15:00:04', 'EBM', 'Septembre', 200);
    auditEntry('2026-10-03 09:00:00', ['manual_prices' => [['before' => snapshotOf($line, ['base' => 10.0]), 'after' => snapshotOf($line, ['base' => 30.0])]]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    expect(historyDay($this, $viewer, '2026-10-04')->json('version.market.groups.0.harvests.left.rows.0.base'))->toEqual(30);
});

it('ignore une ligne actuelle jamais journalisée mais créée après la date', function (): void {
    setHarvestYears();
    manualLine(['created_at' => '2026-10-04 10:00:00']);
    marketRow('2026-10-01 15:00:04');
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    expect(historyDay($this, $viewer, '2026-10-01')->json('version.market.groups'))->toBe([]);
});

it('retire les lignes créées plus tard et rétablit celles supprimées depuis', function (): void {
    setHarvestYears();
    $created = manualLine(['base' => 20]);
    $deletedHash = CotationManualPrice::identityHash('EBM', 2026, 2026, 12, 'Décembre');
    $deleted = snapshotOf($created, ['identity_hash' => $deletedHash, 'market_identity_hash' => $deletedHash, 'maturity_label' => 'Décembre', 'maturity_month' => 12, 'base' => 5.0]);
    $refresh = marketRefresh('2026-10-01 15:00:04');
    marketPrice($refresh, '2026-10-01 15:00:04', 'EBM', 'Septembre', 200);
    marketPrice($refresh, '2026-10-01 15:00:04', 'EBM', 'Décembre', 220, 2026, 2026, 12);
    // Septembre créée après le relevé ; Décembre supprimée après le relevé.
    auditEntry('2026-10-02 10:00:00', ['manual_prices' => [['before' => null, 'after' => snapshotOf($created)]], 'deleted_manual_prices' => [$deleted]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $rows = historyDay($this, $viewer, '2026-10-01')->json('version.market.groups.0.harvests.left.rows');

    expect(collect($rows)->pluck('label')->all())->toBe(['Décembre']);
    expect($rows[0]['final_price'])->toEqual(215);
});

it('restitue les textes, libellés, ordre et grille de transport d’alors', function (): void {
    setHarvestYears();
    manualLine();
    marketRow('2026-10-01 15:00:04');
    auditEntry('2026-10-03 09:00:00', [
        'cereal_info' => ['before' => '<p>Ancien texte</p>', 'after' => '<p>Nouveau</p>'],
        'cereal_labels' => ['before' => ['EBM' => 'Blé meunier'], 'after' => ['EBM' => 'Blé']],
        'cereal_table_labels' => ['before' => ['EBM' => ['base' => 'Marge d’alors']], 'after' => []],
        'transport_grid' => ['before' => ['title' => 'Transport d’alors', 'sections' => []], 'after' => ['title' => 'Transport actuel', 'sections' => []]],
    ]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $version = historyDay($this, $viewer, '2026-10-01')->json('version');

    expect($version['market']['cereal_info_html'])->toContain('Ancien texte')
        ->and($version['market']['groups'][0]['name'])->toBe('Blé meunier')
        ->and($version['market']['cereal_table_labels']['EBM']['base'])->toBe('Marge d’alors')
        ->and($version['transport_grid']['title'])->toBe('Transport d’alors');
});

it('signale un texte d’information non fiable (journal tronqué) au lieu de l’inventer', function (): void {
    setHarvestYears();
    manualLine();
    marketRow('2026-10-01 15:00:04');
    auditEntry('2026-10-03 09:00:00', ['cereal_info' => ['before' => str_repeat('a', 4000), 'after' => '<p>x</p>']]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $version = historyDay($this, $viewer, '2026-10-01')->json('version');

    expect($version['market']['cereal_info_html'])->toBe('')
        ->and($version['warnings'][0])->toContain('4 000');
});

it('exclut du calendrier les journées antérieures au début du journal des réglages', function (): void {
    marketRow('2026-06-10 15:00:04');
    marketRow('2026-09-01 15:00:04');
    auditEntry('2026-06-18 11:00:00', ['before' => [], 'after' => []], 'update_cotation_settings');
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $this->actingAs($viewer)->getJson(route('cotations.cereal-history.dates'))->assertJsonPath('dates', ['2026-09-01']);
});

it('répond clairement pour une journée sans relevé de 15 h ou sans relevé du tout', function (): void {
    marketRow('2026-10-01 23:59:05');
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    historyDay($this, $viewer, '2026-10-01')->assertNotFound()
        ->assertJsonPath('status', 'no_target')
        ->assertJsonPath('message', "Le relevé de 15 h n'est pas disponible pour cette journée.");
    historyDay($this, $viewer, '2026-10-05')->assertNotFound()
        ->assertJsonPath('status', 'none')
        ->assertJsonPath('message', "Cette version historique n'est plus disponible.");
});

it('rejette une date mal formée', function (): void {
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $this->actingAs($viewer)->getJson('/cotations/cereal-history/abc')->assertNotFound();
});

it('exige la permission dédiée à l’historique des céréales, y compris pour un éditeur', function (): void {
    marketRow('2026-10-01 15:00:04');
    $withoutHistory = marketUser(['cotations.cereals.view', 'cotations.cereals.edit']);

    $this->actingAs($withoutHistory)->getJson(route('cotations.cereal-history.dates'))->assertForbidden();
    $this->actingAs($withoutHistory)->getJson(route('cotations.cereal-history.series', ['from' => '2026-10-01', 'to' => '2026-10-01']))->assertForbidden();
    historyDay($this, $withoutHistory, '2026-10-01')->assertForbidden();
});

it('respecte les permissions existantes de consultation des cotations céréales', function (): void {
    marketRow('2026-10-01 15:00:04');
    $fuelOnly = marketUser(['cotations.fuel.view']);
    $editor = marketUser(['cotations.cereals.edit', 'cotations.cereals.history.view']);

    $this->actingAs($fuelOnly)->getJson(route('cotations.cereal-history.dates'))->assertForbidden();
    historyDay($this, $fuelOnly, '2026-10-01')->assertForbidden();
    $this->actingAs($editor)->getJson(route('cotations.cereal-history.dates'))->assertOk();
});

it('est en lecture seule : aucune route d’écriture et l’historique n’est jamais modifié', function (): void {
    marketRow('2026-10-01 15:00:04');
    $editor = marketUser(['cotations.cereals.edit', 'cotations.cereals.history.view']);
    $before = DB::table('cotation_market_prices')->get()->toJson();

    foreach (['put', 'patch', 'post', 'delete'] as $method) {
        $this->actingAs($editor)->{$method.'Json'}(route('cotations.cereal-history.show', '2026-10-01'), [])->assertStatus(405);
    }
    historyDay($this, $editor, '2026-10-01')->assertOk();

    expect(DB::table('cotation_market_prices')->get()->toJson())->toBe($before);
});

it('conserve le comportement sans historique : liste vide, page chargée', function (): void {
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

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
    marketRow('2026-10-01 15:00:04');
    $editor = marketUser(['cotations.cereals.edit', 'cotations.cereals.history.view']);
    $countBefore = DB::table('cotation_market_prices')->count();

    $this->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => CotationSetting::query()->get()->map(fn ($s) => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])->all(),
        'cereal_info_html' => '<p>Texte</p>',
    ])->assertSessionHasNoErrors();

    expect(DB::table('cotation_market_prices')->count())->toBe($countBefore)
        ->and(Schema::hasTable('cotation_cereal_snapshots'))->toBeFalse();
});

it('la migration de nettoyage retire la table de snapshots sans toucher à l’historique existant', function (): void {
    marketRow('2026-10-01 15:00:04');
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

it('donne à chaque journée ses propres cours MATIF par échéance et sa base, sans réutiliser un autre jour', function (): void {
    setHarvestYears();
    $nov = manualLine(['maturity_label' => 'Novembre', 'maturity_month' => 11, 'base' => 10, 'base_operation' => 'add', 'product_code' => 'ECO', 'product_name' => 'Colza']);
    $novHash = CotationManualPrice::identityHash('ECO', 2026, 2026, 11, 'Novembre');
    $nov->update(['identity_hash' => $novHash, 'market_identity_hash' => $novHash]);
    $feb = manualLine(['maturity_label' => 'Février', 'maturity_month' => 2, 'maturity_year' => 2027, 'base' => 5, 'base_operation' => 'add', 'product_code' => 'ECO', 'product_name' => 'Colza', 'sort_order' => 2]);
    $febHash = CotationManualPrice::identityHash('ECO', 2026, 2027, 2, 'Février');
    $feb->update(['identity_hash' => $febHash, 'market_identity_hash' => $febHash]);

    foreach ([['2026-08-12', 500.0, 520.0], ['2026-09-30', 538.75, 555.0]] as [$day, $novPrice, $febPrice]) {
        $refresh = marketRefresh("$day 15:00:03");
        marketPrice($refresh, "$day 15:00:03", 'ECO', 'Novembre', $novPrice, 2026, 2026, 11);
        marketPrice($refresh, "$day 15:00:03", 'ECO', 'Février', $febPrice, 2026, 2027, 2);
        // Relevé d'un autre horaire, au cours différent : ne doit jamais être utilisé.
        $late = marketRefresh("$day 23:59:05");
        marketPrice($late, "$day 23:59:05", 'ECO', 'Novembre', 999, 2026, 2026, 11);
    }
    // La base passe de −10/−5 à +10/+5 entre les deux journées (modification du 30/09 matin).
    auditEntry('2026-09-30 09:41:32', ['manual_prices' => [
        ['before' => snapshotOf($nov, ['identity_hash' => $novHash, 'market_identity_hash' => $novHash, 'product_code' => 'ECO', 'product_name' => 'Colza', 'maturity_label' => 'Novembre', 'maturity_month' => 11, 'base' => 10.0, 'base_operation' => 'subtract']),
         'after' => snapshotOf($nov, ['identity_hash' => $novHash, 'market_identity_hash' => $novHash, 'product_code' => 'ECO', 'product_name' => 'Colza', 'maturity_label' => 'Novembre', 'maturity_month' => 11, 'base' => 10.0, 'base_operation' => 'add'])],
        ['before' => snapshotOf($feb, ['identity_hash' => $febHash, 'market_identity_hash' => $febHash, 'product_code' => 'ECO', 'product_name' => 'Colza', 'maturity_label' => 'Février', 'maturity_month' => 2, 'maturity_year' => 2027, 'base' => 5.0, 'base_operation' => 'subtract']),
         'after' => snapshotOf($feb, ['identity_hash' => $febHash, 'market_identity_hash' => $febHash, 'product_code' => 'ECO', 'product_name' => 'Colza', 'maturity_label' => 'Février', 'maturity_month' => 2, 'maturity_year' => 2027, 'base' => 5.0, 'base_operation' => 'add'])],
    ]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $rowsOf = fn (string $day) => collect(historyDay($this, $viewer, $day)->assertOk()->json('version.market.groups.0.harvests.left.rows'))->keyBy('label');
    $aug = $rowsOf('2026-08-12');
    $sep = $rowsOf('2026-09-30');

    expect($aug['Novembre']['matif'])->toEqual(500)->and($aug['Novembre']['base_operation'])->toBe('subtract')->and($aug['Novembre']['final_price'])->toEqual(490)
        ->and($aug['Février']['matif'])->toEqual(520)->and($aug['Février']['final_price'])->toEqual(515)
        ->and($sep['Novembre']['matif'])->toEqual(538.75)->and($sep['Novembre']['base_operation'])->toBe('add')->and($sep['Novembre']['final_price'])->toEqual(548.75)
        ->and($sep['Février']['matif'])->toEqual(555)->and($sep['Février']['final_price'])->toEqual(560);
});

it('reprend le dernier cours d’une échéance échue conservée, avec la base de la date, et l’indique', function (): void {
    setHarvestYears();
    $augHash = CotationManualPrice::identityHash('ECO', 2026, 2026, 8, 'Août');
    $line = manualLine(['identity_hash' => $augHash, 'market_identity_hash' => $augHash, 'maturity_label' => 'Août', 'maturity_month' => 8, 'product_code' => 'ECO', 'product_name' => 'Colza', 'display_label' => 'Moisson', 'base' => 17, 'base_operation' => 'add']);
    $snap = fn (float $margin, string $op) => snapshotOf($line, ['identity_hash' => $augHash, 'market_identity_hash' => $augHash, 'maturity_label' => 'Août', 'maturity_month' => 8, 'product_code' => 'ECO', 'product_name' => 'Colza', 'display_label' => 'Moisson', 'base' => $margin, 'base_operation' => $op]);
    // Dernier cours du contrat échu : 30/07 à 15:00:05 (490). La base passe de −17 à +17 le 30/09 (après).
    $expiry = marketRefresh('2026-07-30 15:00:05');
    marketPrice($expiry, '2026-07-30 15:00:05', 'ECO', 'Août', 490, 2026, 2026, 8);
    foreach (['2026-08-12', '2026-09-30', '2026-10-05'] as $day) {
        marketPrice(marketRefresh("$day 15:00:03"), "$day 15:00:03", 'ECO', 'Novembre', 540, 2026, 2026, 11); // relevé de la journée, sans le contrat échu
    }
    auditEntry('2026-09-30 09:41:32', ['manual_prices' => [['before' => $snap(17, 'subtract'), 'after' => $snap(17, 'add')]]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);
    $rowOf = fn (string $day) => collect(historyDay($this, $viewer, $day)->assertOk()->json('version.market.groups.0.harvests.left.rows'))->firstWhere('display_label', 'Moisson');

    $aug = $rowOf('2026-08-12');   // base d'alors : −17
    $sep = $rowOf('2026-09-30');   // base d'alors : +17 (modifiée à 09:41, avant le relevé de 15 h)
    $oct = $rowOf('2026-10-05');

    // Même dernier Matif (490) aux trois dates, mais le prix final suit la base de chaque date.
    expect($aug['matif'])->toEqual(490)->and($aug['base_operation'])->toBe('subtract')->and($aug['final_price'])->toEqual(473)
        ->and($sep['matif'])->toEqual(490)->and($sep['base_operation'])->toBe('add')->and($sep['final_price'])->toEqual(507)
        ->and($oct['final_price'])->toEqual(507)
        ->and($aug['carried_quote_at'])->toStartWith('2026-07-30T15:00:05')
        ->and(implode(' ', historyDay($this, $viewer, '2026-10-05')->json('version.warnings')))
            ->toContain('reprennent le dernier cours connu d\'une échéance échue')->not->toContain('Pas de cours MATIF');
});

it('ne reporte jamais un cours postérieur à la journée, ni sur un contrat encore actif, ni d’un autre contrat', function (): void {
    setHarvestYears();
    $hash = CotationManualPrice::identityHash('ECO', 2026, 2026, 8, 'Août');
    manualLine(['identity_hash' => $hash, 'market_identity_hash' => $hash, 'maturity_label' => 'Août', 'maturity_month' => 8, 'product_code' => 'ECO', 'product_name' => 'Colza', 'display_label' => 'Moisson']);
    // Contrat coté le 01/10 (100) puis de nouveau le 06/10 (200) : il n'a PAS de cours les 02, 03, 04 et 05/10.
    marketPrice(marketRefresh('2026-10-01 15:00:03'), '2026-10-01 15:00:03', 'ECO', 'Août', 100, 2026, 2026, 8);
    marketPrice(marketRefresh('2026-10-06 15:00:03'), '2026-10-06 15:00:03', 'ECO', 'Août', 200, 2026, 2026, 8);
    // Un AUTRE contrat du même produit et de la même échéance, d'une autre année (ne doit pas être repris).
    marketPrice(marketRefresh('2026-10-03 15:00:03'), '2026-10-03 15:00:03', 'ECO', 'Août', 999, 2027, 2027, 8);
    marketRow('2026-10-03 15:00:03', 'EMA', 'Novembre', 150);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $version = historyDay($this, $viewer, '2026-10-03')->assertOk()->json('version');
    $row = collect($version['market']['groups'][0]['harvests']['left']['rows'] ?? [])->firstWhere('display_label', 'Moisson');

    // Contrat actif (des cours existent après le 03/10) : anomalie de collecte, aucun report (ni 100, ni 200, ni 999).
    expect($row['matif'])->toBeNull()->and($row['final_price'])->toBeNull()->and($row)->not->toHaveKey('carried_quote_at')
        ->and(implode(' ', $version['warnings']))->toContain('Pas de cours MATIF dans le relevé de 15 h')->toContain('Moisson');
});

it('respecte la frontière de la journée à Paris : un cours à 00h30 le jour consulté n’est pas « antérieur »', function (): void {
    setHarvestYears();
    $hash = CotationManualPrice::identityHash('ECO', 2026, 2026, 8, 'Août');
    manualLine(['identity_hash' => $hash, 'market_identity_hash' => $hash, 'maturity_label' => 'Août', 'maturity_month' => 8, 'product_code' => 'ECO', 'product_name' => 'Colza', 'display_label' => 'Moisson']);
    // Dernier cours à 00h30 (Paris) le 02/10 = 22h30 UTC le 01/10 : appartient à la journée du 02/10.
    marketPrice(marketRefresh('2026-10-02 00:30:00'), '2026-10-02 00:30:00', 'ECO', 'Août', 300, 2026, 2026, 8);
    foreach (['2026-10-02', '2026-10-03'] as $day) {
        marketPrice(marketRefresh("$day 15:00:03"), "$day 15:00:03", 'ECO', 'Novembre', 540, 2026, 2026, 11);
    }
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);
    $rowOf = fn (string $day) => collect(historyDay($this, $viewer, $day)->json('version.market.groups.0.harvests.left.rows'))->firstWhere('display_label', 'Moisson');

    expect($rowOf('2026-10-02')['matif'])->toBeNull()                     // cours le jour même (hors relevé de 15 h) : non reporté
        ->and($rowOf('2026-10-03')['matif'])->toEqual(300)                // lendemain : contrat échu, dernier cours repris
        ->and($rowOf('2026-10-03')['carried_quote_at'])->toStartWith('2026-10-02T00:30:00');
});

it('n’invente rien quand aucun cours n’a jamais existé pour le contrat', function (): void {
    setHarvestYears();
    $hash = CotationManualPrice::identityHash('ECO', 2026, 2026, 8, 'Août');
    manualLine(['identity_hash' => $hash, 'market_identity_hash' => $hash, 'maturity_label' => 'Août', 'maturity_month' => 8, 'product_code' => 'ECO', 'product_name' => 'Colza', 'display_label' => 'Moisson']);
    marketPrice(marketRefresh('2026-10-03 15:00:03'), '2026-10-03 15:00:03', 'ECO', 'Novembre', 540, 2026, 2026, 11);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $version = historyDay($this, $viewer, '2026-10-03')->json('version');
    $row = collect($version['market']['groups'][0]['harvests']['left']['rows'])->firstWhere('display_label', 'Moisson');

    expect($row['matif'])->toBeNull()
        ->and(implode(' ', $version['warnings']))->toContain("Moisson) : aucun cours enregistré pour ce contrat dans l'historique");
});

it('explique les absences restantes et reporte les contrats échus (y compris céréales personnalisées et lignes liées)', function (): void {
    setHarvestYears();
    // Blé Septembre : dernier cours le 10/09 à 01:59, plus rien ensuite (échu).
    $septHash = CotationManualPrice::identityHash('EBM', 2026, 2026, 9, 'Septembre');
    manualLine(['display_label' => 'Moisson']);
    // Orge adossée au MÊME contrat.
    manualLine(['identity_hash' => sha1('orge'), 'market_identity_hash' => $septHash, 'product_code' => 'CMQJN92JY', 'product_name' => 'Orge', 'display_label' => 'Moisson orge', 'sort_order' => 2]);
    // Ligne libre liée au prix final de « Moisson ».
    manualLine(['identity_hash' => sha1('fourrager'), 'market_identity_hash' => null, 'line_type' => 'custom', 'product_code' => 'CMQJNXXXX', 'product_name' => 'Blé fourrager', 'display_label' => 'Moisson fourrager', 'manual_matif' => 176.75, 'final_price_reference_key' => 'identity:'.CotationManualPrice::identityHash('EBM', 2026, 2026, 9, 'Septembre'), 'sort_order' => 3]);
    // Décembre : coté à 08:00 le 11/09 (autre heure) et après → actif, absent du relevé de 15 h = anomalie.
    $decHash = CotationManualPrice::identityHash('EBM', 2026, 2026, 12, 'Décembre');
    manualLine(['identity_hash' => $decHash, 'market_identity_hash' => $decHash, 'maturity_label' => 'Décembre', 'maturity_month' => 12, 'display_label' => 'Sept - Oct', 'sort_order' => 4]);

    marketPrice(marketRefresh('2026-09-10 01:59:04'), '2026-09-10 01:59:04', 'EBM', 'Septembre', 234.25, 2026, 2026, 9);
    marketPrice(marketRefresh('2026-09-11 15:00:04'), '2026-09-11 15:00:04', 'EBM', 'Novembre', 240, 2026, 2026, 11);
    marketPrice(marketRefresh('2026-09-11 08:00:00'), '2026-09-11 08:00:00', 'EBM', 'Décembre', 238, 2026, 2026, 12);
    marketPrice(marketRefresh('2026-09-14 15:00:04'), '2026-09-14 15:00:04', 'EBM', 'Décembre', 239, 2026, 2026, 12);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $version = historyDay($this, $viewer, '2026-09-11')->assertOk()->json('version');
    $rows = collect($version['market']['groups'])->flatMap(fn ($g) => $g['harvests']['left']['rows'])->keyBy('display_label');
    $text = implode("\n", $version['warnings']);

    // Échu : dernier cours (234,25) repris, aussi pour l'orge et pour la ligne liée.
    expect($rows['Moisson']['matif'])->toEqual(234.25)->and($rows['Moisson']['final_price'])->toEqual(214.25)
        ->and($rows['Moisson orge']['matif'])->toEqual(234.25)
        ->and($rows['Moisson fourrager']['final_price'])->not->toBeNull()
        ->and($rows['Moisson']['carried_quote_at'])->toStartWith('2026-09-10T01:59:04');
    // Actif et absent du relevé : signalé, jamais comblé ; l'échu n'est plus listé comme indisponible.
    expect($rows['Sept - Oct']['matif'])->toBeNull()
        ->and($text)->toContain("Sept - Oct) : des cours existent à d'autres moments (dernier avant ce relevé : 11/09/2026 à 08:00)")
        ->and($text)->not->toContain('Moisson) : dernier cours enregistré')
        ->and($text)->not->toContain('non coté');
});

it('ne signale rien quand toutes les lignes ont leur cours de 15 h', function (): void {
    setHarvestYears();
    manualLine();
    marketRow('2026-10-01 15:00:04');
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    expect(historyDay($this, $viewer, '2026-10-01')->json('version.warnings'))->toBe([]);
});

/* ---------------------------------------------------------------- Graphiques (séries) */

function seriesUrl(string $from, string $to): string
{
    return route('cotations.cereal-history.series', ['from' => $from, 'to' => $to]);
}

/** Ligne liée à un contrat (produit, mois, année) avec son identité propre. */
function seriesLine(string $product, string $name, string $label, int $month, int $year, string $display, float $margin, string $op = 'subtract', int $harvest = 2026): CotationManualPrice
{
    $market = CotationManualPrice::identityHash($product, $harvest, $year, $month, $label);

    return manualLine([
        'identity_hash' => sha1("$product|$display|$label|$harvest"),
        'market_identity_hash' => $market,
        'product_code' => $product,
        'product_name' => $name,
        'maturity_label' => $label,
        'maturity_month' => $month,
        'maturity_year' => $year,
        'harvest_year' => $harvest,
        'display_label' => $display,
        'base' => $margin,
        'base_operation' => $op,
    ]);
}

function dayWithPrices(string $day, array $prices): void
{
    $refresh = marketRefresh("$day 15:00:03");
    foreach ($prices as [$product, $label, $price, $month, $year]) {
        marketPrice($refresh, "$day 15:00:03", $product, $label, $price, 2026, $year, $month);
    }
}

it('renvoie une série par ligne de cotation et par céréale, avec le prix final de chaque journée', function (): void {
    setHarvestYears();
    seriesLine('ECO', 'Colza', 'Novembre', 11, 2026, 'Nov - Dec', 10);
    seriesLine('ECO', 'Colza', 'Février', 2, 2027, 'Livraison févr.', 5, 'add');
    seriesLine('EBM', 'Blé', 'Décembre', 12, 2026, 'Moisson blé', 20);
    foreach ([['2026-09-28', 500, 520, 200], ['2026-09-29', 510, 530, 205], ['2026-09-30', 520, 540, 210]] as [$day, $nov, $feb, $dec]) {
        dayWithPrices($day, [['ECO', 'Novembre', $nov, 11, 2026], ['ECO', 'Février', $feb, 2, 2027], ['EBM', 'Décembre', $dec, 12, 2026]]);
    }
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $data = $this->actingAs($viewer)->getJson(seriesUrl('2026-09-28', '2026-09-30'))->assertOk()->json();
    $colza = collect($data['cereals'])->firstWhere('code', 'ECO');
    $bySeries = collect($colza['series'])->keyBy('display_label');

    expect($data['dates'])->toBe(['2026-09-28', '2026-09-29', '2026-09-30'])
        ->and(collect($data['cereals'])->pluck('code')->sort()->values()->all())->toBe(['EBM', 'ECO'])
        ->and($colza['series'])->toHaveCount(2)
        // Nov : MATIF − 10 ; Février : MATIF + 5 ; une série = toujours la même ligne.
        ->and(collect($bySeries['Nov - Dec']['points'])->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([490.0, 500.0, 510.0])
        ->and(collect($bySeries['Livraison févr.']['points'])->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([525.0, 535.0, 545.0])
        ->and(collect($bySeries['Nov - Dec']['points'])->pluck('matif')->map(fn ($p) => (float) $p)->all())->toBe([500.0, 510.0, 520.0])
        ->and($bySeries['Nov - Dec']['points'][0])->toMatchArray(['date' => '2026-09-28', 'carried_quote_at' => null])
        ->and($bySeries['Nov - Dec']['harvest_year'])->toBe(2026);
});

it('applique la base historique de chaque journée (même MATIF, base modifiée) et ne mélange pas les lignes', function (): void {
    setHarvestYears();
    $line = seriesLine('ECO', 'Colza', 'Novembre', 11, 2026, 'Moisson', 17, 'add');
    $other = seriesLine('ECO', 'Colza', 'Novembre', 11, 2026, 'Sept - Oct', 5);
    foreach (['2026-09-29', '2026-09-30', '2026-10-01'] as $day) {
        dayWithPrices($day, [['ECO', 'Novembre', 540, 11, 2026]]); // MATIF identique chaque jour
    }
    $snap = fn (CotationManualPrice $l, float $m, string $op) => snapshotOf($l, ['identity_hash' => $l->identity_hash, 'market_identity_hash' => $l->market_identity_hash, 'product_code' => 'ECO', 'product_name' => 'Colza', 'maturity_label' => 'Novembre', 'maturity_month' => 11, 'display_label' => $l->display_label, 'base' => $m, 'base_operation' => $op]);
    auditEntry('2026-06-18 11:00:00', ['before' => [], 'after' => []], 'update_cotation_settings'); // début de la couverture du journal
    // La base de « Moisson » passe de −17 à +17 le 30/09 à 09:41.
    auditEntry('2026-09-30 09:41:32', ['manual_prices' => [['before' => $snap($line, 17, 'subtract'), 'after' => $snap($line, 17, 'add')]]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $series = collect($this->actingAs($viewer)->getJson(seriesUrl('2026-09-29', '2026-10-01'))->json('cereals.0.series'))->keyBy('display_label');

    expect(collect($series['Moisson']['points'])->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([523.0, 557.0, 557.0])
        ->and(collect($series['Sept - Oct']['points'])->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([535.0, 535.0, 535.0])
        ->and($series['Moisson']['key'])->not->toBe($series['Sept - Oct']['key']);
});

it('garde l’identité d’une série quand son libellé change, et reprend le dernier cours des échéances échues', function (): void {
    setHarvestYears();
    $line = seriesLine('ECO', 'Colza', 'Août', 8, 2026, 'Moisson', 17, 'add');
    dayWithPrices('2026-07-30', [['ECO', 'Août', 490, 8, 2026]]);              // dernier cours du contrat
    foreach (['2026-09-29', '2026-09-30'] as $day) {
        dayWithPrices($day, [['ECO', 'Novembre', 540, 11, 2026]]);               // relevé sans le contrat échu
    }
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $series = $this->actingAs($viewer)->getJson(seriesUrl('2026-07-30', '2026-09-30'))->json('cereals.0.series.0');
    $points = collect($series['points'])->keyBy('date');

    expect($series['key'])->toBe($line->identity_hash)
        ->and($points->keys()->all())->toBe(['2026-07-30', '2026-09-29', '2026-09-30'])
        ->and((float) $points['2026-07-30']['price'])->toBe(507.0)
        ->and($points['2026-07-30']['carried_quote_at'])->toBeNull()                     // vrai cours du jour
        ->and((float) $points['2026-09-29']['price'])->toBe(507.0)                         // 490 + 17 repris
        ->and($points['2026-09-29']['carried_quote_at'])->toBe('2026-07-30');
});

it('ne crée pas de point pour une journée sans cotation exploitable (pas d’interpolation)', function (): void {
    setHarvestYears();
    seriesLine('ECO', 'Colza', 'Novembre', 11, 2026, 'Nov', 0);
    dayWithPrices('2026-09-28', [['ECO', 'Novembre', 500, 11, 2026]]);
    marketPrice(marketRefresh('2026-09-29 23:59:05'), '2026-09-29 23:59:05', 'ECO', 'Novembre', 999, 2026, 2026, 11); // 23 h 59 seulement : pas de relevé de 15 h
    dayWithPrices('2026-09-30', [['ECO', 'Novembre', 520, 11, 2026]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $data = $this->actingAs($viewer)->getJson(seriesUrl('2026-09-28', '2026-09-30'))->json();

    expect($data['dates'])->toBe(['2026-09-28', '2026-09-30'])
        ->and(collect($data['cereals'][0]['series'][0]['points'])->pluck('date')->all())->toBe(['2026-09-28', '2026-09-30']);
});

it('ne charge que la période demandée et gère une période sans résultat', function (): void {
    setHarvestYears();
    seriesLine('ECO', 'Colza', 'Novembre', 11, 2026, 'Nov', 0);
    foreach (['2026-08-01', '2026-09-15', '2026-09-30'] as $day) {
        dayWithPrices($day, [['ECO', 'Novembre', 500, 11, 2026]]);
    }
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $one = $this->actingAs($viewer)->getJson(seriesUrl('2026-09-10', '2026-09-20'))->json();
    expect($one['dates'])->toBe(['2026-09-15'])->and($one['cereals'][0]['series'][0]['points'])->toHaveCount(1);

    $none = $this->actingAs($viewer)->getJson(seriesUrl('2026-05-01', '2026-05-10'))->assertOk()->json();
    expect($none['dates'])->toBe([])->and($none['cereals'])->toBe([]);
});

it('couvre les cinq périodes de l’interface : 7 jours, 30 jours, 3 mois, 1 an, plage personnalisée', function (): void {
    setHarvestYears();
    seriesLine('ECO', 'Colza', 'Novembre', 11, 2026, 'Nov', 0);
    auditEntry('2025-11-01 11:00:00', ['before' => [], 'after' => []], 'update_cotation_settings');
    $days = [];
    for ($d = CarbonImmutable::parse('2025-12-01'); $d <= CarbonImmutable::parse('2026-09-30'); $d = $d->addDays(3)) {
        $days[] = $d->toDateString();
        dayWithPrices($d->toDateString(), [['ECO', 'Novembre', 500, 11, 2026]]);
    }
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);
    $count = fn (string $from, string $to) => count($this->actingAs($viewer)->getJson(seriesUrl($from, $to))->assertOk()->json('dates'));
    $inRange = fn (string $from, string $to) => count(array_filter($days, fn ($d) => $d >= $from && $d <= $to));

    // Fin = date consultée (30/09/2026) : 7 j = 24/09→30/09 ; 30 j = 01/09→30/09 ; 3 mois = 01/07→30/09 ; 1 an = 01/10/2025→30/09/2026.
    foreach ([['2026-09-24', '2026-09-30'], ['2026-09-01', '2026-09-30'], ['2026-07-01', '2026-09-30'], ['2025-10-01', '2026-09-30'], ['2026-02-10', '2026-03-10']] as [$from, $to]) {
        expect($count($from, $to))->toBe($inRange($from, $to));
    }
});

it('vérifie les paramètres de période côté serveur', function (): void {
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);
    $get = fn (array $q) => $this->actingAs($viewer)->getJson(route('cotations.cereal-history.series', $q));

    $get([])->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
    $get(['from' => '2026-13-45', 'to' => '2026-09-30'])->assertStatus(422)->assertJsonValidationErrors('from');
    $get(['from' => '2026-09-30', 'to' => '2026-09-01'])->assertStatus(422)->assertJsonPath('errors.to.0', 'La date de fin doit être postérieure ou égale à la date de début.');
    $get(['from' => '2026-01-01', 'to' => '2999-01-01'])->assertStatus(422)->assertJsonPath('errors.to.0', 'La date de fin ne peut pas être dans le futur.');
    $get(['from' => '2024-01-01', 'to' => '2026-01-01'])->assertStatus(422)->assertJsonPath('errors.to.0', 'La période ne peut pas dépasser un an (366 jours).');
    $get(['from' => '2025-09-30', 'to' => '2026-09-30'])->assertOk();
});

it('respecte les permissions de consultation des cotations céréales pour les graphiques', function (): void {
    $fuelOnly = marketUser(['cotations.fuel.view']);
    $editor = marketUser(['cotations.cereals.edit', 'cotations.cereals.history.view']);

    $this->actingAs($fuelOnly)->getJson(seriesUrl('2026-09-01', '2026-09-30'))->assertForbidden();
    $this->actingAs($editor)->getJson(seriesUrl('2026-09-01', '2026-09-30'))->assertOk();
});

it('donne les mêmes prix aux graphiques qu’à la consultation d’une journée', function (): void {
    setHarvestYears();
    seriesLine('ECO', 'Colza', 'Novembre', 11, 2026, 'Nov', 12, 'add');
    dayWithPrices('2026-09-30', [['ECO', 'Novembre', 538.75, 11, 2026]]);
    $viewer = marketUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    $point = $this->actingAs($viewer)->getJson(seriesUrl('2026-09-30', '2026-09-30'))->json('cereals.0.series.0.points.0');
    $row = historyDay($this, $viewer, '2026-09-30')->json('version.market.groups.0.harvests.left.rows.0');

    expect((float) $point['price'])->toBe((float) $row['final_price'])->and((float) $point['matif'])->toBe(538.75);
});
