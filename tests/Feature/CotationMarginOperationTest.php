<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use App\Services\Cotations\CotationMarketService;
use App\Support\Cotations\CotationPdfFormatter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Signe de la « base » (colonne margin) : MATIF − base (défaut historique)
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
        'margin' => 17,
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
    expect(CotationManualPrice::applyMargin(490, 17, 'subtract'))->toBe(473.0)
        ->and(CotationManualPrice::applyMargin(490, 17, 'add'))->toBe(507.0)
        ->and(CotationManualPrice::applyMargin(490, 17, null))->toBe(473.0)
        ->and(CotationManualPrice::applyMargin(490, 17, 'n\'importe quoi'))->toBe(473.0);
});

it('gère une base nulle, décimale ou historiquement négative sans double signe', function (): void {
    expect(CotationManualPrice::applyMargin(490, 0, 'add'))->toBe(490.0)
        ->and(CotationManualPrice::applyMargin(490, null, 'subtract'))->toBe(490.0)
        ->and(CotationManualPrice::applyMargin(490.5, '17.25', 'add'))->toBe(507.75)
        ->and(CotationManualPrice::applyMargin(490, -17, 'subtract'))->toBe(473.0)
        ->and(CotationManualPrice::applyMargin(490, -17, 'add'))->toBe(507.0);
});

it('formate la base avec son signe pour le PDF', function (): void {
    expect(CotationPdfFormatter::margin(17))->toBe('-17 €')
        ->and(CotationPdfFormatter::margin(17, 'subtract'))->toBe('-17 €')
        ->and(CotationPdfFormatter::margin(17, 'add'))->toBe('+17 €')
        ->and(CotationPdfFormatter::margin(null, 'add'))->toBe('—');
});

it('utilise « subtract » par défaut pour les données existantes et les nouvelles lignes', function (): void {
    $line = cotationLine();
    saveCotationLines($this, cotationEditor(), [$line])->assertSessionHasNoErrors();

    $manual = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->firstOrFail();
    expect($manual->margin_operation)->toBe('subtract');

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
        'margin' => 17,
    ]);
    expect($legacy->fresh()->margin_operation)->toBe('subtract')
        ->and(cotationRow(sha1('legacy'))['final_price'])->toBe(473.0);
});

it('passe une ligne de « − » à « + » puis revient, et persiste après rechargement', function (): void {
    $user = cotationEditor();
    $line = cotationLine();
    saveCotationLines($this, $user, [$line])->assertSessionHasNoErrors();

    $manualId = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->value('id');

    saveCotationLines($this, $user, [[...$line, 'manual_id' => $manualId, 'margin_operation' => 'add']])
        ->assertSessionHasNoErrors();

    $row = cotationRow($line['identity_hash']);
    expect($row['margin_operation'])->toBe('add')
        ->and($row['margin'])->toBe(17.0)
        ->and($row['final_price'])->toBe(507.0);

    saveCotationLines($this, $user, [[...$line, 'manual_id' => $manualId, 'margin_operation' => 'subtract']])
        ->assertSessionHasNoErrors();

    $row = cotationRow($line['identity_hash']);
    expect($row['margin_operation'])->toBe('subtract')
        ->and($row['final_price'])->toBe(473.0);
});

it('garde un signe indépendant par ligne', function (): void {
    $user = cotationEditor();
    $a = cotationLine(['maturity_label' => 'Sept', 'margin_operation' => 'add']);
    $b = cotationLine(['maturity_label' => 'Déc', 'margin_operation' => 'subtract']);
    $c = cotationLine(['maturity_label' => 'Mars']);

    saveCotationLines($this, $user, [$a, $b, $c])->assertSessionHasNoErrors();

    expect(cotationRow($a['identity_hash'])['final_price'])->toBe(507.0)
        ->and(cotationRow($b['identity_hash'])['final_price'])->toBe(473.0)
        ->and(cotationRow($c['identity_hash'])['margin_operation'])->toBe('subtract');
});

it('conserve le signe enregistré quand un ancien client n\'envoie pas le champ', function (): void {
    $user = cotationEditor();
    $line = cotationLine(['margin_operation' => 'add']);
    saveCotationLines($this, $user, [$line])->assertSessionHasNoErrors();
    $manualId = CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->value('id');

    $legacyPayload = collect($line)->except('margin_operation')->merge(['manual_id' => $manualId, 'margin' => 20])->all();
    saveCotationLines($this, $user, [$legacyPayload])->assertSessionHasNoErrors();

    $row = cotationRow($line['identity_hash']);
    expect($row['margin_operation'])->toBe('add')
        ->and($row['final_price'])->toBe(510.0);
});

it('rejette un signe invalide côté serveur', function (): void {
    $line = cotationLine(['margin_operation' => 'multiply']);

    saveCotationLines($this, cotationEditor(), [$line])
        ->assertSessionHasErrors('manual_prices.0.margin_operation');

    expect(CotationManualPrice::query()->where('identity_hash', $line['identity_hash'])->exists())->toBeFalse();
});

it('refuse la modification du signe sans permission d\'édition', function (): void {
    $line = cotationLine(['margin_operation' => 'add']);

    // Le handler global convertit le 403 des requêtes non-GET en redirection + message d'erreur.
    saveCotationLines($this, cotationEditor(['cotations.cereals.view']), [$line])
        ->assertRedirect()
        ->assertSessionHas('error', 'Action non autorisee.');

    expect(CotationManualPrice::query()->count())->toBe(0);
});

it('applique le signe à une ligne dont le MATIF est résolu depuis une autre ligne', function (): void {
    $user = cotationEditor();
    $source = cotationLine(['product_code' => 'ECO', 'product_name' => 'Colza', 'margin_operation' => 'add']);
    $linked = cotationLine([
        'manual_matif' => null,
        'margin' => 5,
        'margin_operation' => 'subtract',
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

    saveCotationLines($this, $user, [[...$line, 'manual_id' => $manualId, 'margin_operation' => 'add']])
        ->assertSessionHasNoErrors();

    $audit = \Illuminate\Support\Facades\DB::table('audit_logs')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and((string) $audit->payload)->toContain('"margin_operation":"add"');
})->skip(fn () => ! \Illuminate\Support\Facades\Schema::hasTable('audit_logs'), 'Pas de table audit_logs');
