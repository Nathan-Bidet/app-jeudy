<?php

use App\Http\Controllers\CotationController;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\AuditLog;
use App\Models\CotationCustomCereal;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Suppression logique d'une céréale : disparaît de l'édition, de la consultation, du PDF et du courriel ;
 * lignes, cours, audit et historique antérieur sont conservés.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    \Illuminate\Support\Carbon::setTestNow();
});

function delUser(array $abilities): User
{
    $sector = Sector::query()->create(['name' => fake()->unique()->company(), 'slug' => fake()->unique()->slug()]);
    $role = Role::findOrCreate('del-'.fake()->unique()->word(), 'web');
    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function delLine(string $code, string $name, int $sort, string $label = 'Sept'): array
{
    return [
        'line_type' => 'custom', 'identity_hash' => sha1($code.$label), 'product_code' => $code, 'product_name' => $name,
        'product_sort' => $sort, 'maturity_label' => $label, 'maturity_year' => 2026, 'harvest_year' => 2026,
        'manual_matif' => 200, 'base' => 10, 'base_operation' => 'subtract', 'sale_price' => 3, 'sale_price_operation' => 'add', 'sort_order' => 0,
    ];
}

function delSettings(): array
{
    return CotationSetting::query()->get()
        ->map(fn (CotationSetting $s): array => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])->all();
}

/** Crée une céréale personnalisée « Sarrasin » (base EBM) avec une ligne, et renvoie son code. */
function delMakeCustom($test, User $editor, string $name = 'Sarrasin'): string
{
    $test->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => delSettings(),
        'custom_cereals' => [['name' => $name, 'base_product_code' => 'EBM', 'sort_order' => 100]],
    ])->assertSessionHasNoErrors();
    $code = (string) CotationCustomCereal::query()->where('name', $name)->value('code');
    $test->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => delSettings(),
        'manual_prices' => [delLine($code, $name, 100)],
    ])->assertSessionHasNoErrors();

    return $code;
}

function delSeed($test, User $editor): void
{
    $test->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => delSettings(),
        'manual_prices' => [delLine('EBM', 'Blé', 20), delLine('EMA', 'Maïs', 30), delLine('ECO', 'Colza', 10)],
    ])->assertSessionHasNoErrors();
}

function delCodes($test, User $user, bool $edit = false): array
{
    $url = route('cotations.market-data').($edit ? '?edit=1' : '');

    return collect($test->actingAs($user)->getJson($url)->assertOk()->json('groups'))->pluck('code')->all();
}

function delCereal($test, User $user, string $code)
{
    return $test->actingAs($user)->deleteJson(route('cotations.cereals.destroy', $code));
}

function delPdfNames(): array
{
    $captured = [];
    View::composer('cotations.pdf', function ($view) use (&$captured): void {
        $captured = $view->getData();
    });
    $pdf = app(CotationController::class)->renderExportPdf();
    expect($pdf)->toStartWith('%PDF');

    return collect($captured['cerealHarvestTables']['left']['cereal_groups'] ?? [])
        ->map(fn (array $g): string => mb_substr((string) $g['name'], 0, 2))->all();
}

it('supprime une céréale personnalisée partout sans effacer aucune donnée', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view']);
    delSeed($this, $editor);
    $code = delMakeCustom($this, $editor);
    expect(delCodes($this, $editor, true))->toContain($code);
    $before = CotationManualPrice::query()->orderBy('id')->get()->map->getAttributes()->all();
    $customBefore = CotationCustomCereal::query()->where('code', $code)->first()->getAttributes();

    delCereal($this, $editor, $code)->assertOk()->assertJson(['ok' => true, 'code' => $code, 'already_deleted' => false]);

    // Absente partout (édition, consultation, rechargement, PDF).
    expect(delCodes($this, $editor, true))->not->toContain($code)
        ->and(delCodes($this, $viewer))->not->toContain($code)
        ->and(delCodes($this, $editor, true))->not->toContain($code)
        ->and(delPdfNames())->not->toContain('Sa');
    $payload = $this->actingAs($editor)->getJson(route('cotations.market-data').'?edit=1')->json();
    expect(collect($payload['custom_cereals'])->pluck('code')->all())->not->toContain($code);

    // Aucune suppression physique, aucune valeur modifiée.
    expect(CotationManualPrice::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(CotationCustomCereal::query()->where('code', $code)->first()->getAttributes())->toBe($customBefore);

    // Journal d'audit.
    $log = AuditLog::query()->where('action', 'delete_cotation_cereal')->firstOrFail();
    $audit = is_array($log->payload) ? $log->payload : json_decode((string) $log->payload, true);
    expect($log->user_id)->toBe($editor->id)->and($log->description)->toContain('Sarrasin')
        ->and($audit['cereal_code'])->toBe($code)->and($audit['cereal_type'])->toBe('custom')
        ->and($audit['preserved_manual_lines'])->toBe(1)->and($audit['soft_delete'])->toBeTrue();
});

it('supprime une céréale MATIF sans toucher aux autres ni aux céréales qui en dépendent', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view']);
    delSeed($this, $editor);
    $custom = delMakeCustom($this, $editor); // basée sur EBM
    $before = CotationManualPrice::query()->count();

    delCereal($this, $editor, 'EBM')->assertOk();

    foreach ([delCodes($this, $editor, true), delCodes($this, $viewer)] as $codes) {
        expect($codes)->not->toContain('EBM')->toContain('EMA', 'ECO', $custom);
    }
    expect(delPdfNames())->not->toContain('Bl')->toContain('Ma');
    // Le catalogue MATIF reste complet : la céréale dépendante garde ses options.
    $options = $this->actingAs($editor)->getJson(route('cotations.market-data').'?edit=1')->json('options');
    expect(collect($options)->pluck('code')->all())->toContain('EBM', $custom);
    expect(CotationManualPrice::query()->count())->toBe($before)
        ->and(CotationManualPrice::query()->where('product_code', $custom)->exists())->toBeTrue();

    // Le prix final de la céréale dépendante est inchangé (200 − 10).
    $group = collect($this->actingAs($viewer)->getJson(route('cotations.market-data'))->json('groups'))->firstWhere('code', $custom);
    expect($group['harvests']['left']['rows'][0]['final_price'])->toEqual(190);
});

it('conserve l\'ordre des autres céréales', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    delSeed($this, $editor);
    $order = delCodes($this, $editor, true);
    $middle = $order[1];

    delCereal($this, $editor, $middle)->assertOk();

    expect(delCodes($this, $editor, true))->toBe(array_values(array_diff($order, [$middle])));
});

it('supprime aussi une céréale masquée et reste indépendante du masquage', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    delSeed($this, $editor);
    $this->actingAs($editor)->putJson(route('cotations.cereals.visibility', 'EMA'), ['visible' => false])->assertOk();

    delCereal($this, $editor, 'EMA')->assertOk();

    expect(delCodes($this, $editor, true))->not->toContain('EMA');
    // Une céréale supprimée ne peut plus être masquée/affichée.
    $this->actingAs($editor)->putJson(route('cotations.cereals.visibility', 'EMA'), ['visible' => true])->assertNotFound();
});

it('est idempotent (double clic) et protégé par les permissions', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view']);
    delSeed($this, $editor);

    delCereal($this, $viewer, 'EMA')->assertStatus(403);
    expect(delCodes($this, $editor, true))->toContain('EMA');
    delCereal($this, $editor, 'ZZZ')->assertNotFound();

    delCereal($this, $editor, 'EMA')->assertOk()->assertJson(['already_deleted' => false]);
    delCereal($this, $editor, 'EMA')->assertOk()->assertJson(['already_deleted' => true]);

    expect(AuditLog::query()->where('action', 'delete_cotation_cereal')->count())->toBe(1);
});

it('ne ressuscite pas la céréale après de nouveaux cours MATIF ni avec un formulaire resté ouvert', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    delSeed($this, $editor);
    $code = delMakeCustom($this, $editor);
    delCereal($this, $editor, $code)->assertOk();
    delCereal($this, $editor, 'EMA')->assertOk();

    // Nouveau relevé MATIF (synchronisation) sur le produit supprimé.
    $now = now()->format('Y-m-d H:i:s');
    $refresh = DB::table('cotation_market_refreshes')->insertGetId(['source_url' => 't', 'is_success' => true, 'row_count' => 1, 'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('cotation_market_prices')->insert(['refresh_id' => $refresh, 'product_code' => 'EMA', 'product_name' => 'Maïs', 'product_sort' => 30, 'maturity_label' => 'Sept', 'maturity_month' => 9, 'maturity_year' => 2026, 'harvest_year' => 2026, 'price' => 250, 'maturity_sort' => 9, 'created_at' => $now, 'updated_at' => $now]);
    expect(delCodes($this, $editor, true))->not->toContain('EMA', $code);

    // Formulaire d'édition périmé qui renvoie la céréale supprimée : ignorée, données conservées.
    $line = CotationManualPrice::query()->where('product_code', $code)->firstOrFail();
    $line->refresh();
    $stale = delLine($code, 'Sarrasin renommé', 100);
    $stale['manual_id'] = $line->id;
    $stale['manual_matif'] = 999;
    $this->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => delSettings(),
        'manual_prices' => [$stale],
        'custom_cereals' => [['id' => CotationCustomCereal::query()->where('code', $code)->value('id'), 'code' => $code, 'name' => 'Sarrasin renommé', 'base_product_code' => 'EBM', 'sort_order' => 100]],
    ])->assertSessionHasNoErrors();

    expect((float) $line->fresh()->manual_matif)->toBe(200.0)
        ->and(CotationCustomCereal::query()->where('code', $code)->value('name'))->toBe('Sarrasin')
        ->and(delCodes($this, $editor, true))->not->toContain($code);
});

it('crée une nouvelle céréale distincte quand le nom d\'une céréale supprimée est réutilisé', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    delSeed($this, $editor);
    $old = delMakeCustom($this, $editor, 'Sarrasin');
    delCereal($this, $editor, $old)->assertOk();

    $new = delMakeCustom($this, $editor, 'Sarrasin');
    // Le helper retrouve par nom : on vérifie explicitement les deux identités.
    $codes = CotationCustomCereal::query()->where('name', 'Sarrasin')->pluck('code')->all();
    expect($codes)->toHaveCount(2)->and(array_unique($codes))->toHaveCount(2);
    $fresh = collect($codes)->first(fn (string $c): bool => $c !== $old);

    $visible = delCodes($this, $editor, true);
    expect($visible)->not->toContain($old);
    // Les anciennes lignes restent rattachées à l'ancien code uniquement.
    expect(CotationManualPrice::query()->where('product_code', $old)->count())->toBe(1)
        ->and(CotationManualPrice::query()->where('product_code', $fresh)->count())->toBe(0);
});

it('garde la céréale dans l\'historique antérieur à sa suppression, et l\'en retire après', function (): void {
    foreach (['harvest_left_year' => 2026, 'harvest_right_year' => 2027] as $key => $value) {
        CotationSetting::query()->updateOrCreate(['key' => $key], ['section' => 'display', 'label' => $key, 'value' => $value, 'sort_order' => 1]);
    }
    $hash = CotationManualPrice::identityHash('EMA', 2026, 2026, 9, 'Septembre');
    $line = CotationManualPrice::query()->create([
        'identity_hash' => $hash, 'market_identity_hash' => $hash, 'line_type' => 'matif', 'product_code' => 'EMA', 'product_name' => 'Maïs',
        'product_sort' => 30, 'maturity_label' => 'Septembre', 'maturity_month' => 9, 'maturity_year' => 2026, 'harvest_year' => 2026,
        'base' => 20, 'base_operation' => 'subtract', 'sort_order' => 1,
    ]);
    DB::table('cotation_manual_prices')->where('id', $line->id)->update(['created_at' => '2026-06-20 10:00:00']);

    foreach (['2026-10-10', '2026-10-13'] as $day) {
        $at = CarbonImmutable::parse("$day 15:00:03", 'Europe/Paris')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $refresh = DB::table('cotation_market_refreshes')->insertGetId(['source_url' => 't', 'is_success' => true, 'row_count' => 1, 'fetched_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        DB::table('cotation_market_prices')->insert(['refresh_id' => $refresh, 'product_code' => 'EMA', 'product_name' => 'Maïs', 'product_sort' => 30, 'maturity_label' => 'Septembre', 'maturity_month' => 9, 'maturity_year' => 2026, 'harvest_year' => 2026, 'price' => 200, 'maturity_sort' => 9, 'created_at' => $at, 'updated_at' => $at]);
    }

    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view', 'cotations.cereals.history.view']);

    // Suppression le 12/10/2026 à 12 h (heure de Paris).
    \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 12:00:00', 'Europe/Paris'));
    delCereal($this, $editor, 'EMA')->assertOk();
    \Illuminate\Support\Carbon::setTestNow();

    $codesAt = fn (string $day): array => collect($this->actingAs($viewer)->getJson(route('cotations.cereal-history.show', $day))->assertOk()->json('version.market.groups'))->pluck('code')->all();
    expect($codesAt('2026-10-10'))->toContain('EMA')
        ->and($codesAt('2026-10-13'))->not->toContain('EMA')
        ->and(delCodes($this, $viewer))->not->toContain('EMA');

    // Les anciennes valeurs de la ligne sont intactes.
    expect((float) $line->fresh()->base)->toBe(20.0);
});

/*
 * Restauration : même identité, mêmes données ; les périodes de suppression restent tracées
 * pour que l'historique ne change pas rétroactivement.
 */
function restoreCereal($test, User $user, string $code)
{
    return $test->actingAs($user)->postJson(route('cotations.cereals.restore', $code));
}

function restoreHistoryDay(string $day): void
{
    $at = CarbonImmutable::parse("$day 15:00:03", 'Europe/Paris')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    $refresh = DB::table('cotation_market_refreshes')->insertGetId(['source_url' => 't', 'is_success' => true, 'row_count' => 1, 'fetched_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
    DB::table('cotation_market_prices')->insert(['refresh_id' => $refresh, 'product_code' => 'EMA', 'product_name' => 'Maïs', 'product_sort' => 30, 'maturity_label' => 'Septembre', 'maturity_month' => 9, 'maturity_year' => 2026, 'harvest_year' => 2026, 'price' => 200, 'maturity_sort' => 9, 'created_at' => $at, 'updated_at' => $at]);
}

it('liste les céréales supprimées pour l\'édition uniquement, avec nom, type, date et auteur', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    $editor->forceFill(['name' => 'Administrateur Test'])->save();
    $viewer = delUser(['cotations.cereals.view']);
    delSeed($this, $editor);
    $custom = delMakeCustom($this, $editor);

    expect($this->actingAs($editor)->getJson(route('cotations.market-data').'?edit=1')->json('deleted_cereals'))->toBe([]);

    \Illuminate\Support\Carbon::setTestNow('2026-10-12 12:00:00');
    delCereal($this, $editor, 'EMA')->assertOk();
    \Illuminate\Support\Carbon::setTestNow('2026-10-12 14:30:00');
    delCereal($this, $editor, $custom)->assertOk();
    \Illuminate\Support\Carbon::setTestNow();

    $list = $this->actingAs($editor)->getJson(route('cotations.market-data').'?edit=1')->json('deleted_cereals');
    expect($list)->toHaveCount(2)
        ->and($list[0]['code'])->toBe($custom)->and($list[0]['type'])->toBe('custom')->and($list[0]['name'])->toBe('Sarrasin')
        ->and($list[1]['code'])->toBe('EMA')->and($list[1]['type'])->toBe('matif')
        ->and($list[0]['deleted_by_name'])->toBe($editor->name)
        ->and($list[0]['deleted_at'])->toStartWith('2026-10-12T14:30:00');
    // Ni la consultation ni un simple lecteur ne reçoivent cette liste.
    expect($this->actingAs($editor)->getJson(route('cotations.market-data'))->json('deleted_cereals'))->toBe([])
        ->and($this->actingAs($viewer)->getJson(route('cotations.market-data').'?edit=1')->json('deleted_cereals'))->toBe([]);
});

it('restaure une céréale MATIF et une personnalisée avec leur identité et toutes leurs données', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view']);
    delSeed($this, $editor);
    $custom = delMakeCustom($this, $editor);
    $lines = CotationManualPrice::query()->orderBy('id')->get()->map->getAttributes()->all();
    $customRow = CotationCustomCereal::query()->orderBy('id')->get()->map->getAttributes()->all();
    $linesCount = count($lines);
    delCereal($this, $editor, 'EMA')->assertOk();
    delCereal($this, $editor, $custom)->assertOk();
    expect(delCodes($this, $viewer))->not->toContain('EMA', $custom);

    restoreCereal($this, $editor, 'EMA')->assertOk()->assertJson(['ok' => true, 'already_restored' => false]);
    restoreCereal($this, $editor, $custom)->assertOk()->assertJsonPath('deleted_cereals', []);

    foreach ([delCodes($this, $editor, true), delCodes($this, $viewer)] as $codes) {
        expect($codes)->toContain('EMA', $custom);
    }
    expect(delPdfNames())->toContain('Ma', 'Sa');
    // Aucune duplication, aucune valeur modifiée (prix de vente, base, marge, échéances…), même code.
    expect(CotationManualPrice::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($lines)
        ->and(CotationManualPrice::query()->count())->toBe($linesCount)
        ->and(CotationCustomCereal::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($customRow);
});

it('restitue l\'état masqué d\'avant la suppression et la place dans l\'ordre', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view']);
    delSeed($this, $editor);
    $order = delCodes($this, $editor, true);
    $this->actingAs($editor)->put(route('cotations.settings.update'), ['settings' => delSettings(), 'cereal_order' => array_reverse($order)])->assertSessionHasNoErrors();
    $order = delCodes($this, $editor, true);
    $middle = $order[1];
    $this->actingAs($editor)->putJson(route('cotations.cereals.visibility', $middle), ['visible' => false])->assertOk();

    delCereal($this, $editor, $middle)->assertOk();
    // Enregistrement d'un formulaire sans la céréale supprimée : sa place dans l'ordre est gardée.
    $without = array_values(array_diff($order, [$middle]));
    $this->actingAs($editor)->put(route('cotations.settings.update'), ['settings' => delSettings(), 'cereal_order' => $without])->assertSessionHasNoErrors();
    restoreCereal($this, $editor, $middle)->assertOk();

    expect(delCodes($this, $editor, true))->toBe($order)
        ->and(delCodes($this, $viewer))->not->toContain($middle)
        ->and(delPdfNames())->not->toContain(mb_substr((string) collect(delNames($this, $editor))->get($middle), 0, 2))
        ->and($this->actingAs($editor)->getJson(route('cotations.market-data').'?edit=1')->json('hidden_cereal_codes'))->toBe([$middle]);
});

function delNames($test, User $user): array
{
    return collect($test->actingAs($user)->getJson(route('cotations.market-data').'?edit=1')->json('groups'))->pluck('name', 'code')->all();
}

it('garde les périodes de suppression sur plusieurs cycles dans l\'historique', function (): void {
    foreach (['harvest_left_year' => 2026, 'harvest_right_year' => 2027] as $key => $value) {
        CotationSetting::query()->updateOrCreate(['key' => $key], ['section' => 'display', 'label' => $key, 'value' => $value, 'sort_order' => 1]);
    }
    $hash = CotationManualPrice::identityHash('EMA', 2026, 2026, 9, 'Septembre');
    $line = CotationManualPrice::query()->create([
        'identity_hash' => $hash, 'market_identity_hash' => $hash, 'line_type' => 'matif', 'product_code' => 'EMA', 'product_name' => 'Maïs',
        'product_sort' => 30, 'maturity_label' => 'Septembre', 'maturity_month' => 9, 'maturity_year' => 2026, 'harvest_year' => 2026,
        'base' => 20, 'base_operation' => 'subtract', 'sort_order' => 1,
    ]);
    DB::table('cotation_manual_prices')->where('id', $line->id)->update(['created_at' => '2026-06-20 10:00:00']);
    foreach (['2026-10-10', '2026-10-13', '2026-10-16', '2026-10-21'] as $day) {
        restoreHistoryDay($day);
    }
    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view', 'cotations.cereals.history.view']);
    $at = fn (string $when) => \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::parse($when, 'Europe/Paris'));

    $at('2026-10-12 12:00:00');
    delCereal($this, $editor, 'EMA')->assertOk();
    $at('2026-10-15 12:00:00');
    restoreCereal($this, $editor, 'EMA')->assertOk();
    $at('2026-10-20 12:00:00');
    delCereal($this, $editor, 'EMA')->assertOk();
    \Illuminate\Support\Carbon::setTestNow();

    $codesAt = fn (string $day): array => collect($this->actingAs($viewer)->getJson(route('cotations.cereal-history.show', $day))->assertOk()->json('version.market.groups'))->pluck('code')->all();
    expect($codesAt('2026-10-10'))->toContain('EMA')
        ->and($codesAt('2026-10-13'))->not->toContain('EMA')
        ->and($codesAt('2026-10-16'))->toContain('EMA')
        ->and($codesAt('2026-10-21'))->not->toContain('EMA');

    // Les deux périodes restent tracées ; rien n'a été effacé.
    $periods = app(\App\Services\Cotations\CotationMarketService::class)->deletionRegistry()['EMA']['periods'];
    expect($periods)->toHaveCount(2)
        ->and($periods[0]['restored_at'])->not->toBeNull()->and($periods[1]['restored_at'])->toBeNull()
        ->and((float) $line->fresh()->base)->toBe(20.0);
});

it('journalise la restauration sans effacer les événements de suppression, et reste idempotent', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    delSeed($this, $editor);
    delCereal($this, $editor, 'EMA')->assertOk();

    restoreCereal($this, $editor, 'EMA')->assertOk()->assertJson(['already_restored' => false]);
    restoreCereal($this, $editor, 'EMA')->assertOk()->assertJson(['already_restored' => true]);

    expect(AuditLog::query()->where('action', 'delete_cotation_cereal')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'restore_cotation_cereal')->count())->toBe(1);
    $log = AuditLog::query()->where('action', 'restore_cotation_cereal')->firstOrFail();
    $payload = is_array($log->payload) ? $log->payload : json_decode((string) $log->payload, true);
    expect($log->user_id)->toBe($editor->id)->and($log->description)->toContain('restaurée')
        ->and($payload['cereal_code'])->toBe('EMA')->and($payload['cereal_type'])->toBe('matif')
        ->and($payload['before']['deleted'])->toBeTrue()->and($payload['after']['deleted'])->toBeFalse();
});

it('refuse la restauration sans droit, pour une céréale jamais supprimée, ou inconnue', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    $viewer = delUser(['cotations.cereals.view']);
    delSeed($this, $editor);
    delCereal($this, $editor, 'EMA')->assertOk();

    restoreCereal($this, $viewer, 'EMA')->assertStatus(403);
    expect(delCodes($this, $editor, true))->not->toContain('EMA');
    restoreCereal($this, $editor, 'EBM')->assertNotFound();
    restoreCereal($this, $editor, 'ZZZ')->assertNotFound();
});

it('lit un ancien registre à plat, le convertit en périodes à la restauration sans perdre la date d\'origine', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    delSeed($this, $editor);
    CotationSetting::query()->create([
        'key' => 'cereal_deleted_codes', 'section' => 'cereal_visibility', 'label' => 'Céréales supprimées', 'sort_order' => 32,
        'note' => json_encode(['EMA' => ['deleted_at' => '2026-10-12T10:00:00+00:00', 'name' => 'Maïs', 'deleted_by' => $editor->id]]),
    ]);

    expect(delCodes($this, $editor, true))->not->toContain('EMA');
    $list = $this->actingAs($editor)->getJson(route('cotations.market-data').'?edit=1')->json('deleted_cereals');
    expect($list[0]['code'])->toBe('EMA')->and($list[0]['deleted_at'])->toStartWith('2026-10-12T10:00:00');

    restoreCereal($this, $editor, 'EMA')->assertOk();
    $periods = app(\App\Services\Cotations\CotationMarketService::class)->deletionRegistry()['EMA']['periods'];
    expect($periods)->toHaveCount(1)->and($periods[0]['deleted_at'])->toStartWith('2026-10-12T10:00:00')
        ->and($periods[0]['restored_at'])->not->toBeNull()
        ->and(delCodes($this, $editor, true))->toContain('EMA');
});

it('restaure la bonne céréale quand une autre du même nom a été créée entre-temps', function (): void {
    $editor = delUser(['cotations.cereals.edit']);
    delSeed($this, $editor);
    $old = delMakeCustom($this, $editor, 'Sarrasin');
    delCereal($this, $editor, $old)->assertOk();
    $this->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => delSettings(),
        'custom_cereals' => [['name' => 'Sarrasin', 'base_product_code' => 'EBM', 'sort_order' => 101]],
    ])->assertSessionHasNoErrors();
    $new = (string) CotationCustomCereal::query()->where('name', 'Sarrasin')->where('code', '!=', $old)->value('code');
    expect($new)->not->toBe('')->and($new)->not->toBe($old);

    restoreCereal($this, $editor, $old)->assertOk();

    $visible = delCodes($this, $editor, true);
    expect($visible)->toContain($old)
        ->and(CotationManualPrice::query()->where('product_code', $old)->count())->toBe(1)
        ->and(CotationManualPrice::query()->where('product_code', $new)->count())->toBe(0);
});
