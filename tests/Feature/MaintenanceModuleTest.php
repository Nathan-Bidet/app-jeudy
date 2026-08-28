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

it('ouvre le pointage partiel aux lecteurs du module', function (): void {
    $author = maintenanceUser(['maintenance.view', 'maintenance.create']);
    $task = maintenanceTaskFor($author, ['comment_hidden' => false]);

    $outsider = maintenanceUser([]);

    $this->actingAs($outsider)
        ->patchJson(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertForbidden();

    expect($task->refresh()->partially_pointed)->toBeFalse();

    $viewer = maintenanceUser(['maintenance.view']);

    $this->actingAs($viewer)
        ->patch(route('maintenance.tasks.partial-point', $task), ['partially_pointed' => true])
        ->assertRedirect();

    $task->refresh();

    expect($task->partially_pointed)->toBeTrue()
        ->and($task->partially_pointed_by_user_id)->toBe($viewer->id)
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
