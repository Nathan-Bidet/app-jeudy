<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\AccessException;
use App\Models\LeaveAllowedCreatorPair;
use App\Models\LeaveRequest;
use App\Models\Sector;
use App\Models\User;
use App\Services\Leaves\LeaveDeletionAuthorizer;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Permission conges_heures.leaves.delete_assigned : supprimer une demande en
 * attente ou un congé validé, uniquement pour les collaborateurs affectés
 * (LeaveAllowedCreatorPair) au détenteur.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function leaveActor(array $abilities = []): User
{
    $sector = Sector::query()->create(['name' => fake()->unique()->company(), 'slug' => fake()->unique()->slug()]);
    $role = Role::findOrCreate('leave-del-'.fake()->unique()->word(), 'web');
    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function leaveFor(User $target, string $status = LeaveRequest::STATUS_PENDING, ?User $requester = null): LeaveRequest
{
    return LeaveRequest::query()->create([
        'requester_user_id' => ($requester ?? $target)->id,
        'target_user_id' => $target->id,
        'start_at' => '2026-07-06 00:00:00',
        'end_at' => '2026-07-10 23:59:59',
        'status' => $status,
    ]);
}

function assign(User $manager, User $target): void
{
    LeaveAllowedCreatorPair::query()->create(['creator_user_id' => $manager->id, 'target_user_id' => $target->id]);
}

const DELETE_ABILITY = LeaveDeletionAuthorizer::ABILITY;

it('supprime une demande en attente d’un collaborateur affecté, avec la permission', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $leave = leaveFor($worker);

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id))
        ->assertRedirect()->assertSessionHas('success', 'Demande de congé supprimée.');

    expect(LeaveRequest::query()->find($leave->id))->toBeNull();
    // Trace d'audit + prévenance du collaborateur concerné.
    expect(DB::table('audit_logs')->where('action', 'cancel_leave_request')->count())->toBe(1);
    expect($worker->notifications()->where('data->type', 'leave_request_deleted')->count())->toBe(1);
});

it('supprime un congé validé d’un collaborateur affecté, avec la permission', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $leave = leaveFor($worker, LeaveRequest::STATUS_APPROVED);

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id))
        ->assertSessionHas('success', 'Congé validé supprimé.');

    expect(LeaveRequest::query()->count())->toBe(0);
});

it('retire les notifications qui pointent vers le congé supprimé, et seulement celles-là', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $leave = leaveFor($worker, LeaveRequest::STATUS_APPROVED);
    $other = leaveFor($worker, LeaveRequest::STATUS_APPROVED);
    foreach ([$leave, $other] as $item) {
        DatabaseNotification::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'x',
            'notifiable_type' => User::class,
            'notifiable_id' => $worker->id,
            'data' => ['type' => 'leave_request_approved', 'leave_request_id' => $item->id],
        ]);
    }

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id));

    expect(DatabaseNotification::query()->where('data->leave_request_id', $leave->id)->count())->toBe(0)
        ->and(DatabaseNotification::query()->where('data->leave_request_id', $other->id)->count())->toBe(1);
});

it('refuse sans la permission', function (): void {
    $manager = leaveActor();
    $worker = leaveActor();
    assign($manager, $worker);
    $leave = leaveFor($worker);

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id))->assertRedirect();
    expect(LeaveRequest::query()->count())->toBe(1);
});

it('refuse quand la permission est explicitement désactivée', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    AccessException::query()->create(['user_id' => $manager->id, 'sector_id' => null, 'ability' => DELETE_ABILITY, 'effect' => 'deny']);
    $worker = leaveActor();
    assign($manager, $worker);
    $leave = leaveFor($worker);

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id))->assertRedirect();
    expect(LeaveRequest::query()->count())->toBe(1);
});

it('accepte une autorisation explicite sans permission de rôle', function (): void {
    $manager = leaveActor();
    AccessException::query()->create(['user_id' => $manager->id, 'sector_id' => null, 'ability' => DELETE_ABILITY, 'effect' => 'allow']);
    $worker = leaveActor();
    assign($manager, $worker);
    $leave = leaveFor($worker);

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id))->assertRedirect();
    expect(LeaveRequest::query()->count())->toBe(0);
});

it('refuse pour un collaborateur non affecté, y compris par requête forgée', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $assigned = leaveActor();
    $stranger = leaveActor();
    assign($manager, $assigned);
    $leave = leaveFor($stranger);

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id))->assertRedirect();
    $this->actingAs($manager)->deleteJson(route('leaves.destroy', $leave->id))->assertForbidden();
    expect(LeaveRequest::query()->count())->toBe(1);
});

it('refuse après le retrait de l’affectation', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $leave = leaveFor($worker);

    LeaveAllowedCreatorPair::query()->delete();

    $this->actingAs($manager)->delete(route('leaves.destroy', $leave->id))->assertRedirect();
    expect(LeaveRequest::query()->count())->toBe(1);
});

it('ne donne jamais accès à ses propres congés ni aux congés refusés', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $own = leaveFor($manager);
    $refused = leaveFor($worker, LeaveRequest::STATUS_REFUSED);

    $this->actingAs($manager)->delete(route('leaves.destroy', $own->id))->assertRedirect();
    $this->actingAs($manager)->delete(route('leaves.destroy', $refused->id))->assertRedirect();
    expect(LeaveRequest::query()->count())->toBe(2);
});

it('répond 404 pour une demande qui n’existe plus et exige une authentification', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);

    $this->actingAs($manager)->delete(route('leaves.destroy', 99999))->assertNotFound();

    auth()->logout();
    $this->delete(route('leaves.destroy', 1))->assertRedirect();
});

it('laisse l’administrateur supprimer comme avant', function (): void {
    $admin = leaveActor();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $leave = leaveFor(leaveActor(), LeaveRequest::STATUS_REFUSED);

    $this->actingAs($admin)->delete(route('leaves.destroy', $leave->id))->assertRedirect();
    expect(LeaveRequest::query()->count())->toBe(0);
});

it('ne crée la permission pour aucun rôle hors admin à la migration', function (): void {
    $migration = require base_path('database/migrations/2026_10_01_120000_add_leaves_delete_assigned_permission.php');
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('chauffeur', 'web');

    $migration->up();
    $migration->up(); // idempotente

    $permission = Permission::query()->where('name', DELETE_ABILITY)->sole();
    expect($permission->roles()->pluck('name')->all())->toBe(['admin']);
});

function leavesPagePayload($test, User $viewer): array
{
    $response = $test->actingAs($viewer)->get(route('leaves.index'))->assertOk();
    preg_match('/data-page="([^"]*)"/', $response->getContent(), $m);

    return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
}

function leaveToValidate(User $target, User $validator, string $status): LeaveRequest
{
    $leave = leaveFor($target, $status);
    $leave->forceFill(['validator_1_id' => $validator->id])->save();

    return $leave;
}

it('n’ajoute aucun bloc dédié : aucune donnée « collaborateurs affectés » n’est envoyée à la page', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    leaveToValidate($worker, $manager, LeaveRequest::STATUS_APPROVED);

    $props = leavesPagePayload($this, $manager);

    expect($props)->not->toHaveKey('assignedLeaveRequests');
});

it('autorise le bouton dans l’historique pour une demande en attente ou validée d’un collaborateur affecté', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $pending = leaveToValidate($worker, $manager, LeaveRequest::STATUS_PENDING);
    $approved = leaveToValidate($worker, $manager, LeaveRequest::STATUS_APPROVED);

    $rows = collect(leavesPagePayload($this, $manager)['leaveRequestsToValidate'])->keyBy('id');

    expect($rows[$pending->id]['can_delete'])->toBeTrue()
        ->and($rows[$approved->id]['can_delete'])->toBeTrue();
});

it('refuse le bouton : demande refusée, collaborateur non affecté, sans permission, permission refusée', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    $stranger = leaveActor();
    assign($manager, $worker);
    $refused = leaveToValidate($worker, $manager, LeaveRequest::STATUS_REFUSED);
    $notAssigned = leaveToValidate($stranger, $manager, LeaveRequest::STATUS_APPROVED);

    $rows = collect(leavesPagePayload($this, $manager)['leaveRequestsToValidate'])->keyBy('id');
    expect($rows[$refused->id]['can_delete'])->toBeFalse()
        ->and($rows[$notAssigned->id]['can_delete'])->toBeFalse();

    $noPermission = leaveActor();
    assign($noPermission, $worker);
    $ok = leaveToValidate($worker, $noPermission, LeaveRequest::STATUS_APPROVED);
    expect(collect(leavesPagePayload($this, $noPermission)['leaveRequestsToValidate'])->firstWhere('id', $ok->id)['can_delete'])->toBeFalse();

    $denied = leaveActor([DELETE_ABILITY]);
    AccessException::query()->create(['user_id' => $denied->id, 'sector_id' => null, 'ability' => DELETE_ABILITY, 'effect' => 'deny']);
    assign($denied, $worker);
    $own = leaveToValidate($worker, $denied, LeaveRequest::STATUS_APPROVED);
    expect(collect(leavesPagePayload($this, $denied)['leaveRequestsToValidate'])->firstWhere('id', $own->id)['can_delete'])->toBeFalse();
});

it('n’élargit pas l’historique : une demande hors de la file du valideur n’y apparaît pas', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $notMine = leaveFor($worker, LeaveRequest::STATUS_APPROVED); // aucun valideur : hors historique

    $props = leavesPagePayload($this, $manager);

    expect(collect($props['leaveRequestsToValidate'])->pluck('id')->all())->not->toContain($notMine->id);
});

it('« Mes demandes » ne porte plus de décision de suppression', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    assign($manager, $worker);
    $mine = leaveFor($worker, LeaveRequest::STATUS_PENDING, $manager);

    $row = collect(leavesPagePayload($this, $manager)['myLeaveRequests'])->firstWhere('id', $mine->id);

    expect($row)->not->toHaveKey('can_delete');
});
