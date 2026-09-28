<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Téléphones supplémentaires d'une fiche annuaire (users.directory_phones,
 * liste JSON). Contrat de l'endpoint directory.update :
 *  - clé absente           : liste inchangée ;
 *  - clé vide ('' ou [])   : tous les téléphones supprimés ;
 *  - liste                 : remplace intégralement la liste enregistrée.
 * Le formulaire envoie en FormData : une liste vide y part sous forme de
 * valeur vide (voir resources/js/__tests__/Directory/EditPhones.test.jsx).
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function phoneEditor(): User
{
    return directoryUser(['directory.update']);
}

function phoneTarget(array $phones): User
{
    return directoryTarget(['directory_phones' => $phones]);
}

function storedPhones(User $user): ?array
{
    return $user->fresh()->directory_phones;
}

test('régression : le téléphone TEST retiré du formulaire est réellement supprimé', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([['label' => 'TEST', 'number' => '0629073760']]);

    $this->actingAs($editor)
        ->get(route('directory.index', ['search' => '0629073760']))
        ->assertInertia(fn (Assert $page) => $page->where('directoryUsers.total', 1));

    // Avant correction, le formulaire n'envoyait aucune clé : la liste restait
    // inchangée alors que la réponse annonçait le succès.
    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['phone' => '0100000000'])
        ->assertSessionHas('status', 'Fiche enregistrée.');
    expect(storedPhones($target))->toBe([['label' => 'TEST', 'number' => '0629073760']]);

    // Requête telle que le formulaire corrigé l'envoie après « Retirer ».
    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['phone' => '0100000000', 'directory_phones' => ''])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Fiche enregistrée.');

    expect(storedPhones($target))->toBeNull();

    $this->actingAs($editor)
        ->get(route('directory.show', $target))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.phones', fn ($phones) => collect($phones)->doesntContain('number', '0629073760')));

    $this->actingAs($editor)
        ->get(route('directory.edit', $target))
        ->assertInertia(fn (Assert $page) => $page->where('profile.directory_phones', []));

    $this->actingAs($editor)
        ->get(route('directory.index', ['search' => '0629073760']))
        ->assertInertia(fn (Assert $page) => $page->where('directoryUsers.total', 0));
});

test('création d\'un téléphone', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'directory_phones' => [['label' => 'Perso', 'number' => '06 11 22 33 44']],
        ])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe([['label' => 'Perso', 'number' => '06 11 22 33 44']]);
});

test('modification du type d\'un téléphone', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([['label' => 'Perso', 'number' => '0611223344']]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'directory_phones' => [['label' => 'Astreinte', 'number' => '0611223344']],
        ])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe([['label' => 'Astreinte', 'number' => '0611223344']]);
});

test('modification du numéro d\'un téléphone', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([['label' => 'Perso', 'number' => '0611223344']]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'directory_phones' => [['label' => 'Perso', 'number' => '0799887766']],
        ])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe([['label' => 'Perso', 'number' => '0799887766']]);
});

test('suppression d\'un téléphone parmi plusieurs', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([
        ['label' => 'Perso', 'number' => '0611223344'],
        ['label' => 'TEST', 'number' => '0629073760'],
        ['label' => 'Bur.', 'number' => '1141'],
    ]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'directory_phones' => [
                ['label' => 'Perso', 'number' => '0611223344'],
                ['label' => 'Bur.', 'number' => '1141'],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe([
        ['label' => 'Perso', 'number' => '0611223344'],
        ['label' => 'Bur.', 'number' => '1141'],
    ]);
});

test('suppression du dernier téléphone (valeur vide envoyée par le formulaire)', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([['label' => 'Perso', 'number' => '0611223344']]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['directory_phones' => ''])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBeNull();
});

test('suppression de tous les téléphones avec un tableau explicitement vide', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([
        ['label' => 'Perso', 'number' => '0611223344'],
        ['label' => 'TEST', 'number' => '0629073760'],
    ]);

    $this->actingAs($editor)
        ->putJson(route('directory.update', $target), ['directory_phones' => []])
        ->assertRedirect();

    expect(storedPhones($target))->toBeNull();
});

test('ajout, modification et suppression dans une seule requête', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([
        ['label' => 'Perso', 'number' => '0611223344'],
        ['label' => 'TEST', 'number' => '0629073760'],
    ]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'directory_phones' => [
                ['label' => 'Personnel', 'number' => '0611223344'],
                ['label' => 'Astreinte', 'number' => '0788776655'],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe([
        ['label' => 'Personnel', 'number' => '0611223344'],
        ['label' => 'Astreinte', 'number' => '0788776655'],
    ]);
});

test('enregistrement sans la clé des téléphones : liste inchangée', function (): void {
    $editor = phoneEditor();
    $phones = [['label' => 'Perso', 'number' => '0611223344']];
    $target = phoneTarget($phones);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['internal_number' => '205'])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe($phones)
        ->and($target->fresh()->internal_number)->toBe('205');
});

test('enregistrement avec la liste inchangée : aucune modification', function (): void {
    $editor = phoneEditor();
    $phones = [['label' => 'Perso', 'number' => '0611223344']];
    $target = phoneTarget($phones);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['directory_phones' => $phones])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe($phones)
        ->and(DB::table('audit_logs')->where('action', 'update_directory_entry')->count())->toBe(0);
});

test('une ligne entièrement vide est ignorée', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'directory_phones' => [
                ['label' => 'Perso', 'number' => '0611223344'],
                ['label' => '', 'number' => ''],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(storedPhones($target))->toBe([['label' => 'Perso', 'number' => '0611223344']]);
});

test('téléphone invalide : erreur explicite et aucune sauvegarde partielle', function (array $row, string $field, string $message): void {
    $editor = phoneEditor();
    $phones = [['label' => 'TEST', 'number' => '0629073760']];
    $target = phoneTarget($phones);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'internal_number' => '999',
            'directory_phones' => [['label' => 'Perso', 'number' => '0611223344'], $row],
        ])
        ->assertSessionHasErrors(["directory_phones.1.{$field}" => $message])
        ->assertSessionMissing('status');

    expect(storedPhones($target))->toBe($phones)
        ->and($target->fresh()->internal_number)->toBeNull();
})->with([
    'libellé sans numéro' => [['label' => 'Astreinte', 'number' => ''], 'number', 'Le numéro est obligatoire.'],
    'numéro trop long' => [['label' => 'Perso', 'number' => str_repeat('1', 51)], 'number', 'Le numéro ne peut pas dépasser 50 caractères.'],
    'caractère de contrôle' => [['label' => 'Perso', 'number' => "06\x0011"], 'number', 'Le numéro contient des caractères interdits.'],
    'libellé trop long' => [['label' => str_repeat('a', 41), 'number' => '0700000000'], 'label', 'Le libellé ne peut pas dépasser 40 caractères.'],
    'doublon mis en forme différemment' => [['label' => 'Pro', 'number' => '06.11.22.33.44'], 'number', 'Ce numéro figure déjà dans la liste.'],
]);

test('plus de dix téléphones refusés', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([]);
    $rows = array_map(fn (int $i): array => ['label' => 'N'.$i, 'number' => '06000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], range(1, 11));

    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['directory_phones' => $rows])
        ->assertSessionHasErrors(['directory_phones' => 'Dix téléphones au maximum.']);

    expect(storedPhones($target))->toBe([]);
});

test('identifiant falsifié : refusé, et la fiche d\'autrui reste intacte', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([['label' => 'Perso', 'number' => '0611223344']]);
    $other = phoneTarget([['label' => 'Autre', 'number' => '0655555555']]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'directory_phones' => [['id' => $other->id, 'user_id' => $other->id, 'label' => 'Pirate', 'number' => '0600000000']],
        ])
        ->assertSessionHasErrors(['directory_phones.0' => 'Ce téléphone est invalide.']);

    expect(storedPhones($target))->toBe([['label' => 'Perso', 'number' => '0611223344']])
        ->and(storedPhones($other))->toBe([['label' => 'Autre', 'number' => '0655555555']]);

    // Une mise à jour valide de la fiche ciblée ne touche que celle-ci.
    $this->actingAs($editor)
        ->put(route('directory.update', $target), ['directory_phones' => ''])
        ->assertSessionHasNoErrors();

    expect(storedPhones($other))->toBe([['label' => 'Autre', 'number' => '0655555555']]);
});

test('sans permission d\'édition : téléphones d\'autrui intouchables', function (array $abilities): void {
    $viewer = directoryUser($abilities);
    $phones = [['label' => 'TEST', 'number' => '0629073760']];
    $target = phoneTarget($phones);

    $this->actingAs($viewer)
        ->put(route('directory.update', $target), ['directory_phones' => ''])
        ->assertDenied();
    $this->actingAs($viewer)
        ->put(route('directory.update', $target), ['directory_phones' => [['label' => 'X', 'number' => '0600000000']]])
        ->assertDenied();

    expect(storedPhones($target))->toBe($phones);
})->with([
    'aucune permission' => [[]],
    'ajout de pièces jointes seul' => [['directory.files.create']],
]);

test('sa propre fiche : les téléphones restent gérables sans permission', function (): void {
    $viewer = directoryUser();
    $viewer->forceFill(['directory_phones' => [['label' => 'TEST', 'number' => '0629073760']]])->save();

    $this->actingAs($viewer)
        ->put(route('directory.update', $viewer), ['directory_phones' => ''])
        ->assertSessionHasNoErrors();

    expect(storedPhones($viewer))->toBeNull();
});

test('échec de l\'enregistrement : pas de succès, message d\'erreur, rien de modifié, erreur journalisée', function (): void {
    $editor = phoneEditor();
    $phones = [['label' => 'TEST', 'number' => '0629073760']];
    $target = phoneTarget($phones);

    User::saving(function (User $user) use ($target): void {
        if ((int) $user->id === (int) $target->id) {
            throw new RuntimeException('Panne simulée');
        }
    });

    $this->actingAs($editor)
        ->from(route('directory.edit', $target))
        ->put(route('directory.update', $target), ['directory_phones' => '', 'job_title' => 'Cariste'])
        ->assertRedirect(route('directory.edit', $target))
        ->assertSessionMissing('status')
        ->assertSessionHas('error', 'La fiche n’a pas pu être enregistrée. Aucune modification n’a été appliquée.');

    $fresh = DB::table('users')->where('id', $target->id)->first();
    expect(json_decode($fresh->directory_phones, true))->toBe($phones)
        ->and($fresh->job_title)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'error')->where('description', 'Panne simulée')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'update_directory_entry')->exists())->toBeFalse();
});

test('les autres champs de la fiche sont enregistrés avec les téléphones', function (): void {
    $editor = phoneEditor();
    $target = phoneTarget([['label' => 'TEST', 'number' => '0629073760']]);

    $this->actingAs($editor)
        ->put(route('directory.update', $target), [
            'phone' => '0238000000',
            'mobile_phone' => '0611111111',
            'internal_number' => '204',
            'job_title' => 'Chauffeur PL',
            'adr_valid_until' => '2027-03-01',
            'directory_phones' => [['label' => 'Astreinte', 'number' => '0788776655']],
        ])
        ->assertSessionHasNoErrors();

    $fresh = $target->fresh();
    expect($fresh->phone)->toBe('0238000000')
        ->and($fresh->mobile_phone)->toBe('0611111111')
        ->and($fresh->internal_number)->toBe('204')
        ->and($fresh->job_title)->toBe('Chauffeur PL')
        ->and($fresh->adr_valid_until?->toDateString())->toBe('2027-03-01')
        ->and($fresh->directory_phones)->toBe([['label' => 'Astreinte', 'number' => '0788776655']]);
});
