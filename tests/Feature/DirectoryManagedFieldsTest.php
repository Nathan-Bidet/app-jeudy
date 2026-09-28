<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Depot;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const DIRECTORY_VALIDITY_FIELDS = [
    'driving_license_valid_until',
    'fco_valid_until',
    'adr_valid_until',
    'eco_conduite_valid_until',
    'certiphyto_valid_until',
    'caces_valid_until',
    'fimo_valid_until',
    'nacelle_valid_until',
    'occupational_health_valid_until',
    'sst_valid_until',
];

function directoryEditor(): User
{
    return directoryUser(['directory.update']);
}

function managedFieldsPayload(array $overrides = []): array
{
    $sector = Sector::query()->create(['name' => 'Atelier', 'slug' => 'atelier-'.fake()->unique()->word()]);
    $depot = Depot::query()->create(['name' => 'Dépôt Nord', 'city' => 'Orléans']);
    $manager = directoryTarget(['first_name' => 'Claire', 'last_name' => 'Martin']);

    $payload = [
        'sector_id' => $sector->id,
        'depot_id' => $depot->id,
        'job_title' => 'Chauffeur PL',
        'sector_manager_id' => $manager->id,
        'glpi_url' => 'https://glpi.example.fr/front/user.form.php?id=42',
    ];

    foreach (DIRECTORY_VALIDITY_FIELDS as $index => $field) {
        $payload[$field] = sprintf('2027-%02d-15', $index + 1);
    }

    return array_merge($payload, $overrides);
}

test('édition : chaque champ des trois catégories est enregistré avec les coordonnées', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget();
    $payload = managedFieldsPayload(['phone' => '0611223344', 'internal_number' => '204']);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('directory.show', $target))
        ->assertSessionHas('status', 'Fiche enregistrée.');

    $fresh = $target->fresh();
    expect($fresh->sector_id)->toBe($payload['sector_id'])
        ->and($fresh->depot_id)->toBe($payload['depot_id'])
        ->and($fresh->depot_address)->toContain('Orléans')
        ->and($fresh->job_title)->toBe('Chauffeur PL')
        ->and($fresh->sector_manager_id)->toBe($payload['sector_manager_id'])
        ->and($fresh->glpi_url)->toBe($payload['glpi_url'])
        ->and($fresh->phone)->toBe('0611223344')
        ->and($fresh->internal_number)->toBe('204');

    foreach (DIRECTORY_VALIDITY_FIELDS as $field) {
        expect($fresh->{$field}?->toDateString())->toBe($payload[$field]);
    }
});

test('édition : les valeurs sont visibles au rechargement de la fiche et du formulaire', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget();
    $payload = managedFieldsPayload();

    $this->actingAs($editor)->put(route('directory.update', $target), $payload);

    $this->actingAs($editor)
        ->get(route('directory.show', $target))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.job_title', 'Chauffeur PL')
            ->where('profile.sector_manager.name', 'Claire Martin')
            ->where('profile.sector.id', $payload['sector_id'])
            ->where('profile.depot.name', 'Dépôt Nord')
            ->where('profile.glpi_href', $payload['glpi_url'])
            ->where('profile.validities.0.label', 'Permis')
            ->where('profile.validities.0.formatted', '15/01/2027')
            ->where('profile.validities.6.label', 'CACES GRUE')
            ->where('profile.validities.6.date', $payload['fimo_valid_until']));

    $this->actingAs($editor)
        ->get(route('directory.edit', $target))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Directory/Edit')
            ->where('permissions.can_manage_directory_fields', true)
            ->where('field_access.organization.sector_manager_id', true)
            ->where('field_access.validities', true)
            ->where('field_access.links.glpi_url', true)
            ->where('profile.job_title', 'Chauffeur PL')
            ->where('profile.sector_manager_id', $payload['sector_manager_id'])
            ->where('profile.sector_id', $payload['sector_id'])
            ->where('profile.depot_id', $payload['depot_id'])
            ->where('profile.sst_valid_until', $payload['sst_valid_until'])
            ->has('managers', fn (Assert $managers) => $managers->etc()));
});

test('édition : une modification partielle conserve les autres valeurs', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget();
    $payload = managedFieldsPayload();

    $this->actingAs($editor)->put(route('directory.update', $target), $payload);
    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['job_title' => 'Mécanicien'])
        ->assertSessionHasNoErrors();

    $fresh = $target->fresh();
    expect($fresh->job_title)->toBe('Mécanicien')
        ->and($fresh->sector_id)->toBe($payload['sector_id'])
        ->and($fresh->depot_id)->toBe($payload['depot_id'])
        ->and($fresh->sector_manager_id)->toBe($payload['sector_manager_id'])
        ->and($fresh->glpi_url)->toBe($payload['glpi_url'])
        ->and($fresh->adr_valid_until?->toDateString())->toBe($payload['adr_valid_until'])
        ->and($fresh->phone)->toBe('0100000000');
});

test('dates : une date facultative peut être vidée', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget(['adr_valid_until' => '2027-05-01', 'sst_valid_until' => '2027-06-01']);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['adr_valid_until' => ''])
        ->assertSessionHasNoErrors();

    $fresh = $target->fresh();
    expect($fresh->adr_valid_until)->toBeNull()
        ->and($fresh->sst_valid_until?->toDateString())->toBe('2027-06-01');
});

test('dates : les dates impossibles ou mal formées sont refusées', function (string $value): void {
    $editor = directoryEditor();
    $target = directoryTarget(['caces_valid_until' => '2027-05-01']);

    $this->actingAs($editor)
        ->from(route('directory.edit', $target))
        ->put(route('directory.update', $target), ['caces_valid_until' => $value])
        ->assertRedirect(route('directory.edit', $target))
        ->assertSessionHasErrors(['caces_valid_until' => 'La date « CACES » est invalide.']);

    expect($target->fresh()->caces_valid_until?->toDateString())->toBe('2027-05-01');
})->with([
    'jour inexistant' => '2027-02-30',
    'mois inexistant' => '2027-13-01',
    'format français brut' => '15/01/2027',
    'texte' => 'demain',
    'date hors bornes' => '1800-01-01',
]);

test('relations : identifiants inexistants ou non autorisés refusés', function (array $input, string $field): void {
    $editor = directoryEditor();
    $target = directoryTarget();

    $this->actingAs($editor)
        ->put(route('directory.update', $target), $input)
        ->assertSessionHasErrors($field);

    $fresh = $target->fresh();
    expect($fresh->sector_manager_id)->toBeNull()
        ->and($fresh->depot_id)->toBeNull();
})->with([
    'secteur inexistant' => [['sector_id' => 999999], 'sector_id'],
    'dépôt inexistant' => [['depot_id' => 999999], 'depot_id'],
    'responsable inexistant' => [['sector_manager_id' => 999999], 'sector_manager_id'],
    'responsable non numérique' => [['sector_manager_id' => 'abc'], 'sector_manager_id'],
]);

test('responsable : ni inactif, ni la personne elle-même', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget();
    $inactive = directoryTarget(['is_active' => false]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['sector_manager_id' => $inactive->id])
        ->assertSessionHasErrors('sector_manager_id');
    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['sector_manager_id' => $target->id])
        ->assertSessionHasErrors(['sector_manager_id' => 'Une personne ne peut pas être son propre responsable.']);

    expect($target->fresh()->sector_manager_id)->toBeNull();
});

test('responsable : un responsable désactivé depuis reste enregistrable, et peut être retiré', function (): void {
    $editor = directoryEditor();
    $manager = directoryTarget(['is_active' => false]);
    $target = directoryTarget(['sector_manager_id' => $manager->id]);

    $this->actingAs($editor)
        ->get(route('directory.edit', $target))
        ->assertInertia(fn (Assert $page) => $page
            ->where('managers', fn ($managers) => collect($managers)->contains('id', $manager->id)));

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['sector_manager_id' => $manager->id, 'job_title' => 'Cariste'])
        ->assertSessionHasNoErrors();
    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['sector_manager_id' => ''])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->sector_manager_id)->toBeNull();
});

test('responsable : la suppression du responsable vide le champ sans toucher la fiche', function (): void {
    $manager = directoryTarget();
    $target = directoryTarget(['sector_manager_id' => $manager->id]);

    $manager->delete();

    expect($target->fresh())->not->toBeNull()
        ->and($target->fresh()->sector_manager_id)->toBeNull();
});

test('GLPI : adresses http(s) acceptées, y compris internes', function (string $url): void {
    $editor = directoryEditor();
    $target = directoryTarget();

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['glpi_url' => $url])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->glpi_url)->toBe($url);
})->with([
    'https://glpi.example.fr/front/ticket.php?id=12',
    'http://glpi.local/front/computer.form.php?id=3',
    'http://192.168.1.20/glpi/',
]);

test('GLPI : valeurs incorrectes ou dangereuses refusées', function (string $url): void {
    $editor = directoryEditor();
    $target = directoryTarget(['glpi_url' => 'https://glpi.example.fr/']);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['glpi_url' => $url])
        ->assertSessionHasErrors('glpi_url');

    expect($target->fresh()->glpi_url)->toBe('https://glpi.example.fr/');
})->with([
    'javascript:alert(1)',
    'data:text/html;base64,PHNjcmlwdD4=',
    'ftp://glpi.example.fr/',
    'pas une url',
    '//glpi.example.fr',
]);

test('GLPI : le lien peut être vidé', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget(['glpi_url' => 'https://glpi.example.fr/']);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['glpi_url' => ''])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->glpi_url)->toBeNull();
});

test('GLPI : une valeur historique dangereuse n\'est jamais rendue cliquable', function (): void {
    $viewer = directoryUser();
    $target = directoryTarget(['glpi_url' => 'javascript:alert(1)']);

    $this->actingAs($viewer)
        ->get(route('directory.show', $target))
        ->assertInertia(fn (Assert $page) => $page->where('profile.glpi_href', null));
});

test('sans permission : sa propre fiche n\'expose ni n\'enregistre ces champs', function (): void {
    $viewer = directoryUser();
    $originalSector = $viewer->sector_id;

    $this->actingAs($viewer)
        ->get(route('directory.edit', $viewer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.can_manage_directory_fields', false)
            ->where('field_access.organization.sector_id', false)
            ->where('field_access.organization.job_title', false)
            ->where('field_access.validities', false)
            ->where('field_access.links.glpi_url', false)
            ->where('managers', []));

    $this->actingAs($viewer)
        ->put(route('directory.update', $viewer), managedFieldsPayload(['phone' => '0611223344']))
        ->assertSessionHasNoErrors();

    $fresh = $viewer->fresh();
    expect($fresh->phone)->toBe('0611223344')
        ->and($fresh->sector_id)->toBe($originalSector)
        ->and($fresh->depot_id)->toBeNull()
        ->and($fresh->job_title)->toBeNull()
        ->and($fresh->sector_manager_id)->toBeNull()
        ->and($fresh->glpi_url)->toBeNull()
        ->and($fresh->adr_valid_until)->toBeNull();
});

test('sans permission : appel direct sur la fiche d\'autrui refusé', function (): void {
    $viewer = directoryUser();
    $target = directoryTarget();

    $this->actingAs($viewer)
        ->put(route('directory.update', $target), managedFieldsPayload())
        ->assertDenied();

    expect($target->fresh()->job_title)->toBeNull()
        ->and($target->fresh()->sector_manager_id)->toBeNull();
});

test('ajout de pièces jointes seul : aucun champ de la fiche modifiable', function (): void {
    $viewer = directoryUser(['directory.files.create']);
    $target = directoryTarget();

    $this->actingAs($viewer)->get(route('directory.edit', $target))->assertDenied();
    $this->actingAs($viewer)
        ->put(route('directory.update', $target), managedFieldsPayload(['phone' => '0699999999']))
        ->assertDenied();

    $fresh = $target->fresh();
    expect($fresh->phone)->toBe('0100000000')
        ->and($fresh->job_title)->toBeNull()
        ->and($fresh->sector_manager_id)->toBeNull();
});

test('édition : champs d\'identité toujours réservés aux administrateurs', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget(['first_name' => 'Jean']);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['first_name' => 'Pirate', 'job_title' => 'Cariste'])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->first_name)->toBe('Jean')
        ->and($target->fresh()->job_title)->toBe('Cariste');
});

test('administrateur : tous les champs, et modification partielle sans email', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $target = directoryTarget(['first_name' => 'Jean']);

    $this->actingAs($admin)
        ->put(route('directory.update', $target), managedFieldsPayload(['first_name' => 'Jeanne']))
        ->assertSessionHasNoErrors();

    $fresh = $target->fresh();
    expect($fresh->first_name)->toBe('Jeanne')
        ->and($fresh->job_title)->toBe('Chauffeur PL')
        ->and($fresh->email)->toBe($target->email);
});

test('journal : les modifications de fiche sont tracées', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget();

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['job_title' => 'Cariste', 'phone' => '0100000000']);

    $log = DB::table('audit_logs')->where('action', 'update_directory_entry')->sole();
    $payload = json_decode($log->payload, true);

    expect($log->module)->toBe('directory')
        ->and($log->user_id)->toBe($editor->id)
        ->and($payload['target_user_id'])->toBe($target->id)
        ->and($payload['changes'])->toHaveKey('job_title')
        ->and($payload['changes'])->not->toHaveKey('phone')
        ->and($payload['changes']['job_title']['new'])->toBe('Cariste');
});

test('journal : aucune entrée quand rien ne change', function (): void {
    $editor = directoryEditor();
    $target = directoryTarget();

    $this->actingAs($editor)->put(route('directory.update', $target), ['phone' => '0100000000']);

    expect(DB::table('audit_logs')->where('action', 'update_directory_entry')->count())->toBe(0);
});
