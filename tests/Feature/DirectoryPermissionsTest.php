<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\SectorPermission;
use App\Models\User;
use App\Models\UserFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
});

function directoryPdf(): UploadedFile
{
    return UploadedFile::fake()->create('contrat.pdf', 100, 'application/pdf');
}

function assertShowPermissions($test, User $viewer, User $target, bool $canUpdate, bool $canAttach): void
{
    $test->actingAs($viewer)
        ->get(route('directory.show', $target))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Directory/Show')
            ->where('permissions.can_update', $canUpdate)
            ->where('permissions.can_attach_file', $canAttach));
}

test('ajout seul : peut ajouter une pièce jointe', function (): void {
    $viewer = directoryUser(['directory.files.create']);
    $target = directoryTarget();

    $this->actingAs($viewer)
        ->post(route('directory.files.store', $target), ['file' => directoryPdf()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $file = UserFile::query()->sole();
    expect($file->user_id)->toBe($target->id)
        ->and($file->uploaded_by_user_id)->toBe($viewer->id);
    Storage::disk('local')->assertExists($file->path);
});

test('ajout seul : ni bouton Éditer, ni formulaire, ni enregistrement', function (): void {
    $viewer = directoryUser(['directory.files.create']);
    $target = directoryTarget();

    assertShowPermissions($this, $viewer, $target, canUpdate: false, canAttach: true);

    $this->actingAs($viewer)->get(route('directory.edit', $target))->assertDenied();
    $this->actingAs($viewer)
        ->put(route('directory.update', $target), ['phone' => '0999999999'])
        ->assertDenied();

    expect($target->fresh()->phone)->toBe('0100000000');
});

test('édition seule : peut modifier la fiche', function (): void {
    $viewer = directoryUser(['directory.update']);
    $target = directoryTarget();

    assertShowPermissions($this, $viewer, $target, canUpdate: true, canAttach: false);

    $this->actingAs($viewer)->get(route('directory.edit', $target))->assertOk();
    $this->actingAs($viewer)
        ->put(route('directory.update', $target), ['phone' => '0999999999'])
        ->assertRedirect(route('directory.show', $target));

    expect($target->fresh()->phone)->toBe('0999999999');
});

test('édition seule : ne permet pas d\'ajouter une pièce jointe', function (): void {
    $viewer = directoryUser(['directory.update']);
    $target = directoryTarget();

    $this->actingAs($viewer)
        ->post(route('directory.files.store', $target), ['file' => directoryPdf()])
        ->assertDenied();

    expect(UserFile::query()->count())->toBe(0);
});

test('édition seule : champs réservés aux administrateurs ignorés', function (): void {
    $viewer = directoryUser(['directory.update']);
    $target = directoryTarget();
    $originalEmail = $target->email;

    $this->actingAs($viewer)
        ->put(route('directory.update', $target), [
            'phone' => '0999999999',
            'email' => 'pirate@example.test',
        ])
        ->assertRedirect();

    expect($target->fresh()->email)->toBe($originalEmail);
});

test('les deux permissions : les deux actions sont possibles', function (): void {
    $viewer = directoryUser(['directory.update', 'directory.files.create']);
    $target = directoryTarget();

    assertShowPermissions($this, $viewer, $target, canUpdate: true, canAttach: true);

    $this->actingAs($viewer)
        ->put(route('directory.update', $target), ['phone' => '0999999999'])
        ->assertRedirect();
    $this->actingAs($viewer)
        ->post(route('directory.files.store', $target), ['file' => directoryPdf()])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->phone)->toBe('0999999999')
        ->and(UserFile::query()->count())->toBe(1);
});

test('sans permission : aucune action, appels directs refusés', function (): void {
    $viewer = directoryUser();
    $target = directoryTarget();

    assertShowPermissions($this, $viewer, $target, canUpdate: false, canAttach: false);

    $this->actingAs($viewer)->get(route('directory.edit', $target))->assertDenied();
    $this->actingAs($viewer)
        ->put(route('directory.update', $target), ['phone' => '0999999999'])
        ->assertDenied();
    $this->actingAs($viewer)
        ->post(route('directory.files.store', $target), ['file' => directoryPdf()])
        ->assertDenied();

    expect($target->fresh()->phone)->toBe('0100000000')
        ->and(UserFile::query()->count())->toBe(0);
});

test('invité : redirigé vers la connexion', function (): void {
    $target = directoryTarget();

    $this->post(route('directory.files.store', $target), ['file' => directoryPdf()])
        ->assertRedirect(route('login'));
    $this->put(route('directory.update', $target), ['phone' => '0999999999'])
        ->assertRedirect(route('login'));
});

test('sa propre fiche reste éditable sans permission, mais sans ajout de pièce jointe', function (): void {
    $viewer = directoryUser();

    assertShowPermissions($this, $viewer, $viewer, canUpdate: true, canAttach: false);

    $this->actingAs($viewer)
        ->put(route('directory.update', $viewer), ['phone' => '0611223344'])
        ->assertRedirect();
    $this->actingAs($viewer)
        ->post(route('directory.files.store', $viewer), ['file' => directoryPdf()])
        ->assertDenied();

    expect($viewer->fresh()->phone)->toBe('0611223344');
});

test('ajout de pièce jointe : ne donne ni suppression ni renommage d\'une pièce existante', function (): void {
    $viewer = directoryUser(['directory.files.create']);
    $target = directoryTarget();

    $this->actingAs($viewer)
        ->post(route('directory.files.store', $target), ['file' => directoryPdf()]);
    $file = UserFile::query()->sole();

    $this->actingAs($viewer)
        ->delete(route('directory.files.destroy', [$target, $file]))
        ->assertDenied();
    $this->actingAs($viewer)
        ->put(route('directory.files.rename', [$target, $file]), ['display_name' => 'Autre'])
        ->assertDenied();

    expect($file->fresh())->not->toBeNull();
});

test('validation : type de fichier refusé', function (): void {
    $viewer = directoryUser(['directory.files.create']);
    $target = directoryTarget();

    $this->actingAs($viewer)
        ->post(route('directory.files.store', $target), [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ])
        ->assertSessionHasErrors('file');

    expect(UserFile::query()->count())->toBe(0);
});

test('validation : fichier de plus de 20 Mo refusé', function (): void {
    $viewer = directoryUser(['directory.files.create']);
    $target = directoryTarget();

    $this->actingAs($viewer)
        ->post(route('directory.files.store', $target), [
            'file' => UploadedFile::fake()->create('lourd.pdf', 20481, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');

    expect(UserFile::query()->count())->toBe(0);
});

test('validation : deux fichiers de même nom ne s\'écrasent pas', function (): void {
    $viewer = directoryUser(['directory.files.create']);
    $target = directoryTarget();

    $this->actingAs($viewer)->post(route('directory.files.store', $target), ['file' => directoryPdf()]);
    $this->actingAs($viewer)->post(route('directory.files.store', $target), ['file' => directoryPdf()]);

    $paths = UserFile::query()->pluck('path');
    expect($paths)->toHaveCount(2)
        ->and($paths->unique())->toHaveCount(2);
});

test('permission accordée par défaut de secteur ou exception utilisateur', function (): void {
    $viewer = directoryUser();
    $target = directoryTarget();

    SectorPermission::query()->create([
        'sector_id' => $viewer->sector_id,
        'ability' => 'directory.files.create',
    ]);

    assertShowPermissions($this, $viewer, $target, canUpdate: false, canAttach: true);
});

test('administrateur : conserve les deux actions', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $target = directoryTarget();

    assertShowPermissions($this, $admin, $target, canUpdate: true, canAttach: true);

    $this->actingAs($admin)
        ->post(route('directory.files.store', $target), ['file' => directoryPdf()])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)
        ->put(route('directory.update', $target), [
            'phone' => '0999999999',
            'email' => $target->email,
        ])
        ->assertRedirect();

    expect($target->fresh()->phone)->toBe('0999999999');
});

test('migration : idempotente et limitée au rôle admin', function (): void {
    $admin = Role::findOrCreate('admin', 'web');
    $utilisateur = Role::findOrCreate('utilisateur', 'web');
    $utilisateur->givePermissionTo(Permission::findOrCreate('directory.view', 'web'));

    $migration = require database_path('migrations/2026_09_28_100000_add_directory_permissions.php');
    $migration->up();
    $migration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::query()->whereIn('name', ['directory.update', 'directory.files.create'])->count())->toBe(2)
        ->and($admin->fresh()->hasPermissionTo('directory.update'))->toBeTrue()
        ->and($admin->fresh()->hasPermissionTo('directory.files.create'))->toBeTrue()
        ->and($utilisateur->fresh()->hasPermissionTo('directory.update'))->toBeFalse()
        ->and($utilisateur->fresh()->hasPermissionTo('directory.files.create'))->toBeFalse()
        ->and($utilisateur->fresh()->hasPermissionTo('directory.view'))->toBeTrue();
});
