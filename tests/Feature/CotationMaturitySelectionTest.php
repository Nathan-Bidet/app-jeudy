<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\CotationCustomCereal;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * La récolte d'une ligne et l'échéance MATIF qu'elle référence sont indépendantes : toute échéance
 * encore cotée (présente dans la dernière actualisation utile) de la céréale MATIF d'origine peut être
 * choisie pour n'importe quelle récolte. Les échéances échues ne sont plus proposées mais les lignes
 * qui les référencent restent intactes.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function maturityEditor(): User
{
    $sector = Sector::query()->create(['name' => fake()->unique()->company(), 'slug' => fake()->unique()->slug()]);
    $role = Role::findOrCreate('maturity-'.fake()->unique()->word(), 'web');
    $role->givePermissionTo(Permission::findOrCreate('cotations.cereals.edit', 'web'));
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/** @return array{refresh: int, hashes: array<string, string>} */
function maturityRefresh(array $contracts, bool $success = true, ?int $rowCount = null): array
{
    $refreshId = DB::table('cotation_market_refreshes')->insertGetId([
        'source_url' => 'test', 'is_success' => $success, 'row_count' => $rowCount ?? count($contracts),
        'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $hashes = [];
    $sorts = ['ECO' => 10, 'EBM' => 20, 'EMA' => 30];

    foreach ($contracts as [$product, $month, $year, $label, $price]) {
        DB::table('cotation_market_prices')->insert([
            'refresh_id' => $refreshId, 'product_code' => $product, 'product_name' => $product, 'product_sort' => $sorts[$product],
            'contract_code' => $product.$month.$year, 'maturity_label' => $label, 'maturity_month' => $month,
            'maturity_year' => $year, 'harvest_year' => $year, 'price' => $price, 'maturity_sort' => $year * 100 + $month,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $hashes["$product $label"] = CotationManualPrice::identityHash($product, $year, $year, $month, $label);
    }

    return ['refresh' => $refreshId, 'hashes' => $hashes];
}

/** Marché de référence : blé sur 2026 à 2028, colza sur 2027, plus une échéance échue (Sept. 2026) d'une relève antérieure. */
function maturityMarket(): array
{
    $expired = maturityRefresh([['EBM', 9, 2026, 'Septembre 2026', 230.0]]);
    $active = maturityRefresh([
        ['EBM', 12, 2026, 'Décembre 2026', 242.25],
        ['EBM', 3, 2027, 'Mars 2027', 244.0],
        ['EBM', 3, 2028, 'Mars 2028', 240.5],
        ['ECO', 11, 2026, 'Novembre 2026', 518.0],
        ['ECO', 2, 2027, 'Février 2027', 557.5],
    ]);

    return $expired['hashes'] + $active['hashes'];
}

function maturityLine(string $code, int $harvest, ?string $hash, array $contract = [], array $extra = []): array
{
    return [
        'line_type' => $hash ? 'matif' : 'custom', 'identity_hash' => sha1($code.$harvest.($hash ?? 'c').uniqid()),
        'market_identity_hash' => $hash, 'product_code' => $code, 'product_name' => $code, 'product_sort' => 20,
        'maturity_label' => $contract['label'] ?? 'Option', 'maturity_month' => $contract['month'] ?? null,
        'maturity_year' => $contract['year'] ?? $harvest, 'harvest_year' => $harvest,
        'manual_matif' => $hash ? null : 100, 'base' => 10, 'base_operation' => 'subtract', 'sort_order' => 0,
        ...$extra,
    ];
}

function maturitySave($test, User $user, array $lines, array $custom = [])
{
    $settings = CotationSetting::query()->get()
        ->map(fn (CotationSetting $s): array => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])->all();

    return $test->actingAs($user)->put(route('cotations.settings.update'), [
        'settings' => $settings, 'manual_prices' => $lines, 'custom_cereals' => $custom,
    ]);
}

function maturityMarketData($test, User $user): array
{
    return $test->actingAs($user)->getJson(route('cotations.market-data').'?edit=1')->assertOk()->json();
}

function maturityOptionLabels(array $data, string $code, string $bucket): array
{
    $group = collect($data['options'])->firstWhere('code', $code);

    return collect($group['harvests'][$bucket]['rows'])->pluck('label')->all();
}

it('propose les mêmes échéances actives aux deux années de récolte, toutes années confondues', function (): void {
    maturityMarket();
    $data = maturityMarketData($this, maturityEditor());

    $expected = ['Décembre 2026', 'Mars 2027', 'Mars 2028'];
    expect(maturityOptionLabels($data, 'EBM', 'left'))->toBe($expected)
        ->and(maturityOptionLabels($data, 'EBM', 'right'))->toBe($expected);
});

it('ne propose pas les échéances échues mais ne propose que celles de la céréale MATIF concernée', function (): void {
    maturityMarket();
    $data = maturityMarketData($this, maturityEditor());

    expect(maturityOptionLabels($data, 'EBM', 'left'))->not->toContain('Septembre 2026')
        ->and(maturityOptionLabels($data, 'ECO', 'left'))->toBe(['Novembre 2026', 'Février 2027'])
        ->and(maturityOptionLabels($data, 'ECO', 'right'))->toBe(['Novembre 2026', 'Février 2027']);
});

it('donne à une céréale personnalisée les échéances de sa céréale MATIF d\'origine', function (): void {
    maturityMarket();
    CotationCustomCereal::query()->create(['code' => 'CFOUR', 'name' => 'Blé fourrager', 'base_product_code' => 'EBM', 'sort_order' => 100]);
    CotationCustomCereal::query()->create(['code' => 'CCOLZ', 'name' => 'Colza bio', 'base_product_code' => 'ECO', 'sort_order' => 101]);
    $data = maturityMarketData($this, maturityEditor());

    expect(maturityOptionLabels($data, 'CFOUR', 'left'))->toBe(['Décembre 2026', 'Mars 2027', 'Mars 2028'])
        ->and(maturityOptionLabels($data, 'CFOUR', 'right'))->toBe(['Décembre 2026', 'Mars 2027', 'Mars 2028'])
        ->and(maturityOptionLabels($data, 'CCOLZ', 'left'))->toBe(['Novembre 2026', 'Février 2027']);
});

it('ignore une relève en échec ou vide : la dernière relève utile reste la référence', function (): void {
    maturityMarket();
    maturityRefresh([], success: false, rowCount: 0);
    maturityRefresh([], success: true, rowCount: 0);
    $data = maturityMarketData($this, maturityEditor());

    expect(maturityOptionLabels($data, 'EBM', 'left'))->toBe(['Décembre 2026', 'Mars 2027', 'Mars 2028']);
});

it('permet à une récolte 2026 de choisir une échéance 2027 et à une récolte 2027 d\'en choisir une 2026', function (): void {
    $hashes = maturityMarket();
    $editor = maturityEditor();

    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['EBM Mars 2027'], ['label' => 'Mars 2027', 'month' => 3, 'year' => 2027]),
        maturityLine('EBM', 2027, $hashes['EBM Décembre 2026'], ['label' => 'Décembre 2026', 'month' => 12, 'year' => 2026]),
    ])->assertSessionHasNoErrors();

    $lines = CotationManualPrice::query()->orderBy('harvest_year')->get();
    expect($lines->pluck('harvest_year')->all())->toBe([2026, 2027])
        ->and($lines->pluck('maturity_year')->all())->toBe([2027, 2026]);

    // Chaque ligne reste dans sa récolte et reprend le cours de SA échéance (prix final = MATIF − base).
    $groups = collect(maturityMarketData($this, $editor)['groups'])->firstWhere('code', 'EBM');
    $left = $groups['harvests']['left']['rows'][0];
    $right = $groups['harvests']['right']['rows'][0];
    expect($left['maturity_label'])->toBe('Mars 2027')->and((float) $left['matif'])->toBe(244.0)->and((float) $left['final_price'])->toBe(234.0)->and($left['has_euronext'])->toBeTrue()
        ->and($right['maturity_label'])->toBe('Décembre 2026')->and((float) $right['matif'])->toBe(242.25)->and((float) $right['final_price'])->toBe(232.25);
});

it('autorise une échéance au-delà des deux années affichées (2028) sans perdre son cours', function (): void {
    $hashes = maturityMarket();
    $editor = maturityEditor();

    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['EBM Mars 2028'], ['label' => 'Mars 2028', 'month' => 3, 'year' => 2028]),
    ])->assertSessionHasNoErrors();

    $row = collect(maturityMarketData($this, $editor)['groups'])->firstWhere('code', 'EBM')['harvests']['left']['rows'][0];
    expect((float) $row['matif'])->toBe(240.5)->and($row['has_euronext'])->toBeTrue();
});

it('refuse une nouvelle sélection d\'une échéance échue ou d\'une autre céréale MATIF', function (): void {
    $hashes = maturityMarket();
    $editor = maturityEditor();

    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['EBM Septembre 2026'], ['label' => 'Septembre 2026', 'month' => 9, 'year' => 2026]),
    ])->assertSessionHasErrors('manual_prices.0.market_identity_hash');

    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['ECO Février 2027'], ['label' => 'Février 2027', 'month' => 2, 'year' => 2027]),
    ])->assertSessionHasErrors('manual_prices.0.market_identity_hash');

    expect(CotationManualPrice::query()->count())->toBe(0);
});

it('conserve intacte une ligne qui référence une échéance échue, y compris à la réenregistrement', function (): void {
    $hashes = maturityMarket();
    $editor = maturityEditor();
    $manual = CotationManualPrice::query()->create([
        'identity_hash' => sha1('legacy'), 'market_identity_hash' => $hashes['EBM Septembre 2026'], 'line_type' => 'matif',
        'product_code' => 'EBM', 'product_name' => 'Blé meunier', 'product_sort' => 20, 'maturity_label' => 'Septembre 2026',
        'maturity_month' => 9, 'maturity_year' => 2026, 'harvest_year' => 2026, 'base' => 10, 'base_operation' => 'subtract', 'sort_order' => 0,
    ]);

    // Consultable : dernier cours connu de l'échéance échue, non remplacée par une autre.
    $row = collect(maturityMarketData($this, $editor)['groups'])->firstWhere('code', 'EBM')['harvests']['left']['rows'][0];
    expect($row['maturity_label'])->toBe('Septembre 2026')->and((float) $row['matif'])->toBe(230.0)->and($row['market_identity_hash'])->toBe($hashes['EBM Septembre 2026']);

    // Réenregistrement sans changer d'échéance : accepté.
    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['EBM Septembre 2026'], ['label' => 'Septembre 2026', 'month' => 9, 'year' => 2026], ['manual_id' => $manual->id, 'identity_hash' => $manual->identity_hash, 'base' => 15]),
    ])->assertSessionHasNoErrors();

    $manual->refresh();
    expect($manual->market_identity_hash)->toBe($hashes['EBM Septembre 2026'])->and($manual->maturity_label)->toBe('Septembre 2026')->and((float) $manual->base)->toBe(15.0);
});

it('change l\'échéance d\'une ligne existante vers une autre année et recalcule le cours', function (): void {
    $hashes = maturityMarket();
    $editor = maturityEditor();
    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['EBM Décembre 2026'], ['label' => 'Décembre 2026', 'month' => 12, 'year' => 2026]),
    ])->assertSessionHasNoErrors();
    $manual = CotationManualPrice::query()->firstOrFail();

    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['EBM Mars 2027'], ['label' => 'Mars 2027', 'month' => 3, 'year' => 2027], ['manual_id' => $manual->id, 'identity_hash' => $manual->identity_hash]),
    ])->assertSessionHasNoErrors();

    $manual->refresh();
    expect($manual->market_identity_hash)->toBe($hashes['EBM Mars 2027'])->and($manual->harvest_year)->toBe(2026)->and($manual->maturity_year)->toBe(2027);
    $row = collect(maturityMarketData($this, $editor)['groups'])->firstWhere('code', 'EBM')['harvests']['left']['rows'][0];
    expect((float) $row['matif'])->toBe(244.0);
});

it('ne modifie pas silencieusement les lignes quand une céréale personnalisée change de référence MATIF', function (): void {
    $hashes = maturityMarket();
    $editor = maturityEditor();
    $cereal = CotationCustomCereal::query()->create(['code' => 'CFOUR', 'name' => 'Blé fourrager', 'base_product_code' => 'EBM', 'sort_order' => 100]);
    maturitySave($this, $editor, [
        maturityLine('CFOUR', 2026, $hashes['EBM Mars 2027'], ['label' => 'Mars 2027', 'month' => 3, 'year' => 2027]),
    ], [['id' => $cereal->id, 'code' => 'CFOUR', 'name' => 'Blé fourrager', 'base_product_code' => 'EBM', 'sort_order' => 100]])->assertSessionHasNoErrors();
    $manual = CotationManualPrice::query()->firstOrFail();

    // Changement de référence : la ligne conserve son échéance et son cours, les options passent au colza.
    maturitySave($this, $editor, [
        maturityLine('CFOUR', 2026, $hashes['EBM Mars 2027'], ['label' => 'Mars 2027', 'month' => 3, 'year' => 2027], ['manual_id' => $manual->id, 'identity_hash' => $manual->identity_hash]),
    ], [['id' => $cereal->id, 'code' => 'CFOUR', 'name' => 'Blé fourrager', 'base_product_code' => 'ECO', 'sort_order' => 100]])->assertSessionHasNoErrors();

    $data = maturityMarketData($this, $editor);
    expect($manual->refresh()->market_identity_hash)->toBe($hashes['EBM Mars 2027'])
        ->and(maturityOptionLabels($data, 'CFOUR', 'left'))->toBe(['Novembre 2026', 'Février 2027']);

    // Nouvelle sélection : seules les échéances de la nouvelle référence sont acceptées.
    maturitySave($this, $editor, [
        maturityLine('CFOUR', 2026, $hashes['EBM Décembre 2026'], ['label' => 'Décembre 2026', 'month' => 12, 'year' => 2026], ['manual_id' => $manual->id, 'identity_hash' => $manual->identity_hash]),
    ], [['id' => $cereal->id, 'code' => 'CFOUR', 'name' => 'Blé fourrager', 'base_product_code' => 'ECO', 'sort_order' => 100]])->assertSessionHasErrors('manual_prices.0.market_identity_hash');
    expect($manual->refresh()->market_identity_hash)->toBe($hashes['EBM Mars 2027']);
});

it('laisse les lignes et leurs données intactes quand aucune actualisation utile n\'est disponible', function (): void {
    $hashes = maturityMarket();
    $editor = maturityEditor();
    maturitySave($this, $editor, [
        maturityLine('EBM', 2026, $hashes['EBM Mars 2027'], ['label' => 'Mars 2027', 'month' => 3, 'year' => 2027]),
    ])->assertSessionHasNoErrors();
    $before = CotationManualPrice::query()->first()->only(['market_identity_hash', 'maturity_label', 'harvest_year', 'maturity_year', 'base']);

    DB::table('cotation_market_refreshes')->update(['is_success' => false]);
    $data = maturityMarketData($this, $editor);

    expect(maturityOptionLabels($data, 'EBM', 'left'))->toBe([])
        ->and(CotationManualPrice::query()->first()->only(['market_identity_hash', 'maturity_label', 'harvest_year', 'maturity_year', 'base']))->toBe($before);
    $row = collect($data['groups'])->firstWhere('code', 'EBM')['harvests']['left']['rows'][0];
    expect((float) $row['matif'])->toBe(244.0);
});
