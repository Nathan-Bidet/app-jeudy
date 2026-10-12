<?php

use App\Http\Controllers\CotationController;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\AuditLog;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Affichage / masquage d'une céréale : consultation et PDF filtrés côté serveur,
 * édition toujours complète, aucune donnée de cotation touchée.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function visibilityUser(array $abilities): User
{
    $sector = Sector::query()->create(['name' => fake()->unique()->company(), 'slug' => fake()->unique()->slug()]);
    $role = Role::findOrCreate('visibility-'.fake()->unique()->word(), 'web');
    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function visibilityLine(string $code, string $name, int $sort): array
{
    return [
        'line_type' => 'custom', 'identity_hash' => sha1($code.'line'), 'product_code' => $code, 'product_name' => $name,
        'product_sort' => $sort, 'maturity_label' => 'Sept', 'maturity_year' => 2026, 'harvest_year' => 2026,
        'manual_matif' => 200, 'base' => 10, 'base_operation' => 'subtract', 'sort_order' => 0,
    ];
}

function seedVisibilityLines($test, User $editor): void
{
    $settings = CotationSetting::query()->get()
        ->map(fn (CotationSetting $s): array => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])->all();
    $test->actingAs($editor)->put(route('cotations.settings.update'), [
        'settings' => $settings,
        'manual_prices' => [visibilityLine('EBM', 'Blé meunier', 20), visibilityLine('EMA', 'Maïs', 30)],
    ])->assertSessionHasNoErrors();
}

function visibilityJson($test, User $user, bool $edit = false): array
{
    $url = route('cotations.market-data').($edit ? '?edit=1' : '');

    return $test->actingAs($user)->getJson($url)->assertOk()->json();
}

function visibilityCodes($test, User $user, bool $edit = false): array
{
    return collect(visibilityJson($test, $user, $edit)['groups'])->pluck('code')->all();
}

function setVisibility($test, User $user, string $code, bool $visible)
{
    return $test->actingAs($user)->putJson(route('cotations.cereals.visibility', $code), ['visible' => $visible]);
}

it('affiche toutes les céréales par défaut', function (): void {
    $editor = visibilityUser(['cotations.cereals.edit']);
    seedVisibilityLines($this, $editor);
    $viewer = visibilityUser(['cotations.cereals.view']);

    expect(visibilityCodes($this, $viewer))->toContain('EBM', 'EMA');
    expect(visibilityJson($this, $editor, true)['hidden_cereal_codes'])->toBe([]);
});

it('masque une céréale en consultation mais la garde entière en édition, puis la réaffiche', function (): void {
    $editor = visibilityUser(['cotations.cereals.edit']);
    seedVisibilityLines($this, $editor);
    $viewer = visibilityUser(['cotations.cereals.view']);
    $before = CotationManualPrice::query()->orderBy('id')->get()->map->only(['id', 'base', 'margin', 'manual_matif'])->all();

    setVisibility($this, $editor, 'EBM', false)->assertOk()->assertJson(['ok' => true, 'visible' => false, 'hidden_cereal_codes' => ['EBM']]);

    // Consultation : plus de carte, plus d'option MATIF, ordre des autres conservé.
    $viewerJson = $this->actingAs($viewer)->getJson(route('cotations.market-data'))->json();
    expect(collect($viewerJson['groups'])->pluck('code')->all())->not->toContain('EBM')->toContain('EMA')
        ->and(collect($viewerJson['options'])->pluck('code')->all())->not->toContain('EBM')
        ->and($viewerJson['hidden_cereal_codes'])->toBe([]);

    // Édition : tout est là, avec la liste des codes masqués.
    $editorJson = visibilityJson($this, $editor, true);
    expect(collect($editorJson['groups'])->pluck('code')->all())->toContain('EBM', 'EMA')
        ->and($editorJson['hidden_cereal_codes'])->toBe(['EBM']);

    // Aucune donnée de cotation modifiée.
    expect(CotationManualPrice::query()->orderBy('id')->get()->map->only(['id', 'base', 'margin', 'manual_matif'])->all())->toBe($before);

    // Persistance (nouvelle requête) puis réaffichage.
    expect(visibilityCodes($this, $viewer))->not->toContain('EBM');
    setVisibility($this, $editor, 'EBM', true)->assertOk()->assertJson(['hidden_cereal_codes' => []]);
    expect(visibilityCodes($this, $viewer))->toContain('EBM', 'EMA');
});

it('est idempotent : répéter la même demande ne crée ni doublon ni journal superflu', function (): void {
    $editor = visibilityUser(['cotations.cereals.edit']);
    seedVisibilityLines($this, $editor);

    setVisibility($this, $editor, 'EBM', false)->assertOk();
    setVisibility($this, $editor, 'EBM', false)->assertOk()->assertJson(['hidden_cereal_codes' => ['EBM']]);

    expect(AuditLog::query()->where('action', 'update_cotation_cereal_visibility')->count())->toBe(1);
});

it('journalise le masquage et le réaffichage avec l\'utilisateur et les états', function (): void {
    $editor = visibilityUser(['cotations.cereals.edit']);
    seedVisibilityLines($this, $editor);

    setVisibility($this, $editor, 'EMA', false)->assertOk();
    setVisibility($this, $editor, 'EMA', true)->assertOk();

    $logs = AuditLog::query()->where('action', 'update_cotation_cereal_visibility')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->description)->toContain('Maïs')->toContain('masquée')
        ->and($logs[1]->description)->toContain('rendue visible')
        ->and($logs[0]->user_id)->toBe($editor->id);
    $payload = is_array($logs[0]->payload) ? $logs[0]->payload : json_decode((string) $logs[0]->payload, true);
    expect($payload['before']['visible'])->toBeTrue()->and($payload['after']['visible'])->toBeFalse();
});

it('refuse la modification sans droit d\'édition et pour une céréale inconnue', function (): void {
    $editor = visibilityUser(['cotations.cereals.edit']);
    seedVisibilityLines($this, $editor);
    $viewer = visibilityUser(['cotations.cereals.view']);

    setVisibility($this, $viewer, 'EBM', false)->assertStatus(403);
    setVisibility($this, $editor, 'ZZZ', false)->assertNotFound();
    $this->actingAs($editor)->putJson(route('cotations.cereals.visibility', 'EBM'), ['visible' => 'peut-être'])->assertStatus(422);

    expect(visibilityCodes($this, $viewer))->toContain('EBM');
});

it('exclut les céréales masquées du PDF, côté serveur, en gardant l\'ordre', function (): void {
    $editor = visibilityUser(['cotations.cereals.edit']);
    seedVisibilityLines($this, $editor);

    $captured = [];
    View::composer('cotations.pdf', function ($view) use (&$captured): void {
        $captured = $view->getData();
    });
    $render = fn (): string => app(CotationController::class)->renderExportPdf();
    // Initiales (ASCII) : insensible à la normalisation Unicode des libellés.
    $names = function () use (&$captured): array {
        return collect($captured['cerealHarvestTables']['left']['cereal_groups'] ?? [])
            ->map(fn (array $g): string => mb_substr((string) $g['name'], 0, 2))
            ->all();
    };

    expect($render())->toStartWith('%PDF');
    expect($names())->toBe(['Bl', 'Ma']);

    setVisibility($this, $editor, 'EBM', false)->assertOk();
    $render();
    expect($names())->toBe(['Ma']);

    setVisibility($this, $editor, 'EMA', false)->assertOk();
    $render();
    expect($captured['cerealHarvestTables']['left']['has_data'])->toBeFalse();
});

/*
 * Le mode (consultation / édition) décide de la visibilité, pas le rôle : un utilisateur
 * autorisé à modifier voit le même filtre que les autres tant qu'il ne demande pas l'édition.
 */
function visibilityAdmin(): User
{
    return visibilityUser(['cotations.cereals.edit', 'cotations.cereals.view', 'cotations.admin']);
}

it('filtre les céréales masquées pour un administrateur qui consulte, et les montre en édition', function (): void {
    $admin = visibilityAdmin();
    seedVisibilityLines($this, $admin);
    setVisibility($this, $admin, 'EMA', false)->assertOk();

    // Consultation (aucun paramètre) : Maïs absent, ni liste de codes masqués, ni prix de vente.
    $consult = visibilityJson($this, $admin);
    expect(collect($consult['groups'])->pluck('code')->all())->not->toContain('EMA')->toContain('EBM')
        ->and($consult['hidden_cereal_codes'])->toBe([])
        ->and(json_encode($consult['groups']))->not->toContain('sale_price');

    // Édition : Maïs présent, marqué masqué.
    $edit = visibilityJson($this, $admin, true);
    expect(collect($edit['groups'])->pluck('code')->all())->toContain('EMA', 'EBM')
        ->and($edit['hidden_cereal_codes'])->toBe(['EMA']);
});

it('ne donne le mode édition qu\'aux utilisateurs autorisés (?edit=1 ignoré sinon)', function (): void {
    $editor = visibilityUser(['cotations.cereals.edit']);
    seedVisibilityLines($this, $editor);
    setVisibility($this, $editor, 'EMA', false)->assertOk();
    $viewer = visibilityUser(['cotations.cereals.view']);

    expect(visibilityCodes($this, $viewer, true))->not->toContain('EMA')
        ->and(visibilityJson($this, $viewer, true)['hidden_cereal_codes'])->toBe([]);
});

it('masque plusieurs céréales, puis toutes : consultation vide, édition complète', function (): void {
    $admin = visibilityAdmin();
    seedVisibilityLines($this, $admin);

    setVisibility($this, $admin, 'EBM', false)->assertOk();
    setVisibility($this, $admin, 'EMA', false)->assertOk();
    expect(visibilityCodes($this, $admin))->not->toContain('EBM', 'EMA');

    foreach (collect(visibilityCodes($this, $admin, true)) as $code) {
        setVisibility($this, $admin, $code, false)->assertOk();
    }
    expect(visibilityJson($this, $admin)['groups'])->toBe([])
        ->and(visibilityCodes($this, $admin, true))->toContain('EBM', 'EMA');
});

it('masque une céréale personnalisée comme une céréale MATIF', function (): void {
    $admin = visibilityAdmin();
    seedVisibilityLines($this, $admin);
    $settings = CotationSetting::query()->get()->map(fn (CotationSetting $s): array => ['id' => $s->id, 'value' => $s->value, 'note' => $s->note])->all();
    $this->actingAs($admin)->put(route('cotations.settings.update'), [
        'settings' => $settings,
        'custom_cereals' => [['name' => 'Sarrasin', 'base_product_code' => 'EBM', 'sort_order' => 100]],
    ])->assertSessionHasNoErrors();
    $customCode = (string) \App\Models\CotationCustomCereal::query()->value('code');
    $line = visibilityLine($customCode, 'Sarrasin', 100);
    $this->actingAs($admin)->put(route('cotations.settings.update'), [
        'settings' => $settings,
        'manual_prices' => [$line],
    ])->assertSessionHasNoErrors();

    setVisibility($this, $admin, $customCode, false)->assertOk();

    expect(visibilityCodes($this, $admin))->not->toContain($customCode)
        ->and(collect(visibilityJson($this, $admin)['custom_cereals'])->pluck('code')->all())->not->toContain($customCode)
        ->and(visibilityCodes($this, $admin, true))->toContain($customCode);
});

it('reflète immédiatement masquage, réaffichage et rechargement en consultation', function (): void {
    $admin = visibilityAdmin();
    seedVisibilityLines($this, $admin);

    setVisibility($this, $admin, 'EMA', false)->assertOk();
    expect(visibilityCodes($this, $admin))->not->toContain('EMA');
    expect(visibilityCodes($this, $admin))->not->toContain('EMA'); // rechargement
    setVisibility($this, $admin, 'EMA', true)->assertOk();
    expect(visibilityCodes($this, $admin))->toContain('EMA');
});
