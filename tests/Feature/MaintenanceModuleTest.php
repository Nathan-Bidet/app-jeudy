<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\Depot;
use App\Models\MaintenanceTask;
use App\Models\Sector;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function maintenanceUser(array $abilities): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('maintenance-test-'.fake()->unique()->word(), 'web');

    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $user = User::factory()->create([
        'sector_id' => $sector->id,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function maintenancePayload(array $overrides = []): array
{
    return array_merge([
        'date' => '2026-09-01',
        'fin_date' => '2026-09-03',
        'due_date' => '2026-09-10',
        'assignee_user_id' => null,
        'assignee_label_free' => null,
        'depot_id' => null,
        'address_free' => '12 rue des Ateliers, 45000 Orléans',
        'task' => 'Révision du compresseur',
        'comment' => 'Prévoir la pièce de rechange',
        'comment_hidden' => false,
    ], $overrides);
}

function maintenanceTaskFor(User $creator, array $overrides = []): MaintenanceTask
{
    $task = new MaintenanceTask;
    $task->fill(array_merge([
        'date' => '2026-09-01',
        'task' => 'Tâche existante',
        'comment' => 'Commentaire confidentiel',
        'comment_hidden' => true,
    ], $overrides));
    $task->origin = $overrides['origin'] ?? MaintenanceTask::ORIGIN_CREATION;
    $task->created_by_user_id = $creator->id;
    $task->save();

    return $task->refresh();
}

/**
 * APP Jeudy transforme un 403 non-JSON en redirection porteuse d'un message
 * d'erreur (bootstrap/app.php). Les requêtes JSON, elles, conservent un vrai
 * 403 : c'est la forme la plus stricte pour prouver le refus côté serveur.
 */
function assertDeniedRedirect(\Illuminate\Testing\TestResponse $response): void
{
    $response->assertRedirect();
    $response->assertSessionHas('error');
}

/*
|--------------------------------------------------------------------------
| Permissions et accès aux routes
|--------------------------------------------------------------------------
*/

it('refuse l’accès au module sans la permission maintenance.view', function (): void {
    $user = maintenanceUser([]);

    $this->actingAs($user)
        ->getJson(route('maintenance.index'))
        ->assertForbidden();

    assertDeniedRedirect(
        $this->actingAs($user)->get(route('maintenance.index'))
    );

    $this->actingAs($user)
        ->getJson(route('maintenance.tasks.data'))
        ->assertForbidden();
});

it('affiche la page Maintenance avec la permission dédiée', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($user)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Maintenance/Index')
            ->where('permissions.can_create', false)
            ->where('permissions.can_request', false)
            ->where('permissions.can_point', false)
            ->where('permissions.can_view_hidden_comments', false)
        );
});

it('garde les cinq permissions indépendantes les unes des autres', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceTaskFor($user, ['comment_hidden' => false]);

    $this->actingAs($user)
        ->postJson(route('maintenance.tasks.store'), maintenancePayload())
        ->assertForbidden();

    $this->actingAs($user)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertForbidden();

    expect(MaintenanceTask::query()->count())->toBe(1);

    $user->givePermissionTo('maintenance.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Créer ne donne toujours pas le droit de pointer définitivement.
    $this->actingAs($user)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->pointed)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Création et demande
|--------------------------------------------------------------------------
*/

it('enregistre une création directe avec la permission de créer', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $depot = Depot::query()->create(['name' => 'Dépôt Nord', 'city' => 'Orléans']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'depot_id' => $depot->id,
            'assignee_label_free' => 'Entreprise Duval',
        ]))
        ->assertRedirect();

    $task = MaintenanceTask::query()->sole();

    expect($task->origin)->toBe(MaintenanceTask::ORIGIN_CREATION)
        ->and($task->requested_by_user_id)->toBeNull()
        ->and($task->created_by_user_id)->toBe($user->id)
        ->and($task->depot_id)->toBe($depot->id)
        ->and($task->assignee_user_id)->toBeNull()
        ->and($task->assignee_label_free)->toBe('Entreprise Duval')
        ->and($task->pointed)->toBeFalse()
        ->and($task->first_pointed_on)->toBeNull();
});

it('enregistre une demande et trace le demandeur', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertRedirect();

    $task = MaintenanceTask::query()->sole();

    expect($task->origin)->toBe(MaintenanceTask::ORIGIN_REQUEST)
        ->and($task->requested_by_user_id)->toBe($user->id)
        ->and($task->created_by_user_id)->toBe($user->id);
});

it('empêche un demandeur de forger une création directe', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($user)
        ->postJson(route('maintenance.tasks.store'), maintenancePayload([
            'origin' => MaintenanceTask::ORIGIN_CREATION,
        ]))
        ->assertForbidden();

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('ignore les champs de traçabilité envoyés depuis le frontend', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $intruder = maintenanceUser([]);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'created_by_user_id' => $intruder->id,
            'requested_by_user_id' => $intruder->id,
            'pointed' => true,
            'partially_pointed' => true,
            'first_pointed_on' => '2020-01-01',
            'position' => 999,
        ]))
        ->assertRedirect();

    $task = MaintenanceTask::query()->sole();

    expect($task->created_by_user_id)->toBe($user->id)
        ->and($task->requested_by_user_id)->toBeNull()
        ->and($task->pointed)->toBeFalse()
        ->and($task->partially_pointed)->toBeFalse()
        ->and($task->first_pointed_on)->toBeNull()
        ->and($task->position)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('exige la date principale et la description', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['date' => null, 'task' => null]))
        ->assertSessionHasErrors(['date', 'task']);
});

it('refuse une date de fin de période antérieure à la date de début', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-10',
            'fin_date' => '2026-09-01',
        ]))
        ->assertSessionHasErrors('fin_date');

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('accepte une date de fin de période absente ou égale à la date de début', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['fin_date' => null]))
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-05',
            'fin_date' => '2026-09-05',
        ]))
        ->assertSessionHasNoErrors();

    expect(MaintenanceTask::query()->count())->toBe(2);
});

it('accepte une tâche sans personne affectée', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    expect($task->assignee_user_id)->toBeNull()
        ->and($task->assignee_label_free)->toBeNull()
        ->and($task->assigneeType())->toBe('none');
});

it('refuse de renseigner à la fois un utilisateur et une personne libre', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser([]);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'assignee_label_free' => 'Entreprise Duval',
        ]))
        ->assertSessionHasErrors('assignee_label_free');
});

it('refuse un dépôt inexistant', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['depot_id' => 999999]))
        ->assertSessionHasErrors('depot_id');
});

it('valide le booléen de commentaire masqué', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['comment_hidden' => true]))
        ->assertSessionHasNoErrors();

    expect(MaintenanceTask::query()->sole()->comment_hidden)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Commentaire masqué — étanchéité côté serveur
|--------------------------------------------------------------------------
*/

it('ne transmet jamais un commentaire masqué sans la permission dédiée', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Secret industriel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $response = $this->actingAs($viewer)
        ->get(route('maintenance.index'))
        ->assertOk();

    // Indiscernable d'une tâche sans commentaire : ni contenu, ni drapeau
    // révélant qu'un commentaire existe.
    $response->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.tasks.0.comment_hidden', false)
        ->where('groups.0.tasks.0.comment', null)
    );

    // Aucune trace du contenu dans la réponse complète.
    expect($response->getContent())->not->toContain('Secret industriel');
});

it('ne transmet jamais un commentaire masqué via la réponse JSON', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Secret industriel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $response = $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data'))
        ->assertOk();

    $response->assertJsonMissing(['comment' => 'Secret industriel']);
    expect($response->getContent())->not->toContain('Secret industriel');

    $task = $response->json('groups.0.tasks.0');
    expect($task['comment'])->toBeNull()
        ->and($task)->not->toHaveKey('comment_withheld')
        ->and($task['comment_hidden'])->toBeFalse();
});

it('transmet le commentaire masqué à qui détient la permission', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Secret industriel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.comment_hidden.view']);

    $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data'))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.comment', 'Secret industriel')
        ->assertJsonPath('groups.0.tasks.0.comment_hidden', true);
});

it('transmet un commentaire non masqué à tout lecteur du module', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Information ordinaire',
        'comment_hidden' => false,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data'))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.comment', 'Information ordinaire')
        ->assertJsonPath('groups.0.tasks.0.comment_hidden', false);
});

it('exclut les commentaires masqués de la recherche sans la permission', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'task' => 'Tâche anodine',
        'comment' => 'Amiante détectée',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data', ['search' => 'Amiante', 'pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 0);

    $privileged = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.comment_hidden.view']);

    $this->actingAs($privileged)
        ->getJson(route('maintenance.tasks.data', ['search' => 'Amiante', 'pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 1);
});

/*
|--------------------------------------------------------------------------
| Pointage
|--------------------------------------------------------------------------
*/

it('réserve le pointage définitif à la permission dédiée et horodate le premier pointage', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceTaskFor($author, ['comment_hidden' => false]);

    $this->actingAs($author)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->pointed)->toBeFalse();

    $pointer = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->actingAs($pointer)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    $task->refresh();

    expect($task->pointed)->toBeTrue()
        ->and($task->pointed_by_user_id)->toBe($pointer->id)
        ->and($task->pointed_at)->not->toBeNull()
        ->and($task->first_pointed_on?->toDateString())->toBe(now()->toDateString());

    $firstPointedOn = $task->first_pointed_on->toDateString();

    // Dépointer ne doit pas effacer la date métier du premier pointage.
    $this->actingAs($pointer)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => false])
        ->assertRedirect();

    $task->refresh();

    expect($task->pointed)->toBeFalse()
        ->and($task->pointed_at)->toBeNull()
        ->and($task->first_pointed_on->toDateString())->toBe($firstPointedOn);
});

it('réserve le pointage partiel à la personne affectée', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceTaskFor($author, [
        'comment_hidden' => false,
        'assignee_user_id' => $assignee->id,
    ]);

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    $task->refresh();

    expect($task->partially_pointed)->toBeTrue()
        ->and($task->partially_pointed_by_user_id)->toBe($assignee->id)
        ->and($task->first_pointed_on?->toDateString())->toBe(now()->toDateString());
});

/*
|--------------------------------------------------------------------------
| Modification / suppression
|--------------------------------------------------------------------------
*/

it('ferme modification et suppression à qui ne peut pas créer', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceTaskFor($creator, ['comment_hidden' => false, 'task' => 'Contenu protégé']);

    // Ni un lecteur, ni un pointeur, ni un simple demandeur n'y touchent.
    foreach ([
        maintenanceUser(['maintenance.view', 'maintenance.view.all']),
        maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']),
        maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']),
    ] as $outsider) {
        $this->actingAs($outsider)
            ->putJson(route('maintenance.tasks.update', $task), maintenancePayload(['task' => 'Tentative']))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->deleteJson(route('maintenance.tasks.destroy', $task))
            ->assertForbidden();
    }

    expect($task->refresh()->task)->toBe('Contenu protégé')
        ->and(MaintenanceTask::query()->whereKey($task->id)->exists())->toBeTrue();
});

it('rend toute tâche modifiable et supprimable à qui peut créer', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceTaskFor($author, ['comment_hidden' => false]);

    // Un autre détenteur du droit de créer : la tâche n'est pas la sienne.
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($manager)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload(['task' => 'Description corrigée']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($task->refresh()->task)->toBe('Description corrigée')
        ->and($task->updated_by_user_id)->toBe($manager->id);

    $this->actingAs($manager)
        ->delete(route('maintenance.tasks.destroy', $task))
        ->assertRedirect();

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('limite un demandeur à ses propres tâches non pointées', function (): void {
    $owner = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $ownTask = maintenanceTaskFor($owner, ['comment_hidden' => false]);

    $someoneElse = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $otherTask = maintenanceTaskFor($someoneElse, ['comment_hidden' => false]);

    $this->actingAs($owner)
        ->put(route('maintenance.tasks.update', $ownTask), maintenancePayload(['task' => 'Ma correction']))
        ->assertRedirect();

    $this->actingAs($owner)
        ->putJson(route('maintenance.tasks.update', $otherTask), maintenancePayload(['task' => 'Intrusion']))
        ->assertForbidden();

    expect($otherTask->refresh()->task)->toBe('Tâche existante');

    $ownTask->forceFill(['pointed' => true])->save();

    $this->actingAs($owner)
        ->deleteJson(route('maintenance.tasks.destroy', $ownTask))
        ->assertForbidden();

    expect(MaintenanceTask::query()->whereKey($ownTask->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Phase 3 — interface : props, droits par tâche, formulaire
|--------------------------------------------------------------------------
*/

it('expose au menu la permission de voir le module', function (): void {
    $withAccess = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($withAccess)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions.maintenance_view', true)
        );

    // Sur une page accessible à tous, le drapeau reste faux : le menu ne
    // proposera pas l'entrée.
    $withoutAccess = maintenanceUser([]);

    $this->actingAs($withoutAccess)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions.maintenance_view', false)
        );
});

it('fournit au formulaire les utilisateurs de l’annuaire et les dépôts', function (): void {
    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $depot = Depot::query()->create([
        'name' => 'Dépôt Sud',
        'address_line1' => '3 route de Blois',
        'postal_code' => '41000',
        'city' => 'Blois',
    ]);

    $inactive = User::factory()->create(['is_active' => false, 'sector_id' => $viewer->sector_id]);

    $this->actingAs($viewer)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($viewer, $depot, $inactive) {
            $users = collect($page->toArray()['props']['reference']['assignee_users']);
            $depots = collect($page->toArray()['props']['reference']['depots']);

            expect($users->pluck('id'))->toContain($viewer->id)
                ->and($users->pluck('id'))->not->toContain($inactive->id)
                ->and($depots->pluck('id'))->toContain($depot->id);

            $placeMap = $page->toArray()['props']['reference']['depot_place_map'];
            expect($placeMap['Dépôt Sud'])->toContain('41000 Blois');
        });
});

it('propose en autocomplétion les adresses libres déjà saisies', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    maintenanceTaskFor($user, [
        'comment_hidden' => false,
        'address_free' => "Atelier central\n7 impasse du Pont",
    ]);

    $this->actingAs($user)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $suggestions = $page->toArray()['props']['reference']['place_suggestions'];

            expect($suggestions)->toContain('Atelier central')
                ->and($suggestions)->toContain('7 impasse du Pont');
        });
});

it('enregistre une période avec les deux dates et la date de fin souhaitée', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-07',
            'fin_date' => '2026-09-11',
            'due_date' => '2026-09-15',
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    expect($task->date->toDateString())->toBe('2026-09-07')
        ->and($task->fin_date->toDateString())->toBe('2026-09-11')
        ->and($task->due_date->toDateString())->toBe('2026-09-15');

    $this->actingAs($user)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.date_label', '07/09/2026')
        ->assertJsonPath('groups.0.tasks.0.fin_label', '11/09/2026')
        ->assertJsonPath('groups.0.tasks.0.due_label', '15/09/2026');
});

it('affecte un utilisateur de l’annuaire et le restitue dans le groupe', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = User::factory()->create([
        'first_name' => 'Camille',
        'last_name' => 'Roux',
        'is_active' => true,
        'sector_id' => $user->sector_id,
    ]);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(MaintenanceTask::query()->sole()->assigneeType())->toBe('user');

    $this->actingAs($user)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.assignee.type', 'user')
        ->assertJsonPath('groups.0.assignee.name', 'Camille Roux');
});

it('accepte une personne saisie librement', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_label_free' => 'SARL Legrand',
        ]))
        ->assertSessionHasNoErrors();

    expect(MaintenanceTask::query()->sole()->assigneeType())->toBe('free');

    $this->actingAs($user)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.assignee.type', 'free')
        ->assertJsonPath('groups.0.assignee.name', 'SARL Legrand');
});

it('regroupe sous « Non affectée » les tâches sans personne', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.assignee.type', 'none')
        ->assertJsonPath('groups.0.assignee.name', 'Non affectée');
});

it('construit un lieu cliquable à partir du dépôt et de l’adresse libre', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $depot = Depot::query()->create([
        'name' => 'Dépôt Est',
        'address_line1' => '9 avenue du Moulin',
        'postal_code' => '45200',
        'city' => 'Montargis',
        'gps_lat' => 47.9989,
        'gps_lng' => 2.7325,
    ]);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'depot_id' => $depot->id,
            'address_free' => 'Bâtiment B',
        ]))
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($user)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    $task = $response->json('groups.0.tasks.0');

    // Une seule destination GPS : l'adresse du dépôt. La précision de site
    // reste affichée à part, sans lien propre, pour ne pas faire ouvrir les
    // coordonnées du dépôt en cliquant dessus.
    expect($task['place'])->toContain('9 avenue du Moulin')
        ->and($task['place'])->not->toContain('Bâtiment B')
        ->and($task['address_free'])->toBe('Bâtiment B')
        ->and($task['address_free_is_detail'])->toBeTrue()
        ->and($task['depot']['gps'])->toBe(['lat' => 47.9989, 'lng' => 2.7325]);
});

it('n’ouvre modification et suppression qu’au demandeur, sur sa demande', function (): void {
    $owner = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $other = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($owner)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload(['task' => 'Ma demande']))
        ->assertSessionHasNoErrors();

    $this->actingAs($other)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload(['task' => 'Sa demande']))
        ->assertSessionHasNoErrors();

    $mine = MaintenanceTask::query()->where('task', 'Ma demande')->sole();
    $theirs = MaintenanceTask::query()->where('task', 'Sa demande')->sole();

    $tasks = collect(
        $this->actingAs($owner)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk()
            ->json('groups.0.tasks')
    )->keyBy('id');

    expect($tasks[$mine->id]['can_update'])->toBeTrue()
        ->and($tasks[$mine->id]['can_delete'])->toBeTrue()
        ->and($tasks[$theirs->id]['can_update'])->toBeFalse()
        ->and($tasks[$theirs->id]['can_delete'])->toBeFalse();

    // Et la requête directe suit la même règle.
    $this->actingAs($owner)
        ->putJson(route('maintenance.tasks.update', $theirs), maintenanceRequestPayload(['task' => 'Intrusion']))
        ->assertForbidden();

    $this->actingAs($owner)
        ->deleteJson(route('maintenance.tasks.destroy', $theirs))
        ->assertForbidden();

    expect($theirs->refresh()->task)->toBe('Sa demande');
});

it('ne donne aucun droit de modification à un simple lecteur', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, ['comment_hidden' => false]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $response = $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    expect($response->json('groups.0.tasks.0.can_update'))->toBeFalse()
        ->and($response->json('groups.0.tasks.0.can_delete'))->toBeFalse();
});

it('conserve un commentaire masqué lorsqu’un éditeur non autorisé enregistre la tâche', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request', 'maintenance.comment_hidden.view']);
    $task = maintenanceTaskFor($author, [
        'comment' => 'Diagnostic confidentiel',
        'comment_hidden' => true,
        'origin' => MaintenanceTask::ORIGIN_REQUEST,
    ]);
    $task->forceFill(['requested_by_user_id' => $author->id])->save();

    // Un éditeur sans le droit de lecture : le champ commentaire lui arrive
    // vide. Le seul geste qui écrit encore sur une tâche est la conversion.
    $editor = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($editor)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'task' => 'Description corrigée',
            'comment' => '',
            'comment_hidden' => false,
            'convert' => true,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task->refresh();

    expect($task->task)->toBe('Description corrigée')
        ->and($task->comment)->toBe('Diagnostic confidentiel')
        ->and($task->comment_hidden)->toBeTrue();
});

it('laisse un éditeur autorisé modifier un commentaire masqué', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $task = maintenanceTaskFor($author, [
        'comment' => 'Ancien commentaire',
        'comment_hidden' => true,
        'origin' => MaintenanceTask::ORIGIN_REQUEST,
    ]);
    $task->forceFill(['requested_by_user_id' => $author->id])->save();

    $editor = maintenanceUser([
        'maintenance.view', 'maintenance.view.all',
        'maintenance.create',
        'maintenance.comment_hidden.view',
    ]);

    $this->actingAs($editor)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'comment' => 'Nouveau commentaire',
            'comment_hidden' => false,
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    $task->refresh();

    expect($task->comment)->toBe('Nouveau commentaire')
        ->and($task->comment_hidden)->toBeFalse();
});

it('filtre la liste par origine création ou demande', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['task' => 'Création directe']))
        ->assertSessionHasNoErrors();

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['task' => 'Demande']))
        ->assertSessionHasNoErrors();

    $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['origin' => 'request', 'pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 1)
        ->assertJsonPath('groups.0.tasks.0.is_request', true);

    $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['origin' => 'creation', 'pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 1)
        ->assertJsonPath('groups.0.tasks.0.is_request', false);
});

/*
|--------------------------------------------------------------------------
| Phase 4 — pointage partiel et définitif
|--------------------------------------------------------------------------
*/

function maintenanceAssignedTask(User $assignee, ?User $author = null): MaintenanceTask
{
    $author ??= maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    return maintenanceTaskFor($author, [
        'comment_hidden' => false,
        'assignee_user_id' => $assignee->id,
    ]);
}

it('refuse le pointage partiel à un utilisateur qui n’est pas l’affecté', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);

    $intruder = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($intruder)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('refuse le pointage partiel au responsable, même avec le pointage définitif', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);

    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->actingAs($manager)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('refuse le pointage partiel à un administrateur non affecté', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);

    $admin = maintenanceUser([]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('interdit tout pointage partiel sur une personne saisie librement', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceTaskFor($author, [
        'comment_hidden' => false,
        'assignee_label_free' => 'SARL Legrand',
    ]);

    foreach ([$author, maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point'])] as $candidate) {
        $this->actingAs($candidate)
            ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
            ->assertForbidden();
    }

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('interdit tout pointage partiel sur une tâche sans affectation', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceTaskFor($author, ['comment_hidden' => false]);

    $this->actingAs($author)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('montre au responsable l’état du partiel sans lui en donner le contrôle', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);

    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $before = $this->actingAs($manager)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    expect($before->json('groups.0.tasks.0.partially_pointed'))->toBeFalse()
        ->and($before->json('groups.0.tasks.0.can_partial_point'))->toBeFalse()
        ->and($before->json('groups.0.tasks.0.can_point'))->toBeTrue();

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    $after = $this->actingAs($manager)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    expect($after->json('groups.0.tasks.0.partially_pointed'))->toBeTrue()
        ->and($after->json('groups.0.tasks.0.partially_pointed_by'))->not->toBeNull()
        ->and($after->json('groups.0.tasks.0.can_partial_point'))->toBeFalse();
});

it('donne au porteur du pointage définitif un accès permanent à sa case', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);

    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    $task->refresh();

    expect($task->pointed)->toBeTrue()
        ->and($task->pointed_by_user_id)->toBe($manager->id);

    // L'affecté ne peut pas pointer définitivement.
    $this->actingAs($assignee)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => false])
        ->assertForbidden();

    expect($task->refresh()->pointed)->toBeTrue();
});

it('accepte un pointage définitif avant le partiel, sans les rendre exclusifs', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->travelTo('2026-09-04 09:00:00');

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    $this->travelTo('2026-09-06 09:00:00');

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    $task->refresh();

    // Les deux coexistent : le partiel n'écrase pas le définitif.
    expect($task->pointed)->toBeTrue()
        ->and($task->partially_pointed)->toBeTrue()
        ->and($task->first_pointed_on->toDateString())->toBe('2026-09-04');

    $this->travelBack();
});

it('accepte un pointage partiel avant le définitif et garde la première date', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->travelTo('2026-09-04 08:00:00');

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    expect($task->refresh()->first_pointed_on->toDateString())->toBe('2026-09-04');

    $this->travelTo('2026-09-06 08:00:00');

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    $task->refresh();

    // Le second pointage ne remplace pas la date métier.
    expect($task->pointed)->toBeTrue()
        ->and($task->partially_pointed)->toBeTrue()
        ->and($task->first_pointed_on->toDateString())->toBe('2026-09-04')
        ->and($task->pointed_at->toDateString())->toBe('2026-09-06')
        ->and($task->partially_pointed_at->toDateString())->toBe('2026-09-04');

    $this->travelBack();
});

it('sépare les horodatages techniques de la date métier', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->travelTo('2026-09-04 10:30:00');
    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();
    $this->travelBack();

    // Correction manuelle de la date métier : les traces techniques ne bougent pas.
    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => '2026-08-28'])
        ->assertRedirect();

    $task->refresh();

    expect($task->first_pointed_on->toDateString())->toBe('2026-08-28')
        ->and($task->first_pointed_on_manual)->toBeTrue()
        ->and($task->partially_pointed_at->toDateTimeString())->toBe('2026-09-04 10:30:00')
        ->and($task->partially_pointed_by_user_id)->toBe($assignee->id);
});

it('réserve la modification de la date métier au pointage définitif', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);

    $this->actingAs($assignee)
        ->patchJson(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => '2026-08-01'])
        ->assertForbidden();

    expect($task->refresh()->first_pointed_on)->toBeNull();
});

it('ne recalcule jamais une date métier fixée à la main', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => '2026-08-20'])
        ->assertRedirect();

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    expect($task->refresh()->first_pointed_on->toDateString())->toBe('2026-08-20');

    // Vidée manuellement, elle ne se repose pas non plus toute seule.
    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => null])
        ->assertRedirect();

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => false])
        ->assertRedirect();
    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    expect($task->refresh()->first_pointed_on)->toBeNull();
});

it('n’efface au décochage que les traces du pointage concerné', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->travelTo('2026-09-04 07:00:00');
    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();
    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();
    $this->travelBack();

    // Décocher le définitif laisse le partiel intact.
    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => false])
        ->assertRedirect();

    $task->refresh();

    expect($task->pointed)->toBeFalse()
        ->and($task->pointed_at)->toBeNull()
        ->and($task->pointed_by_user_id)->toBeNull()
        ->and($task->partially_pointed)->toBeTrue()
        ->and($task->partially_pointed_by_user_id)->toBe($assignee->id)
        ->and($task->first_pointed_on->toDateString())->toBe('2026-09-04');

    // Décocher le partiel laisse la date métier, seule trace du démarrage.
    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => false])
        ->assertRedirect();

    $task->refresh();

    expect($task->partially_pointed)->toBeFalse()
        ->and($task->partially_pointed_at)->toBeNull()
        ->and($task->partially_pointed_by_user_id)->toBeNull()
        ->and($task->first_pointed_on->toDateString())->toBe('2026-09-04');
});

it('rejette une requête de pointage forgée sans valeur booléenne', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->actingAs($assignee)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => 'peut-être'])
        ->assertStatus(422);

    $this->actingAs($manager)
        ->patchJson(route('maintenance.tasks.point', $task), [])
        ->assertStatus(422);

    $this->actingAs($manager)
        ->patchJson(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => 'hier'])
        ->assertStatus(422);

    $task->refresh();

    expect($task->partially_pointed)->toBeFalse()
        ->and($task->pointed)->toBeFalse()
        ->and($task->first_pointed_on)->toBeNull();
});

it('ignore les champs de pointage glissés dans une modification de tâche', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceAssignedTask($assignee, $author);

    $this->actingAs($author)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'pointed' => true,
            'partially_pointed' => true,
            'first_pointed_on' => '2020-01-01',
            'pointed_by_user_id' => $author->id,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task->refresh();

    expect($task->pointed)->toBeFalse()
        ->and($task->partially_pointed)->toBeFalse()
        ->and($task->first_pointed_on)->toBeNull()
        ->and($task->pointed_by_user_id)->toBeNull();
});

it('filtre les tâches par état de pointage', function (): void {
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point', 'maintenance.create']);

    $todo = maintenanceAssignedTask($assignee, $manager);
    $inProgress = maintenanceAssignedTask($assignee, $manager);
    $done = maintenanceAssignedTask($assignee, $manager);

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $inProgress), ['partially_pointed' => true])
        ->assertRedirect();

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $done), ['pointed' => true])
        ->assertRedirect();

    $collectIds = function (string $filter): array {
        $response = $this->actingAs(auth()->user())
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => $filter]))
            ->assertOk();

        return collect($response->json('groups'))
            ->flatMap(fn (array $group): array => $group['tasks'])
            ->pluck('id')
            ->all();
    };

    $this->actingAs($manager);

    expect($collectIds('unpointed'))->toContain($todo->id, $inProgress->id)
        ->and($collectIds('unpointed'))->not->toContain($done->id)
        ->and($collectIds('partial'))->toBe([$inProgress->id])
        ->and($collectIds('pointed'))->toBe([$done->id]);
});

/*
|--------------------------------------------------------------------------
| Phase 5 — notifications, workflow de demande et traçabilité
|--------------------------------------------------------------------------
*/

function maintenanceNotificationsOf(User $user): \Illuminate\Support\Collection
{
    return $user->notifications()->get()->map(fn ($n) => $n->data);
}

it('notifie les responsables du traitement lors d’une nouvelle demande', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $otherManager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $bystander = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $requester->forceFill(['first_name' => 'Léa', 'last_name' => 'Martin'])->save();

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['task' => 'Fuite au compresseur']))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    foreach ([$manager, $otherManager] as $recipient) {
        $data = maintenanceNotificationsOf($recipient)->sole();

        expect($data['type'])->toBe('maintenance_request_submitted')
            ->and($data['maintenance_task_id'])->toBe($task->id)
            ->and($data['requester_label'])->toBe('Léa Martin')
            ->and($data['message'])->toContain('Fuite au compresseur');
    }

    // Ni le demandeur, ni un simple lecteur ne sont notifiés.
    expect(maintenanceNotificationsOf($requester))->toBeEmpty()
        ->and(maintenanceNotificationsOf($bystander))->toBeEmpty();
});

it('ne notifie personne d’une création directe', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($manager))->toBeEmpty();
});

it('exclut des destinataires un responsable désactivé', function (): void {
    $active = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $inactive = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $inactive->forceFill(['is_active' => false])->save();

    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($active))->toHaveCount(1)
        ->and(maintenanceNotificationsOf($inactive))->toBeEmpty();
});

it('notifie l’utilisateur affecté à la création', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'task' => 'Remplacement du filtre',
        ]))
        ->assertSessionHasNoErrors();

    $data = maintenanceNotificationsOf($assignee)->sole();

    expect($data['type'])->toBe('maintenance_task_assigned')
        ->and($data['reason'])->toBe('assigned')
        ->and($data['message'])->toContain('Remplacement du filtre');
});

it('n’envoie aucune notification pour une affectation en texte libre ou vide', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $witness = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_label_free' => 'SARL Legrand',
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    expect(\Illuminate\Notifications\DatabaseNotification::query()->count())->toBe(0)
        ->and(maintenanceNotificationsOf($witness))->toBeEmpty();
});

it('ne notifie pas un créateur qui s’affecte la tâche à lui-même', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $creator->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($creator))->toBeEmpty();
});

it('informe l’affecté désigné au moment de la transformation', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    // Une tâche réelle n'étant plus modifiable, l'affectation se joue
    // désormais au moment de transformer la demande.
    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'assignee_user_id' => $assignee->id,
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($assignee)->pluck('reason')->all())->toBe(['assigned'])
        ->and(maintenanceNotificationsOf($requester)->pluck('reason')->all())->toBe(['converted']);
});

it('ne renotifie pas l’affecté quand aucun champ métier ne change', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();
    $task->forceFill(['assignee_user_id' => $assignee->id])->save();
    $assignee->notifications()->delete();

    // Le demandeur amende sa demande sans toucher à un champ métier.
    $this->actingAs($requester)
        ->put(route('maintenance.tasks.update', $task), maintenanceRequestPayload([
            'assignee_user_id' => $assignee->id,
            'comment' => 'Note ajoutée',
        ]))
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($assignee))->toBeEmpty();

    // La date souhaitée change : l'affecté doit le savoir.
    $this->actingAs($requester)
        ->put(route('maintenance.tasks.update', $task), maintenanceRequestPayload([
            'assignee_user_id' => $assignee->id,
            'comment' => 'Note ajoutée',
            'due_date' => '2026-10-01',
        ]))
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($assignee)->pluck('reason')->all())->toBe(['updated']);
});

it('ne laisse jamais fuiter un commentaire masqué dans une notification', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $secret = 'Amiante confirmée batiment C';

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'comment' => $secret,
            'comment_hidden' => true,
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'comment' => $secret,
            'comment_hidden' => true,
        ]))
        ->assertSessionHasNoErrors();

    // Aucune notification stockée, quel que soit le destinataire, ne contient
    // le commentaire — ni en clair, ni tronqué.
    $allPayloads = \Illuminate\Notifications\DatabaseNotification::query()->pluck('data')->map(
        fn ($data) => is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)
    );

    expect($allPayloads)->not->toBeEmpty();

    foreach ($allPayloads as $payload) {
        expect($payload)->not->toContain('Amiante')
            ->and($payload)->not->toContain('batiment C');
    }

    // Le destinataire non habilité ne voit rien non plus via le centre.
    $response = $this->actingAs($assignee)
        ->getJson(route('notifications.latest'))
        ->assertOk();

    expect($response->getContent())->not->toContain('Amiante');

    unset($manager, $task);
});

it('renvoie une notification de maintenance vers le module, pas vers les heures', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $assignee->id]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();
    $notification = $assignee->notifications()->sole();

    $this->actingAs($assignee)
        ->get(route('notifications.read_redirect', $notification->id))
        ->assertRedirect(route('maintenance.index', ['focus_task_id' => $task->id]));

    expect($assignee->notifications()->sole()->read_at)->not->toBeNull();
});

it('affiche la tâche visée par une notification quel que soit son pointage', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $assignee->id]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($creator)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    // Sans focus, le filtre par défaut masque une tâche terminée.
    $this->actingAs($assignee)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('meta.count_tasks', 0));

    $this->actingAs($assignee)
        ->get(route('maintenance.index', ['focus_task_id' => $task->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.count_tasks', 1)
            ->where('focus_task_id', $task->id)
        );
});

it('journalise la demande, sa transformation et le changement d’affectation', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($manager)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'assignee_user_id' => $assignee->id,
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    $actions = DB::table('audit_logs')->where('module', 'maintenance')->pluck('action')->all();

    expect($actions)->toContain('request_maintenance_task')
        ->and($actions)->toContain('update_maintenance_task')
        ->and($actions)->toContain('reassign_maintenance_task')
        ->and($actions)->toContain('convert_maintenance_request');

    $reassign = DB::table('audit_logs')->where('action', 'reassign_maintenance_task')->first();

    expect($reassign->description)->toContain('utilisateur #'.$assignee->id);
});

it('journalise les pointages et la date métier', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($manager)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $assignee->id]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => '2026-08-15'])
        ->assertRedirect();

    $actions = DB::table('audit_logs')->where('module', 'maintenance')->pluck('action')->all();

    expect($actions)->toContain('partial_point_maintenance_task')
        ->and($actions)->toContain('point_maintenance_task')
        ->and($actions)->toContain('update_maintenance_pointing_date');
});

it('n’écrit jamais un commentaire masqué en clair dans les logs', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create', 'maintenance.comment_hidden.view']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'comment' => 'Secret de maintenance',
            'comment_hidden' => true,
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'comment' => 'Autre secret',
            'comment_hidden' => true,
        ]))
        ->assertSessionHasNoErrors();

    $payloads = DB::table('audit_logs')->where('module', 'maintenance')->pluck('payload');

    expect($payloads)->not->toBeEmpty();

    foreach ($payloads as $payload) {
        expect((string) $payload)->not->toContain('Secret de maintenance')
            ->and((string) $payload)->not->toContain('Autre secret')
            ->and((string) $payload)->toContain('MASQU');
    }
});

it('journalise un commentaire visible sans le masquer', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'comment' => 'Commentaire ordinaire',
            'comment_hidden' => false,
        ]))
        ->assertSessionHasNoErrors();

    $payload = (string) DB::table('audit_logs')
        ->where('action', 'create_maintenance_task')
        ->value('payload');

    expect($payload)->toContain('Commentaire ordinaire');
});

/*
|--------------------------------------------------------------------------
| Phase 6 — cas limites d'affichage et lieux
|--------------------------------------------------------------------------
*/

it('affiche sans erreur une tâche affectée à un compte désactivé', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = User::factory()->create([
        'first_name' => 'Paul',
        'last_name' => 'Durand',
        'is_active' => true,
        'sector_id' => $creator->sector_id,
    ]);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $assignee->id]))
        ->assertSessionHasNoErrors();

    $assignee->forceFill(['is_active' => false])->save();

    $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.assignee.type', 'user')
        ->assertJsonPath('groups.0.assignee.name', 'Paul Durand');

    // Le compte désactivé n'est plus proposé à l'affectation : le formulaire
    // doit donc pouvoir le reconstituer depuis la tâche elle-même.
    $this->actingAs($creator)
        ->get(route('maintenance.index', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($assignee) {
            $ids = collect($page->toArray()['props']['reference']['assignee_users'])->pluck('id');
            expect($ids)->not->toContain($assignee->id);

            $task = $page->toArray()['props']['groups'][0]['tasks'][0];
            expect($task['assignee']['id'])->toBe($assignee->id)
                ->and($task['assignee']['name'])->toBe('Paul Durand');
        });
});

it('détache proprement une tâche dont l’utilisateur affecté est supprimé', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = User::factory()->create(['is_active' => true, 'sector_id' => $creator->sector_id]);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $assignee->id]))
        ->assertSessionHasNoErrors();

    $assignee->delete();

    expect(MaintenanceTask::query()->sole()->assignee_user_id)->toBeNull();

    $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.assignee.type', 'none')
        ->assertJsonPath('groups.0.assignee.name', 'Non affectée');
});

it('rend cliquable une adresse libre lorsqu’aucun dépôt n’est lié', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'address_free' => '4 rue des Vignes, 45000 Orléans',
        ]))
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    $task = $response->json('groups.0.tasks.0');

    expect($task['place'])->toBe('4 rue des Vignes, 45000 Orléans')
        ->and($task['address_free_is_detail'])->toBeFalse()
        ->and($task['depot'])->toBeNull();
});

it('n’expose aucun lieu quand ni dépôt ni adresse ne sont renseignés', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['address_free' => null]))
        ->assertSessionHasNoErrors();

    $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.place', null)
        ->assertJsonPath('groups.0.tasks.0.address_free_is_detail', false);
});

it('affiche une tâche sans commentaire sans clé fantôme', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['comment' => null]))
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    $task = $response->json('groups.0.tasks.0');

    expect($task['comment'])->toBeNull()
        ->and($task['comment_hidden'])->toBeFalse();
});

it('garde un nombre de requêtes stable quand la liste grandit', function (): void {
    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create', 'maintenance.point']);

    $seed = function (int $count) use ($viewer): void {
        for ($i = 0; $i < $count; $i++) {
            $assignee = User::factory()->create(['is_active' => true, 'sector_id' => $viewer->sector_id]);
            $task = new MaintenanceTask;
            $task->fill([
                'date' => '2026-09-0'.(($i % 9) + 1),
                'task' => 'Tâche '.$i,
                'assignee_user_id' => $assignee->id,
            ]);
            $task->created_by_user_id = $viewer->id;
            $task->save();
        }
    };

    $measure = function () use ($viewer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed(3);
    $small = $measure();

    $seed(27);
    $large = $measure();

    // Les habilitations du lecteur sont résolues une fois pour la liste : le
    // coût ne doit pas croître avec le nombre de tâches.
    expect($large)->toBeLessThanOrEqual($small + 5);
});

/*
|--------------------------------------------------------------------------
| Phase 7 — revue finale : intégrité et contournements
|--------------------------------------------------------------------------
*/

it('conserve une tâche et son pointage quand le compte créateur est supprimé', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($author)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'task' => 'Historique à préserver',
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    $author->delete();

    $task = MaintenanceTask::query()->find($task->id);

    // La tâche survit au départ de son auteur : seule la référence est vidée.
    expect($task)->not->toBeNull()
        ->and($task->created_by_user_id)->toBeNull()
        ->and($task->task)->toBe('Historique à préserver')
        ->and($task->partially_pointed)->toBeTrue()
        ->and($task->first_pointed_on)->not->toBeNull();

    // Et la liste continue de s'afficher sans erreur.
    $this->actingAs($assignee)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.created_by', null);
});

it('résiste aux contournements HTTP sur toutes les routes du module', function (): void {
    $owner = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($owner)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'comment' => 'Confidentiel absolu',
            'comment_hidden' => true,
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    // Un utilisateur sans aucune permission sur le module.
    $stranger = maintenanceUser([]);

    $refusals = [
        ['getJson', route('maintenance.index'), []],
        ['getJson', route('maintenance.tasks.data'), []],
        ['postJson', route('maintenance.tasks.store'), maintenancePayload()],
        ['putJson', route('maintenance.tasks.update', $task), maintenancePayload()],
        ['deleteJson', route('maintenance.tasks.destroy', $task), []],
        ['patchJson', route('maintenance.tasks.point', $task), ['pointed' => true]],
        ['patchJson', route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true]],
        ['patchJson', route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => '2020-01-01']],
    ];

    foreach ($refusals as [$method, $url, $payload]) {
        $this->actingAs($stranger)->{$method}($url, $payload)->assertForbidden();
    }

    // Un lecteur seul : il voit la page mais ne peut rien écrire.
    $reader = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    foreach (array_slice($refusals, 2) as [$method, $url, $payload]) {
        $this->actingAs($reader)->{$method}($url, $payload)->assertForbidden();
    }

    $task->refresh();

    expect($task->pointed)->toBeFalse()
        ->and($task->partially_pointed)->toBeFalse()
        ->and($task->first_pointed_on)->toBeNull()
        ->and($task->comment)->toBe('Confidentiel absolu')
        ->and(MaintenanceTask::query()->count())->toBe(1);

    // Et le contenu masqué n'a fuité par aucune des deux surfaces de lecture.
    $reader->givePermissionTo('maintenance.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $json = $this->actingAs($reader)->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']));
    $page = $this->actingAs($reader)->get(route('maintenance.index', ['pointed_filter' => 'all']));

    expect($json->getContent())->not->toContain('Confidentiel absolu')
        ->and($page->getContent())->not->toContain('Confidentiel absolu');
});

it('vérifie les cinq permissions une à une sur leur route dédiée', function (): void {
    $matrix = [
        'maintenance.view' => fn (User $u) => $this->actingAs($u)->getJson(route('maintenance.index')),
        'maintenance.create' => fn (User $u) => $this->actingAs($u)
            ->postJson(route('maintenance.tasks.store'), maintenancePayload()),
        'maintenance.request' => fn (User $u) => $this->actingAs($u)
            ->postJson(route('maintenance.tasks.store'), maintenancePayload()),
    ];

    foreach ($matrix as $ability => $call) {
        $without = maintenanceUser([]);
        expect($call($without)->status())->toBe(403);

        $with = maintenanceUser([$ability]);
        expect($call($with)->status())->not->toBe(403);
    }

    // Les deux dernières portent sur une tâche existante.
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $task = maintenanceTaskFor($author, [
        'comment' => 'Masqué',
        'comment_hidden' => true,
    ]);

    $withoutHidden = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $withHidden = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.comment_hidden.view']);

    // Plusieurs tâches coexistent : on cible celle qui porte le commentaire.
    $findTask = function (User $viewer) use ($task): array {
        $response = $this->actingAs($viewer)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk();

        return collect($response->json('groups'))
            ->flatMap(fn (array $group): array => $group['tasks'])
            ->firstWhere('id', $task->id);
    };

    expect($findTask($withoutHidden)['comment'])->toBeNull();
    expect($findTask($withHidden)['comment'])->toBe('Masqué');

    $withoutPoint = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $withPoint = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    expect($this->actingAs($withoutPoint)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])->status())->toBe(403);
    expect($this->actingAs($withPoint)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])->status())->not->toBe(403);
});

it('combine correctement demander et afficher les commentaires masqués', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request', 'maintenance.comment_hidden.view']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'comment' => 'Note masquée',
            'comment_hidden' => true,
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    expect($task->origin)->toBe(MaintenanceTask::ORIGIN_REQUEST)
        ->and($task->requested_by_user_id)->toBe($user->id);

    // Il relit son propre commentaire masqué, mais ne peut toujours pas pointer.
    $this->actingAs($user)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.comment', 'Note masquée');

    $this->actingAs($user)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Création réelle via Inertia — chaîne complète bouton → base
|--------------------------------------------------------------------------
*/

/**
 * Reproduit une requête telle que l'envoie le frontend Inertia (et non un
 * simple POST de formulaire), pour couvrir la chaîne exacte du navigateur.
 */
function maintenanceInertiaPost(array $payload): \Illuminate\Testing\TestResponse
{
    return test()->withHeaders([
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Referer' => route('maintenance.index'),
    ])->post(route('maintenance.tasks.store'), $payload);
}

it('crée réellement la tâche pour chaque combinaison du formulaire', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $depot = Depot::query()->create([
        'name' => 'Dépôt Central',
        'address_line1' => '1 rue du Test',
        'postal_code' => '45000',
        'city' => 'Orléans',
    ]);

    $this->actingAs($creator);

    // Le formulaire envoie exactement ces clés, et des dates au format ISO
    // produit par <input type="date">.
    $base = [
        'date' => '2026-09-10',
        'fin_date' => '',
        'due_date' => '',
        'assignee_user_id' => '',
        'assignee_label_free' => '',
        'depot_id' => '',
        'address_free' => '',
        'task' => 'Tâche de base',
        'comment' => '',
        'comment_hidden' => false,
        'origin' => 'creation',
    ];

    $scenarios = [
        'journée simple' => [],
        'période sur plusieurs jours' => ['date' => '2026-09-10', 'fin_date' => '2026-09-14'],
        'date de fin souhaitée' => ['due_date' => '2026-09-20'],
        'utilisateur affecté' => ['assignee_user_id' => (string) $assignee->id],
        'personne libre' => ['assignee_label_free' => 'SARL Legrand'],
        'sans personne affectée' => [],
        'avec dépôt' => ['depot_id' => (string) $depot->id],
        'avec adresse libre' => ['address_free' => "8 chemin des Prés\nBâtiment A"],
        'commentaire visible' => ['comment' => 'Commentaire visible', 'comment_hidden' => false],
        'commentaire masqué' => ['comment' => 'Commentaire masqué', 'comment_hidden' => true],
    ];

    $created = 0;

    foreach ($scenarios as $label => $overrides) {
        $payload = array_merge($base, $overrides, ['task' => 'Tâche — '.$label]);

        $response = maintenanceInertiaPost($payload);

        expect($response->status())
            ->toBeIn([302, 303], "scénario « {$label} » : réponse inattendue");

        $response->assertSessionHasNoErrors();

        $created++;

        // La ligne existe réellement en base, pas seulement en session.
        $task = MaintenanceTask::query()->where('task', 'Tâche — '.$label)->first();

        expect($task)->not->toBeNull("scénario « {$label} » : aucune ligne créée");
        expect(MaintenanceTask::query()->count())->toBe($created);
    }

    // Contrôle détaillé de quelques enregistrements.
    $periode = MaintenanceTask::query()->where('task', 'Tâche — période sur plusieurs jours')->sole();
    expect($periode->date->toDateString())->toBe('2026-09-10')
        ->and($periode->fin_date->toDateString())->toBe('2026-09-14');

    $affecte = MaintenanceTask::query()->where('task', 'Tâche — utilisateur affecté')->sole();
    expect($affecte->assignee_user_id)->toBe($assignee->id)
        ->and($affecte->assignee_label_free)->toBeNull();

    $libre = MaintenanceTask::query()->where('task', 'Tâche — personne libre')->sole();
    expect($libre->assignee_user_id)->toBeNull()
        ->and($libre->assignee_label_free)->toBe('SARL Legrand');

    $sansPersonne = MaintenanceTask::query()->where('task', 'Tâche — sans personne affectée')->sole();
    expect($sansPersonne->assigneeType())->toBe('none');

    $avecDepot = MaintenanceTask::query()->where('task', 'Tâche — avec dépôt')->sole();
    expect($avecDepot->depot_id)->toBe($depot->id);

    $masque = MaintenanceTask::query()->where('task', 'Tâche — commentaire masqué')->sole();
    expect($masque->comment)->toBe('Commentaire masqué')
        ->and($masque->comment_hidden)->toBeTrue();

    // Après rechargement de la page, les tâches sont toujours là. On repart
    // d'en-têtes vierges : une navigation normale, pas une requête Inertia.
    $this->flushHeaders();

    $this->get(route('maintenance.index', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('meta.count_tasks', count($scenarios)));
});

it('renvoie des erreurs exploitables plutôt qu’un échec silencieux', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $this->actingAs($creator);

    // Le navigateur ne filtre plus rien : la requête part et le serveur répond.
    $response = maintenanceInertiaPost([
        'date' => '',
        'task' => '',
        'comment_hidden' => false,
        'origin' => 'creation',
    ]);

    $response->assertSessionHasErrors(['date', 'task']);

    expect(MaintenanceTask::query()->count())->toBe(0);

    // Période incohérente : refusée par le serveur, avec un message par champ.
    maintenanceInertiaPost([
        'date' => '2026-09-14',
        'fin_date' => '2026-09-10',
        'task' => 'Période inversée',
        'comment_hidden' => false,
        'origin' => 'creation',
    ])->assertSessionHasErrors('fin_date');

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('confirme le succès par le message flash utilisé partout dans l’application', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $this->actingAs($creator);

    maintenanceInertiaPost([
        'date' => '2026-09-10',
        'task' => 'Tâche avec retour de succès',
        'comment_hidden' => false,
        'origin' => 'creation',
    ])->assertSessionHas('status', 'Tâche Maintenance enregistrée.');

    expect(MaintenanceTask::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Invisibilité complète d'un commentaire masqué
|--------------------------------------------------------------------------
*/

it('rend une tâche à commentaire masqué indiscernable d’une tâche sans commentaire', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $withHiddenComment = maintenanceTaskFor($author, [
        'task' => 'Tâche identique',
        'comment' => 'Contenu strictement confidentiel',
        'comment_hidden' => true,
    ]);

    $withoutComment = maintenanceTaskFor($author, [
        'task' => 'Tâche identique',
        'comment' => null,
        'comment_hidden' => false,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $response = $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    $tasks = collect($response->json('groups'))
        ->flatMap(fn (array $group): array => $group['tasks'])
        ->keyBy('id');

    $hidden = $tasks[$withHiddenComment->id];
    $plain = $tasks[$withoutComment->id];

    // On neutralise ce qui diffère légitimement, puis on compare le reste.
    foreach (['id', 'position'] as $key) {
        unset($hidden[$key], $plain[$key]);
    }

    expect($hidden)->toEqual($plain);

    // Aucune clé ne trahit l'existence d'un commentaire.
    expect($hidden['comment'])->toBeNull()
        ->and(array_keys($hidden))->not->toContain('comment_withheld')
        ->and($hidden['comment_hidden'])->toBeFalse();
});

it('ne laisse rien deviner dans le HTML rendu à un lecteur non autorisé', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'task' => 'Revision annuelle',
        'comment' => 'Contenu strictement confidentiel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $html = $this->actingAs($viewer)
        ->get(route('maintenance.index', ['pointed_filter' => 'all']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Revision annuelle')
        ->and($html)->not->toContain('Contenu strictement confidentiel')
        ->and($html)->not->toContain('Commentaire masqué')
        ->and($html)->not->toContain('comment_withheld');
});

it('conserve l’affichage normal pour un lecteur autorisé', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Contenu strictement confidentiel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.comment_hidden.view']);

    $task = $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups.0.tasks.0');

    // Contenu transmis, et le drapeau de masquage lui reste utile pour
    // afficher la mention « Masqué » et cocher la case du formulaire.
    expect($task['comment'])->toBe('Contenu strictement confidentiel')
        ->and($task['comment_hidden'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Avatars et priorité d'affichage des demandes
|--------------------------------------------------------------------------
*/

it('expose la photo de profil de la personne affectée', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $withPhoto = User::factory()->create([
        'is_active' => true,
        'sector_id' => $creator->sector_id,
        'photo_path' => 'photos/alice.jpg',
    ]);
    $withoutPhoto = User::factory()->create([
        'is_active' => true,
        'sector_id' => $creator->sector_id,
        'photo_path' => null,
    ]);

    $this->actingAs($creator);

    foreach ([$withPhoto->id, $withoutPhoto->id] as $id) {
        $this->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $id]))
            ->assertSessionHasNoErrors();
    }

    $this->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_label_free' => 'SARL Legrand']))
        ->assertSessionHasNoErrors();
    $this->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    $groups = collect(
        $this->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk()
            ->json('groups')
    )->keyBy(fn (array $group): string => $group['assignee']['type'].':'.($group['assignee']['id'] ?? ''));

    // Même résolution d'URL que le Livre du travail : /storage/ pour un chemin relatif.
    expect($groups["user:{$withPhoto->id}"]['assignee']['photo_url'])->toBe('/storage/photos/alice.jpg')
        ->and($groups["user:{$withoutPhoto->id}"]['assignee']['photo_url'])->toBeNull()
        ->and($groups['free:']['assignee']['photo_url'])->toBeNull()
        ->and($groups['none:']['assignee']['photo_url'])->toBeNull();
});

it('remonte les demandes en tête, avant les tâches créées directement', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    // Créée en premier et à une date antérieure : sans priorité, elle passerait devant.
    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-01',
            'task' => 'Création directe',
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-20',
            'fin_date' => null,
            'task' => 'Demande tardive',
        ]))
        ->assertSessionHasNoErrors();

    $groups = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups');

    expect($groups[0]['is_request'])->toBeTrue()
        ->and($groups[0]['tasks'][0]['task'])->toBe('Demande tardive')
        ->and($groups[1]['is_request'])->toBeFalse()
        ->and($groups[1]['tasks'][0]['task'])->toBe('Création directe');
});

it('ne mélange jamais demande et création dans un même groupe', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    // Même date, même personne affectée : seul l'origine les sépare.
    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'task' => 'Classique',
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'task' => 'Demandée',
        ]))
        ->assertSessionHasNoErrors();

    $groups = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(2);

    foreach ($groups as $group) {
        $origins = collect($group['tasks'])->pluck('is_request')->unique();
        expect($origins)->toHaveCount(1)
            ->and($origins->first())->toBe($group['is_request']);
    }
});

it('regroupe plusieurs demandes de même date et même personne', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($requester);

    foreach (['Première demande', 'Seconde demande'] as $label) {
        $this->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'task' => $label,
        ]))->assertSessionHasNoErrors();
    }

    $groups = $this->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['is_request'])->toBeTrue()
        ->and($groups[0]['tasks'])->toHaveCount(2);
});

it('conserve la priorité des demandes sous filtre actif', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-02',
            'task' => 'Classique filtrée',
            'address_free' => 'Atelier commun',
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-25',
            'fin_date' => null,
            'task' => 'Demande filtrée',
            'address_free' => 'Atelier commun',
        ]))
        ->assertSessionHasNoErrors();

    // Une tâche hors filtre, pour vérifier que le filtre s'applique bien.
    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['task' => 'Hors périmètre']))
        ->assertSessionHasNoErrors();

    $groups = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['search' => 'Atelier commun', 'pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['is_request'])->toBeTrue()
        ->and($groups[1]['is_request'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Workflow de demande simplifié et transformation en tâche
|--------------------------------------------------------------------------
*/

function maintenanceRequestPayload(array $overrides = []): array
{
    return array_merge([
        'due_date' => '2026-09-10',
        'depot_id' => null,
        'task' => 'Réparer la porte de l’atelier',
        'origin' => MaintenanceTask::ORIGIN_REQUEST,
    ], $overrides);
}

it('enregistre une demande avec ses trois seuls champs', function (): void {
    $depot = Depot::query()->create(['name' => 'Dépôt Nord', 'city' => 'Orléans']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $requester->forceFill(['depot_id' => $depot->id])->save();

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload(['depot_id' => $depot->id]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    expect($task->origin)->toBe(MaintenanceTask::ORIGIN_REQUEST)
        ->and($task->requested_by_user_id)->toBe($requester->id)
        ->and($task->due_date->toDateString())->toBe('2026-09-10')
        ->and($task->depot_id)->toBe($depot->id)
        ->and($task->task)->toBe('Réparer la porte de l’atelier')
        ->and($task->isPendingRequest())->toBeTrue()
        // Les champs non saisis restent vides, sans valeur inventée.
        ->and($task->date)->toBeNull()
        ->and($task->fin_date)->toBeNull()
        ->and($task->assignee_user_id)->toBeNull()
        ->and($task->assignee_label_free)->toBeNull()
        ->and($task->address_free)->toBeNull()
        ->and($task->comment)->toBeNull()
        ->and($task->comment_hidden)->toBeFalse();
});

it('accepte une demande d’un utilisateur sans dépôt rattaché', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    expect($requester->depot_id)->toBeNull();

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    expect(MaintenanceTask::query()->sole()->depot_id)->toBeNull();
});

it('expose au formulaire le dépôt de rattachement du demandeur', function (): void {
    $depot = Depot::query()->create(['name' => 'Dépôt Sud', 'city' => 'Blois']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $requester->forceFill(['depot_id' => $depot->id])->save();

    $this->actingAs($requester)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reference.current_user_depot_id', $depot->id)
        );
});

it('exige la date souhaitée et la description sur une demande', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload([
            'due_date' => null,
            'task' => null,
        ]))
        ->assertSessionHasErrors(['due_date', 'task']);

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('n’exige pas de date de début sur une demande, mais l’exige sur une création', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['date' => null]))
        ->assertSessionHasErrors('date');
});

it('regroupe toutes les demandes en attente en tête, sans date ni affectation', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($requester);
    foreach (['2026-09-15', '2026-09-05'] as $due) {
        $this->post(route('maintenance.tasks.store'), maintenanceRequestPayload([
            'due_date' => $due,
            'task' => 'Demande '.$due,
        ]))->assertSessionHasNoErrors();
    }

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['task' => 'Tâche classique']))
        ->assertSessionHasNoErrors();

    $groups = $this->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups');

    // Un seul groupe pour toutes les demandes, en tête.
    expect($groups[0]['is_request'])->toBeTrue()
        ->and($groups[0]['tasks'])->toHaveCount(2)
        ->and($groups[0]['date'])->toBeNull()
        // Triées par date souhaitée.
        ->and($groups[0]['tasks'][0]['task'])->toBe('Demande 2026-09-05')
        ->and($groups[0]['tasks'][1]['task'])->toBe('Demande 2026-09-15')
        ->and($groups[1]['is_request'])->toBeFalse();
});

it('n’ouvre la transformation qu’à qui peut créer une tâche', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    // Le demandeur ne valide pas sa propre demande.
    $own = $this->actingAs($requester)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->json('groups.0.tasks.0');
    expect($own['can_convert'])->toBeFalse();

    $this->actingAs($requester)
        ->putJson(route('maintenance.tasks.update', $task), maintenancePayload([
            'task' => 'Tentative de conversion',
            'convert' => true,
        ]))
        ->assertForbidden();

    expect($task->refresh()->converted_at)->toBeNull();

    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $seen = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->json('groups.0.tasks.0');

    expect($seen['can_convert'])->toBeTrue();
});

it('transforme la demande en tâche sur la même ligne, sans doublon', function (): void {
    $depot = Depot::query()->create(['name' => 'Dépôt Est', 'city' => 'Montargis']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload([
            'due_date' => '2026-09-10',
            'depot_id' => $depot->id,
            'task' => 'Réparer la porte',
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'due_date' => '2026-09-10',
            'depot_id' => $depot->id,
            'task' => 'Réparer la porte',
            'assignee_user_id' => $assignee->id,
            'convert' => true,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Une seule ligne : la demande est devenue la tâche.
    expect(MaintenanceTask::query()->count())->toBe(1);

    $task->refresh();

    expect($task->converted_at)->not->toBeNull()
        ->and($task->converted_by_user_id)->toBe($creator->id)
        ->and($task->isPendingRequest())->toBeFalse()
        // Provenance conservée.
        ->and($task->origin)->toBe(MaintenanceTask::ORIGIN_REQUEST)
        ->and($task->requested_by_user_id)->toBe($requester->id)
        ->and($task->date->toDateString())->toBe('2026-09-08')
        ->and($task->assignee_user_id)->toBe($assignee->id);

    // Elle a quitté la zone des demandes et rejoint les groupes ordinaires.
    $groups = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['is_request'])->toBeFalse()
        ->and($groups[0]['tasks'][0]['came_from_request'])->toBeTrue()
        ->and($groups[0]['tasks'][0]['can_convert'])->toBeFalse();
});

it('prévient le demandeur que sa demande est prise en charge, une seule fois', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();
    $requester->notifications()->delete();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'task' => 'Réparer la porte',
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    $reasons = maintenanceNotificationsOf($requester)->pluck('reason')->all();

    expect($reasons)->toBe(['converted']);

    // Le demandeur affecté à sa propre demande n'est pas prévenu deux fois.
    $other = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $this->actingAs($other)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload(['task' => 'Seconde demande']))
        ->assertSessionHasNoErrors();

    $second = MaintenanceTask::query()->where('task', 'Seconde demande')->sole();
    $other->notifications()->delete();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $second), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'task' => 'Seconde demande',
            'assignee_user_id' => $other->id,
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($other)->pluck('reason')->all())->toBe(['assigned']);
});

it('journalise la transformation d’une demande', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'task' => 'Réparer la porte',
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    expect(DB::table('audit_logs')->where('module', 'maintenance')->pluck('action')->all())
        ->toContain('convert_maintenance_request');
});

/*
|--------------------------------------------------------------------------
| Verrouillage des tâches réelles, ouverture des demandes
|--------------------------------------------------------------------------
*/

it('laisse le demandeur amender et retirer sa propre demande', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($requester)
        ->put(route('maintenance.tasks.update', $task), maintenanceRequestPayload([
            'task' => 'Demande corrigée',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($task->refresh()->task)->toBe('Demande corrigée');

    $this->actingAs($requester)
        ->delete(route('maintenance.tasks.destroy', $task))
        ->assertRedirect();

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('laisse qui peut créer amender ou écarter une demande sans la traiter', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenanceRequestPayload(['task' => 'Reformulée']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($task->refresh()->task)->toBe('Reformulée')
        // Amender n'est pas traiter : la demande reste en attente.
        ->and($task->isPendingRequest())->toBeTrue();

    $this->actingAs($creator)
        ->delete(route('maintenance.tasks.destroy', $task))
        ->assertRedirect();

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('ferme la demande à son demandeur dès qu’elle est transformée', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    // Le demandeur n'a plus la main sur ce qui est devenu une tâche.
    $this->actingAs($requester)
        ->putJson(route('maintenance.tasks.update', $task), maintenanceRequestPayload(['task' => 'Trop tard']))
        ->assertForbidden();

    $this->actingAs($requester)
        ->deleteJson(route('maintenance.tasks.destroy', $task))
        ->assertForbidden();

    // Et on ne la transforme pas deux fois.
    $this->actingAs($creator)
        ->putJson(route('maintenance.tasks.update', $task), maintenancePayload(['convert' => true]))
        ->assertForbidden();

    expect(MaintenanceTask::query()->count())->toBe(1);
});

it('laisse intactes les actions de pointage, indépendamment du droit de créer', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view', 'maintenance.view.all']);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $assignee->id]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    // Le pointeur ne peut pas modifier le contenu…
    $this->actingAs($manager)
        ->putJson(route('maintenance.tasks.update', $task), maintenancePayload(['task' => 'Tentative']))
        ->assertForbidden();

    // …mais les trois gestes de pointage fonctionnent toujours.
    $this->actingAs($assignee)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertRedirect();

    $this->actingAs($manager)
        ->patch(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => '2026-09-01'])
        ->assertRedirect();

    $task->refresh();

    expect($task->partially_pointed)->toBeTrue()
        ->and($task->pointed)->toBeTrue()
        ->and($task->first_pointed_on->toDateString())->toBe('2026-09-01');
});

it('expose modification et suppression selon le droit de créer', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create', 'maintenance.point']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    $seenByCreator = $this->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups.0.tasks.0');

    expect($seenByCreator['can_update'])->toBeTrue()
        ->and($seenByCreator['can_delete'])->toBeTrue()
        ->and($seenByCreator['can_convert'])->toBeFalse()
        ->and($seenByCreator['can_point'])->toBeTrue();

    // Sans le droit de créer, aucun des deux.
    $pointer = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.point']);

    $seenByPointer = $this->actingAs($pointer)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups.0.tasks.0');

    expect($seenByPointer['can_update'])->toBeFalse()
        ->and($seenByPointer['can_delete'])->toBeFalse()
        ->and($seenByPointer['can_point'])->toBeTrue();
});

it('ouvre modification, suppression et transformation à qui peut créer, sur une demande', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.request']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload())
        ->assertSessionHasNoErrors();

    $seen = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->json('groups.0.tasks.0');

    expect($seen['can_update'])->toBeTrue()
        ->and($seen['can_delete'])->toBeTrue()
        ->and($seen['can_convert'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Périmètre de visibilité — « voir toutes les tâches »
|--------------------------------------------------------------------------
*/

/** Lecteur restreint : accès à la page, sans « voir toutes les tâches ». */
function maintenanceRestrictedUser(array $extra = []): User
{
    return maintenanceUser(array_merge(['maintenance.view'], $extra));
}

it('limite un lecteur restreint à ce qui le concerne', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $alice = maintenanceRestrictedUser();
    $said = maintenanceRestrictedUser();

    // 1. Une tâche affectée à Alice, créée par quelqu'un d'autre.
    $this->actingAs($manager)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $alice->id,
            'task' => 'Affectée à Alice',
        ]))
        ->assertSessionHasNoErrors();

    // 2. Une tâche qui ne la concerne en rien.
    $this->actingAs($manager)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $said->id,
            'task' => 'Affectée à Saïd',
        ]))
        ->assertSessionHasNoErrors();

    $visible = collect(
        $this->actingAs($alice)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk()
            ->json('groups')
    )->flatMap(fn (array $group): array => $group['tasks'])->pluck('task');

    expect($visible->all())->toBe(['Affectée à Alice']);
});

it('montre au demandeur sa demande, puis la tâche qui en découle', function (): void {
    $alice = maintenanceRestrictedUser(['maintenance.request']);
    $nathan = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $said = maintenanceRestrictedUser();
    $spectator = maintenanceRestrictedUser();

    $this->actingAs($alice)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload(['task' => 'Demande d’Alice']))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $seenBy = fn (User $user): array => collect(
        $this->actingAs($user)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk()
            ->json('groups')
    )->flatMap(fn (array $group): array => $group['tasks'])->pluck('task')->all();

    // La demande n'est visible que de son auteur.
    expect($seenBy($alice))->toBe(['Demande d’Alice'])
        ->and($seenBy($spectator))->toBe([])
        ->and($seenBy($said))->toBe([]);

    // Nathan la transforme et l'affecte à Saïd.
    $this->actingAs($nathan)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'date' => '2026-09-08',
            'fin_date' => null,
            'assignee_user_id' => $said->id,
            'task' => 'Demande d’Alice',
            'convert' => true,
        ]))
        ->assertSessionHasNoErrors();

    // Alice la garde en vue, car elle en est à l'origine ; Saïd la voit comme
    // affecté ; le spectateur ne voit toujours rien.
    expect($seenBy($alice))->toBe(['Demande d’Alice'])
        ->and($seenBy($said))->toBe(['Demande d’Alice'])
        ->and($seenBy($spectator))->toBe([]);
});

it('donne à « voir toutes les tâches » la liste entière', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $alice = maintenanceRestrictedUser(['maintenance.request']);
    $said = maintenanceRestrictedUser();

    $this->actingAs($manager)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $said->id,
            'task' => 'Tâche de Saïd',
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($alice)
        ->post(route('maintenance.tasks.store'), maintenanceRequestPayload(['task' => 'Demande d’Alice']))
        ->assertSessionHasNoErrors();

    // Un lecteur doté de la permission voit tout, demandes comprises.
    $observer = maintenanceUser(['maintenance.view', 'maintenance.view.all']);

    $seen = collect(
        $this->actingAs($observer)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk()
            ->json('groups')
    )->flatMap(fn (array $group): array => $group['tasks'])->pluck('task')->sort()->values();

    expect($seen->all())->toBe(['Demande d’Alice', 'Tâche de Saïd']);
});

it('laisse un administrateur tout voir sans permission explicite', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $said = maintenanceRestrictedUser();

    $this->actingAs($manager)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $said->id,
            'task' => 'Tâche de Saïd',
        ]))
        ->assertSessionHasNoErrors();

    $admin = maintenanceUser([]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 1);
});

it('calcule compteurs et groupes sur le seul périmètre visible', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $alice = maintenanceRestrictedUser();

    $this->actingAs($manager);

    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        $this->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => $date,
            'fin_date' => null,
            'task' => 'Tâche du '.$date,
        ]))->assertSessionHasNoErrors();
    }

    $this->post(route('maintenance.tasks.store'), maintenancePayload([
        'assignee_user_id' => $alice->id,
        'task' => 'La seule d’Alice',
    ]))->assertSessionHasNoErrors();

    // Le gestionnaire voit les quatre, réparties en quatre groupes.
    $this->actingAs($manager)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 4)
        ->assertJsonPath('meta.count_groups', 4);

    // Alice ne compte que la sienne : le total de l'entreprise ne lui échappe pas.
    $this->actingAs($alice)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 1)
        ->assertJsonPath('meta.count_groups', 1);
});

it('applique les filtres à l’intérieur du périmètre visible', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $alice = maintenanceRestrictedUser();

    $this->actingAs($manager);

    $this->post(route('maintenance.tasks.store'), maintenancePayload([
        'assignee_user_id' => $alice->id,
        'task' => 'Compresseur d’Alice',
        'address_free' => 'Atelier central',
    ]))->assertSessionHasNoErrors();

    $this->post(route('maintenance.tasks.store'), maintenancePayload([
        'task' => 'Compresseur des autres',
        'address_free' => 'Atelier central',
    ]))->assertSessionHasNoErrors();

    // La recherche porte sur les deux, mais Alice n'en récupère qu'une.
    $this->actingAs($alice)
        ->getJson(route('maintenance.tasks.data', ['search' => 'Compresseur', 'pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 1)
        ->assertJsonPath('groups.0.tasks.0.task', 'Compresseur d’Alice');

    $this->actingAs($manager)
        ->getJson(route('maintenance.tasks.data', ['search' => 'Compresseur', 'pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 2);
});

it('borne les suggestions d’adresses au périmètre visible', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.view.all', 'maintenance.create']);
    $alice = maintenanceRestrictedUser();

    $this->actingAs($manager);

    $this->post(route('maintenance.tasks.store'), maintenancePayload([
        'assignee_user_id' => $alice->id,
        'address_free' => 'Atelier visible',
    ]))->assertSessionHasNoErrors();

    $this->post(route('maintenance.tasks.store'), maintenancePayload([
        'address_free' => 'Atelier confidentiel',
    ]))->assertSessionHasNoErrors();

    $this->actingAs($alice)
        ->get(route('maintenance.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $suggestions = $page->toArray()['props']['reference']['place_suggestions'];

            expect($suggestions)->toContain('Atelier visible')
                ->and($suggestions)->not->toContain('Atelier confidentiel');
        });
});

it('garde un coût de requêtes stable malgré la restriction', function (): void {
    $viewer = maintenanceRestrictedUser();

    $seed = function (int $count) use ($viewer): void {
        for ($i = 0; $i < $count; $i++) {
            $task = new MaintenanceTask;
            $task->fill([
                'date' => '2026-09-0'.(($i % 9) + 1),
                'task' => 'Tâche '.$i,
                'assignee_user_id' => $viewer->id,
            ]);
            $task->created_by_user_id = $viewer->id;
            $task->save();
        }
    };

    $measure = function () use ($viewer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed(3);
    $small = $measure();

    $seed(27);
    $large = $measure();

    expect($large)->toBeLessThanOrEqual($small + 5);
});
