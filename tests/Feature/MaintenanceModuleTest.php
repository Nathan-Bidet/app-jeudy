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
    $user = maintenanceUser(['maintenance.view']);

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
    $user = maintenanceUser(['maintenance.view']);
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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $user = maintenanceUser(['maintenance.view', 'maintenance.request']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertRedirect();

    $task = MaintenanceTask::query()->sole();

    expect($task->origin)->toBe(MaintenanceTask::ORIGIN_REQUEST)
        ->and($task->requested_by_user_id)->toBe($user->id)
        ->and($task->created_by_user_id)->toBe($user->id);
});

it('empêche un demandeur de forger une création directe', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.request']);

    $this->actingAs($user)
        ->postJson(route('maintenance.tasks.store'), maintenancePayload([
            'origin' => MaintenanceTask::ORIGIN_CREATION,
        ]))
        ->assertForbidden();

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('ignore les champs de traçabilité envoyés depuis le frontend', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['date' => null, 'task' => null]))
        ->assertSessionHasErrors(['date', 'task']);
});

it('refuse une date de fin de période antérieure à la date de début', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'date' => '2026-09-10',
            'fin_date' => '2026-09-01',
        ]))
        ->assertSessionHasErrors('fin_date');

    expect(MaintenanceTask::query()->count())->toBe(0);
});

it('accepte une date de fin de période absente ou égale à la date de début', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    expect($task->assignee_user_id)->toBeNull()
        ->and($task->assignee_label_free)->toBeNull()
        ->and($task->assigneeType())->toBe('none');
});

it('refuse de renseigner à la fois un utilisateur et une personne libre', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $assignee = maintenanceUser([]);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'assignee_label_free' => 'Entreprise Duval',
        ]))
        ->assertSessionHasErrors('assignee_label_free');
});

it('refuse un dépôt inexistant', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($user)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['depot_id' => 999999]))
        ->assertSessionHasErrors('depot_id');
});

it('valide le booléen de commentaire masqué', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Secret industriel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view']);

    $response = $this->actingAs($viewer)
        ->get(route('maintenance.index'))
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.tasks.0.comment_withheld', true)
        ->where('groups.0.tasks.0.comment_hidden', true)
        ->missing('groups.0.tasks.0.comment')
    );

    // Aucune trace du contenu dans la réponse complète.
    expect($response->getContent())->not->toContain('Secret industriel');
});

it('ne transmet jamais un commentaire masqué via la réponse JSON', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Secret industriel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view']);

    $response = $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data'))
        ->assertOk();

    $response->assertJsonMissing(['comment' => 'Secret industriel']);
    expect($response->getContent())->not->toContain('Secret industriel');

    $task = $response->json('groups.0.tasks.0');
    expect($task)->not->toHaveKey('comment')
        ->and($task['comment_withheld'])->toBeTrue();
});

it('transmet le commentaire masqué à qui détient la permission', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Secret industriel',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view', 'maintenance.comment_hidden.view']);

    $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data'))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.comment', 'Secret industriel')
        ->assertJsonPath('groups.0.tasks.0.comment_withheld', false);
});

it('transmet un commentaire non masqué à tout lecteur du module', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'comment' => 'Information ordinaire',
        'comment_hidden' => false,
    ]);

    $viewer = maintenanceUser(['maintenance.view']);

    $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data'))
        ->assertOk()
        ->assertJsonPath('groups.0.tasks.0.comment', 'Information ordinaire')
        ->assertJsonPath('groups.0.tasks.0.comment_withheld', false);
});

it('exclut les commentaires masqués de la recherche sans la permission', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    maintenanceTaskFor($author, [
        'task' => 'Tâche anodine',
        'comment' => 'Amiante détectée',
        'comment_hidden' => true,
    ]);

    $viewer = maintenanceUser(['maintenance.view']);

    $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data', ['search' => 'Amiante', 'pointed_filter' => 'all']))
        ->assertOk()
        ->assertJsonPath('meta.count_tasks', 0);

    $privileged = maintenanceUser(['maintenance.view', 'maintenance.comment_hidden.view']);

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
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $task = maintenanceTaskFor($author, ['comment_hidden' => false]);

    $this->actingAs($author)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->pointed)->toBeFalse();

    $pointer = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
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

it('laisse un créateur modifier n’importe quelle tâche', function (): void {
    $owner = maintenanceUser(['maintenance.view', 'maintenance.request']);
    $task = maintenanceTaskFor($owner, ['comment_hidden' => false]);

    $editor = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($editor)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload(['task' => 'Description corrigée']))
        ->assertRedirect();

    expect($task->refresh()->task)->toBe('Description corrigée')
        ->and($task->updated_by_user_id)->toBe($editor->id);
});

it('limite un demandeur à ses propres tâches non pointées', function (): void {
    $owner = maintenanceUser(['maintenance.view', 'maintenance.request']);
    $ownTask = maintenanceTaskFor($owner, ['comment_hidden' => false]);

    $someoneElse = maintenanceUser(['maintenance.view', 'maintenance.request']);
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
    $withAccess = maintenanceUser(['maintenance.view']);

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
    $viewer = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $user = maintenanceUser(['maintenance.view', 'maintenance.create']);
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

it('expose les droits de modification tâche par tâche', function (): void {
    $owner = maintenanceUser(['maintenance.view', 'maintenance.request']);
    $ownTask = maintenanceTaskFor($owner, ['comment_hidden' => false]);

    $someoneElse = maintenanceUser(['maintenance.view', 'maintenance.request']);
    maintenanceTaskFor($someoneElse, ['comment_hidden' => false, 'task' => 'Tâche d’un autre']);

    $response = $this->actingAs($owner)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    $tasks = collect($response->json('groups'))
        ->flatMap(fn (array $group): array => $group['tasks'])
        ->keyBy('id');

    expect($tasks[$ownTask->id]['can_update'])->toBeTrue()
        ->and($tasks[$ownTask->id]['can_delete'])->toBeTrue();

    $otherId = $tasks->keys()->first(fn (int $id): bool => $id !== $ownTask->id);
    expect($tasks[$otherId]['can_update'])->toBeFalse()
        ->and($tasks[$otherId]['can_delete'])->toBeFalse();
});

it('ne donne aucun droit de modification à un simple lecteur', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    maintenanceTaskFor($author, ['comment_hidden' => false]);

    $viewer = maintenanceUser(['maintenance.view']);

    $response = $this->actingAs($viewer)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    expect($response->json('groups.0.tasks.0.can_update'))->toBeFalse()
        ->and($response->json('groups.0.tasks.0.can_delete'))->toBeFalse();
});

it('conserve un commentaire masqué lorsqu’un éditeur non autorisé enregistre la tâche', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create', 'maintenance.comment_hidden.view']);
    $task = maintenanceTaskFor($author, [
        'comment' => 'Diagnostic confidentiel',
        'comment_hidden' => true,
    ]);

    // Un éditeur sans le droit de lecture : le champ commentaire lui arrive vide.
    $editor = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($editor)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'task' => 'Description corrigée',
            'comment' => '',
            'comment_hidden' => false,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task->refresh();

    expect($task->task)->toBe('Description corrigée')
        ->and($task->comment)->toBe('Diagnostic confidentiel')
        ->and($task->comment_hidden)->toBeTrue();
});

it('laisse un éditeur autorisé modifier un commentaire masqué', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $task = maintenanceTaskFor($author, [
        'comment' => 'Ancien commentaire',
        'comment_hidden' => true,
    ]);

    $editor = maintenanceUser([
        'maintenance.view',
        'maintenance.create',
        'maintenance.comment_hidden.view',
    ]);

    $this->actingAs($editor)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'comment' => 'Nouveau commentaire',
            'comment_hidden' => false,
        ]))
        ->assertSessionHasNoErrors();

    $task->refresh();

    expect($task->comment)->toBe('Nouveau commentaire')
        ->and($task->comment_hidden)->toBeFalse();
});

it('filtre la liste par origine création ou demande', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $requester = maintenanceUser(['maintenance.view', 'maintenance.request']);

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
    $author ??= maintenanceUser(['maintenance.view', 'maintenance.create']);

    return maintenanceTaskFor($author, [
        'comment_hidden' => false,
        'assignee_user_id' => $assignee->id,
    ]);
}

it('refuse le pointage partiel à un utilisateur qui n’est pas l’affecté', function (): void {
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);

    $intruder = maintenanceUser(['maintenance.view']);

    $this->actingAs($intruder)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('refuse le pointage partiel au responsable, même avec le pointage définitif', function (): void {
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);

    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

    $this->actingAs($manager)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('refuse le pointage partiel à un administrateur non affecté', function (): void {
    $assignee = maintenanceUser(['maintenance.view']);
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
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $task = maintenanceTaskFor($author, [
        'comment_hidden' => false,
        'assignee_label_free' => 'SARL Legrand',
    ]);

    foreach ([$author, maintenanceUser(['maintenance.view', 'maintenance.point'])] as $candidate) {
        $this->actingAs($candidate)
            ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
            ->assertForbidden();
    }

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('interdit tout pointage partiel sur une tâche sans affectation', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $task = maintenanceTaskFor($author, ['comment_hidden' => false]);

    $this->actingAs($author)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();
});

it('montre au responsable l’état du partiel sans lui en donner le contrôle', function (): void {
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);

    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);

    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);

    $this->actingAs($assignee)
        ->patchJson(route('maintenance.tasks.pointing-date', $task), ['first_pointed_on' => '2026-08-01'])
        ->assertForbidden();

    expect($task->refresh()->first_pointed_on)->toBeNull();
});

it('ne recalcule jamais une date métier fixée à la main', function (): void {
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $task = maintenanceAssignedTask($assignee);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.point']);

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
    $assignee = maintenanceUser(['maintenance.view']);
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $assignee = maintenanceUser(['maintenance.view']);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.point', 'maintenance.create']);

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
    $manager = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $otherManager = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $bystander = maintenanceUser(['maintenance.view']);

    $requester = maintenanceUser(['maintenance.view', 'maintenance.request']);
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
    $manager = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($manager))->toBeEmpty();
});

it('exclut des destinataires un responsable désactivé', function (): void {
    $active = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $inactive = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $inactive->forceFill(['is_active' => false])->save();

    $requester = maintenanceUser(['maintenance.view', 'maintenance.request']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($active))->toHaveCount(1)
        ->and(maintenanceNotificationsOf($inactive))->toBeEmpty();
});

it('notifie l’utilisateur affecté à la création', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $witness = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $creator->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($creator))->toBeEmpty();
});

it('informe le nouvel affecté et l’ancien lors d’une réaffectation', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $first = maintenanceUser(['maintenance.view']);
    $second = maintenanceUser(['maintenance.view']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $first->id]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'assignee_user_id' => $second->id,
        ]))
        ->assertSessionHasNoErrors();

    $firstReasons = maintenanceNotificationsOf($first)->pluck('reason')->all();
    $secondReasons = maintenanceNotificationsOf($second)->pluck('reason')->all();

    expect($firstReasons)->toBe(['assigned', 'unassigned'])
        ->and($secondReasons)->toBe(['assigned']);
});

it('ne renotifie pas l’affecté pour une modification sans changement métier', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['assignee_user_id' => $assignee->id]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    // Seul le commentaire change : pas de dérangement.
    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'comment' => 'Note interne mise à jour',
        ]))
        ->assertSessionHasNoErrors();

    expect(maintenanceNotificationsOf($assignee))->toHaveCount(1);

    // La date change : l'affecté doit le savoir.
    $this->actingAs($creator)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'comment' => 'Note interne mise à jour',
            'date' => '2026-09-20',
            'fin_date' => null,
        ]))
        ->assertSessionHasNoErrors();

    $reasons = maintenanceNotificationsOf($assignee)->pluck('reason')->all();

    expect($reasons)->toBe(['assigned', 'updated']);
});

it('ne laisse jamais fuiter un commentaire masqué dans une notification', function (): void {
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view']);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $secret = 'Amiante confirmée batiment C';

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload([
            'assignee_user_id' => $assignee->id,
            'comment' => $secret,
            'comment_hidden' => true,
        ]))
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $requester = maintenanceUser(['maintenance.view', 'maintenance.request']);
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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view']);

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

it('journalise la demande, la modification et le changement d’affectation', function (): void {
    $requester = maintenanceUser(['maintenance.view', 'maintenance.request']);
    $manager = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view']);

    $this->actingAs($requester)
        ->post(route('maintenance.tasks.store'), maintenancePayload())
        ->assertSessionHasNoErrors();

    $task = MaintenanceTask::query()->sole();

    $this->actingAs($manager)
        ->put(route('maintenance.tasks.update', $task), maintenancePayload([
            'assignee_user_id' => $assignee->id,
        ]))
        ->assertSessionHasNoErrors();

    $actions = DB::table('audit_logs')->where('module', 'maintenance')->pluck('action')->all();

    expect($actions)->toContain('request_maintenance_task')
        ->and($actions)->toContain('update_maintenance_task')
        ->and($actions)->toContain('reassign_maintenance_task');

    $reassign = DB::table('audit_logs')
        ->where('action', 'reassign_maintenance_task')
        ->first();

    expect($reassign->description)->toContain('utilisateur #'.$assignee->id)
        ->and($reassign->description)->toContain('non affectée');
});

it('journalise les pointages et la date métier', function (): void {
    $manager = maintenanceUser(['maintenance.view', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create', 'maintenance.comment_hidden.view']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);

    $this->actingAs($creator)
        ->post(route('maintenance.tasks.store'), maintenancePayload(['comment' => null]))
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($creator)
        ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
        ->assertOk();

    $task = $response->json('groups.0.tasks.0');

    expect($task['comment'])->toBeNull()
        ->and($task['comment_hidden'])->toBeFalse()
        ->and($task['comment_withheld'])->toBeFalse();
});

it('garde un nombre de requêtes stable quand la liste grandit', function (): void {
    $viewer = maintenanceUser(['maintenance.view', 'maintenance.create', 'maintenance.point']);

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
    $author = maintenanceUser(['maintenance.view', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view']);

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
    $owner = maintenanceUser(['maintenance.view', 'maintenance.create', 'maintenance.point']);
    $assignee = maintenanceUser(['maintenance.view']);

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
    $reader = maintenanceUser(['maintenance.view']);

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
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $task = maintenanceTaskFor($author, [
        'comment' => 'Masqué',
        'comment_hidden' => true,
    ]);

    $withoutHidden = maintenanceUser(['maintenance.view']);
    $withHidden = maintenanceUser(['maintenance.view', 'maintenance.comment_hidden.view']);

    // Plusieurs tâches coexistent : on cible celle qui porte le commentaire.
    $findTask = function (User $viewer) use ($task): array {
        $response = $this->actingAs($viewer)
            ->getJson(route('maintenance.tasks.data', ['pointed_filter' => 'all']))
            ->assertOk();

        return collect($response->json('groups'))
            ->flatMap(fn (array $group): array => $group['tasks'])
            ->firstWhere('id', $task->id);
    };

    expect($findTask($withoutHidden))->not->toHaveKey('comment');
    expect($findTask($withHidden)['comment'])->toBe('Masqué');

    $withoutPoint = maintenanceUser(['maintenance.view']);
    $withPoint = maintenanceUser(['maintenance.view', 'maintenance.point']);

    expect($this->actingAs($withoutPoint)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])->status())->toBe(403);
    expect($this->actingAs($withPoint)
        ->patchJson(route('maintenance.tasks.point', $task), ['pointed' => true])->status())->not->toBe(403);
});

it('combine correctement demander et afficher les commentaires masqués', function (): void {
    $user = maintenanceUser(['maintenance.view', 'maintenance.request', 'maintenance.comment_hidden.view']);

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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $assignee = maintenanceUser(['maintenance.view']);
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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
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
    $creator = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $this->actingAs($creator);

    maintenanceInertiaPost([
        'date' => '2026-09-10',
        'task' => 'Tâche avec retour de succès',
        'comment_hidden' => false,
        'origin' => 'creation',
    ])->assertSessionHas('status', 'Tâche Maintenance enregistrée.');

    expect(MaintenanceTask::query()->count())->toBe(1);
});
