<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use App\Services\Cotations\CotationMarketService;
use App\Support\Cotations\CotationPdfFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Signe de la « base » (colonne base) : MATIF − base (défaut historique)
 * ou MATIF + base, propre à chaque ligne d'échéance.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function cotationEditor(array $abilities = ['cotations.cereals.edit']): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);
    $role = Role::findOrCreate('cotation-test-'.fake()->unique()->word(), 'web');
    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function cotationLine(array $overrides = []): array
{
    return array_merge([
        'line_type' => 'custom',
        'identity_hash' => sha1(uniqid('line', true)),
        'product_code' => 'EBM',
        'product_name' => 'Blé meunier',
        'product_sort' => 10,
        'maturity_label' => 'Sept',
        'maturity_year' => 2026,
        'harvest_year' => 2026,
        'manual_matif' => 490,
        'base' => 17,
        'sort_order' => 0,
    ], $overrides);
}

function saveCotationLines($test, User $user, array $lines)
{
    $settings = CotationSetting::query()->get()
        ->map(fn (CotationSetting $s): array => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])
        ->all();

    return $test->actingAs($user)->put(route('cotations.settings.update'), [
        'settings' => $settings,
        'manual_prices' => $lines,
    ]);
}

function cotationRow(string $identityHash): array
{
    $groups = app(CotationMarketService::class)->latestGroups(2026, 2027, true);

    foreach ($groups as $group) {
        foreach (['left', 'right'] as $bucket) {
            foreach ($group['harvests'][$bucket]['rows'] as $row) {
                if ($row['identity_hash'] === $identityHash) {
                    return $row;
                }
            }
        }
    }

    throw new RuntimeException('Ligne introuvable');
}

it('calcule le prix final avec les deux opérations', function (): void {
    expect(CotationManualPrice::applyBase(490, 17, 'subtract'))->toBe(473.0)
        ->and(CotationManualPrice::applyBase(490, 17, 'add'))->toBe(507.0)
        ->and(CotationManualPrice::applyBase(490, 17, null))->toBe(473.0)
        ->and(CotationManualPrice::applyBase(490, 17, 'n\'importe quoi'))->toBe(473.0);
});

it('gère une base nulle, décimale ou historiquement négative sans double signe', function (): void {
    expect(CotationManualPrice::applyBase(490, 0, 'add'))->toBe(490.0)
        ->and(CotationManualPrice::applyBase(490, null, 'subtract'))->toBe(490.0)
        ->and(CotationManualPrice::applyBase(490.5, '17.25', 'add'))->toBe(507.75)
        ->and(CotationManualPrice::applyBase(490, -17, 'subtract'))->toBe(473.0)
        ->and(CotationManualPrice::applyBase(490, -17, 'add'))->toBe(507.0);
});

it('formate la base avec son signe pour le PDF', function (): void {
    expect(CotationPdfFormatter::base(17))->toBe('-17 €')
        ->and(CotationPdfFormatter::base(17, 'subtract'))->toBe('-17 €')
        ->and(CotationPdfFormatter::base(17, 'add'))->toBe('+17 €')
        ->and(CotationPdfFormatter::base(null, 'add'))->toBe('—');
});

it('utilise « subtract » par défaut pour les données existantes et les nouvelles lignes', function (): void {
    $line = cotationLine();
    saveCotationLines($this, cotationEditor(), [$line])->assertSessionHasNoErrors();

    $manual = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->firstOrFail();
    expect($manual->base_operation)->toBe('subtract');

    // Ligne antérieure à la migration : la colonne prend sa valeur par défaut.
    $legacy = CotationManualPrice::query()->create([
        'identity_hash' => sha1('legacy'),
        'line_type' => 'custom',
        'product_code' => 'EBM',
        'product_name' => 'Blé meunier',
        'product_sort' => 10,
        'maturity_label' => 'Déc',
        'maturity_year' => 2026,
        'harvest_year' => 2026,
        'manual_matif' => 490,
        'base' => 17,
    ]);
    expect($legacy->fresh()->base_operation)->toBe('subtract')
        ->and(cotationRow(sha1('legacy'))['final_price'])->toBe(473.0);
});

it('passe une ligne de « − » à « + » puis revient, et persiste après rechargement', function (): void {
    $user = cotationEditor();
    $line = cotationLine();
    saveCotationLines($this, $user, [$line])->assertSessionHasNoErrors();

    $manualId = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->value('id');

    saveCotationLines($this, $user, [[...$line, 'manual_id' => $manualId, 'base_operation' => 'add']])
        ->assertSessionHasNoErrors();

    $row = cotationRow($line['identity_hash']);
    expect($row['base_operation'])->toBe('add')
        ->and($row['base'])->toBe(17.0)
        ->and($row['final_price'])->toBe(507.0);

    saveCotationLines($this, $user, [[...$line, 'manual_id' => $manualId, 'base_operation' => 'subtract']])
        ->assertSessionHasNoErrors();

    $row = cotationRow($line['identity_hash']);
    expect($row['base_operation'])->toBe('subtract')
        ->and($row['final_price'])->toBe(473.0);
});

it('garde un signe indépendant par ligne', function (): void {
    $user = cotationEditor();
    $a = cotationLine(['maturity_label' => 'Sept', 'base_operation' => 'add']);
    $b = cotationLine(['maturity_label' => 'Déc', 'base_operation' => 'subtract']);
    $c = cotationLine(['maturity_label' => 'Mars']);

    saveCotationLines($this, $user, [$a, $b, $c])->assertSessionHasNoErrors();

    expect(cotationRow($a['identity_hash'])['final_price'])->toBe(507.0)
        ->and(cotationRow($b['identity_hash'])['final_price'])->toBe(473.0)
        ->and(cotationRow($c['identity_hash'])['base_operation'])->toBe('subtract');
});

it('conserve le signe enregistré quand un ancien client n\'envoie pas le champ', function (): void {
    $user = cotationEditor();
    $line = cotationLine(['base_operation' => 'add']);
    saveCotationLines($this, $user, [$line])->assertSessionHasNoErrors();
    $manualId = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->value('id');

    $legacyPayload = collect($line)->except('base_operation')->merge(['manual_id' => $manualId, 'base' => 20])->all();
    saveCotationLines($this, $user, [$legacyPayload])->assertSessionHasNoErrors();

    $row = cotationRow($line['identity_hash']);
    expect($row['base_operation'])->toBe('add')
        ->and($row['final_price'])->toBe(510.0);
});

it('rejette un signe invalide côté serveur', function (): void {
    $line = cotationLine(['base_operation' => 'multiply']);

    saveCotationLines($this, cotationEditor(), [$line])
        ->assertSessionHasErrors('manual_prices.0.base_operation');

    expect(CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->exists())->toBeFalse();
});

it('refuse la modification du signe sans permission d\'édition', function (): void {
    $line = cotationLine(['base_operation' => 'add']);

    // Le handler global convertit le 403 des requêtes non-GET en redirection + message d'erreur.
    saveCotationLines($this, cotationEditor(['cotations.cereals.view']), [$line])
        ->assertRedirect()
        ->assertSessionHas('error', 'Action non autorisee.');

    expect(CotationManualPrice::query()->count())->toBe(0);
});

it('applique le signe à une ligne dont le MATIF est résolu depuis une autre ligne', function (): void {
    $user = cotationEditor();
    $source = cotationLine(['product_code' => 'ECO', 'product_name' => 'Colza', 'base_operation' => 'add']);
    $linked = cotationLine([
        'manual_matif' => null,
        'base' => 5,
        'base_operation' => 'subtract',
        'final_price_reference_key' => 'identity:'.$source['identity_hash'],
    ]);

    saveCotationLines($this, $user, [$source, $linked])->assertSessionHasNoErrors();

    // Source : 490 + 17 = 507 ; ligne liée : 507 − 5 = 502.
    expect(cotationRow($linked['identity_hash'])['final_price'])->toBe(502.0);
});

it('journalise le changement de signe dans l\'audit', function (): void {
    $user = cotationEditor();
    $line = cotationLine();
    saveCotationLines($this, $user, [$line])->assertSessionHasNoErrors();
    $manualId = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->value('id');

    saveCotationLines($this, $user, [[...$line, 'manual_id' => $manualId, 'base_operation' => 'add']])
        ->assertSessionHasNoErrors();

    $audit = \Illuminate\Support\Facades\DB::table('audit_logs')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and((string) $audit->payload)->toContain('"base_operation":"add"');
})->skip(fn () => ! \Illuminate\Support\Facades\Schema::hasTable('audit_logs'), 'Pas de table audit_logs');

it('calcule la marge = prix de vente signé − base signée, sans toucher au prix final', function (): void {
    $cases = [
        [1, 'add', 39, 'subtract', 40.0],
        [1, 'add', 28, 'subtract', 29.0],
        [10, 'add', 5, 'add', 5.0],
        [10, 'subtract', 5, 'add', -15.0],
        [10, 'subtract', 20, 'subtract', 10.0],
        [0.1, 'add', 0.3, 'add', -0.2],
    ];
    foreach ($cases as [$sale, $saleOp, $base, $baseOp, $expected]) {
        expect(CotationManualPrice::saleMargin($sale, $saleOp, $base, $baseOp))->toBe($expected);
    }
    expect(CotationManualPrice::applyBase(242, 39, 'subtract'))->toBe(203.0);
});

it('enregistre le prix de vente, le restitue au gestionnaire et le cache en consultation', function (): void {
    $line = cotationLine(['sale_price' => 12.5, 'sale_price_operation' => 'subtract']);
    saveCotationLines($this, cotationEditor(), [$line])->assertSessionHasNoErrors();

    $saved = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->firstOrFail();
    expect((float) $saved->sale_price)->toBe(12.5)->and($saved->sale_price_operation)->toBe('subtract');

    $svc = app(CotationMarketService::class);
    $find = function (array $groups) use ($line): array {
        foreach ($groups as $group) {
            foreach (['left', 'right'] as $bucket) {
                foreach ($group['harvests'][$bucket]['rows'] as $row) {
                    if ($row['identity_hash'] === $line['identity_hash']) {
                        return $row;
                    }
                }
            }
        }
        throw new RuntimeException('Ligne introuvable');
    };

    $edit = $find($svc->latestGroups(2026, 2027, true, false, true));
    expect($edit['sale_price'])->toBe(12.5)->and($edit['sale_price_operation'])->toBe('subtract');
    expect($find($svc->latestGroups(2026, 2027, true)))->not->toHaveKeys(['sale_price', 'sale_price_operation']);
});

it('refuse un prix de vente invalide ou une requête sans droit de modification', function (): void {
    saveCotationLines($this, cotationEditor(), [cotationLine(['sale_price' => -3])])->assertSessionHasErrors('manual_prices.0.sale_price');
    $line = cotationLine(['sale_price' => 5]);
    saveCotationLines($this, cotationEditor(['cotations.cereals.view']), [$line]);
    expect(CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->exists())->toBeFalse();
});

function savedSaleMargin(string $hash): float
{
    return (float) CotationManualPrice::query()->where('identity_hash', $hash)->firstOrFail()->margin;
}

it('enregistre la marge calculée côté serveur et la recalcule à chaque changement', function (): void {
    $user = cotationEditor();
    $line = cotationLine(['sale_price' => 1, 'sale_price_operation' => 'add', 'base' => 39, 'base_operation' => 'subtract']);
    saveCotationLines($this, $user, [$line])->assertSessionHasNoErrors();
    expect(savedSaleMargin($line['identity_hash']))->toBe(40.0);

    $manualId = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->value('id');
    $current = array_merge($line, ['manual_id' => $manualId]);
    $edit = function (array $changes) use (&$current, $user) {
        $current = array_merge($current, $changes);

        return saveCotationLines($this, $user, [$current])->assertSessionHasNoErrors();
    };

    $edit(['base' => 28]);
    expect(savedSaleMargin($line['identity_hash']))->toBe(29.0);
    $edit(['base' => 5, 'base_operation' => 'add', 'sale_price' => 10, 'sale_price_operation' => 'subtract']);
    expect(savedSaleMargin($line['identity_hash']))->toBe(-15.0);
    $edit(['sale_price_operation' => 'add']);
    expect(savedSaleMargin($line['identity_hash']))->toBe(5.0);
    $edit(['base_operation' => 'subtract']);
    expect(savedSaleMargin($line['identity_hash']))->toBe(15.0);
    $edit(['sale_price' => 0.25, 'base' => 0]);
    expect(savedSaleMargin($line['identity_hash']))->toBe(0.25);
});

it('ignore une marge envoyée par le navigateur et calcule une marge pour une nouvelle échéance', function (): void {
    $line = cotationLine(['sale_price' => 0, 'base' => 17, 'base_operation' => 'add', 'margin' => 9999]);
    saveCotationLines($this, cotationEditor(), [$line])->assertSessionHasNoErrors();

    expect(savedSaleMargin($line['identity_hash']))->toBe(-17.0);
});

it('renomme les colonnes sans perdre ni recalculer aucune valeur, et sait revenir en arrière', function (): void {
    $migration = require database_path('migrations/2026_10_12_110000_rename_cotation_base_and_margin_columns.php');
    $line = cotationLine(['base' => 39, 'base_operation' => 'subtract', 'sale_price' => 1, 'sale_price_operation' => 'add']);
    saveCotationLines($this, cotationEditor(), [$line])->assertSessionHasNoErrors();
    $before = DB::table('cotation_manual_prices')->where('identity_hash', $line['identity_hash'])->first();
    $count = DB::table('cotation_manual_prices')->count();

    $migration->down();
    expect(Schema::hasColumn('cotation_manual_prices', 'sale_margin'))->toBeTrue()
        ->and(Schema::hasColumn('cotation_manual_prices', 'base'))->toBeFalse();
    $legacy = DB::table('cotation_manual_prices')->where('identity_hash', $line['identity_hash'])->first();
    expect((float) $legacy->margin)->toBe((float) $before->base)
        ->and($legacy->margin_operation)->toBe($before->base_operation)
        ->and((float) $legacy->sale_margin)->toBe((float) $before->margin);

    $migration->up();
    $after = DB::table('cotation_manual_prices')->where('identity_hash', $line['identity_hash'])->first();
    expect(Schema::hasColumn('cotation_manual_prices', 'sale_margin'))->toBeFalse()
        ->and((array) $after)->toEqual((array) $before)
        ->and(DB::table('cotation_manual_prices')->count())->toBe($count)
        ->and((float) $after->margin)->toBe(40.0);
});

it('relit les anciens journaux d\'audit où la base était enregistrée sous « margin »', function (): void {
    $service = app(CotationMarketService::class);
    $snapshot = ['line_type' => 'custom', 'product_code' => 'EBM', 'product_name' => 'Blé', 'maturity_label' => 'Sept', 'maturity_year' => 2026, 'harvest_year' => 2026, 'manual_matif' => 490, 'identity_hash' => 'x'];

    $legacy = $service->configuredRowFromSnapshot($snapshot + ['margin' => 17.0, 'margin_operation' => 'add', 'sale_margin' => 5.0], [], 10);
    $current = $service->configuredRowFromSnapshot($snapshot + ['base' => 17.0, 'base_operation' => 'add', 'margin' => 99.0], [], 10);

    expect($legacy['base'])->toBe(17.0)->and($legacy['base_operation'])->toBe('add')
        ->and($current['base'])->toBe(17.0)->and($current['base_operation'])->toBe('add');
});
