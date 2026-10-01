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

it('expose le bouton Supprimer seulement pour les collaborateurs affectés du détenteur', function (): void {
    $manager = leaveActor([DELETE_ABILITY]);
    $worker = leaveActor();
    $stranger = leaveActor();
    assign($manager, $worker);
    $pending = leaveFor($worker, LeaveRequest::STATUS_PENDING);
    $approved = leaveFor($worker, LeaveRequest::STATUS_APPROVED);
    leaveFor($worker, LeaveRequest::STATUS_REFUSED);
    leaveFor($stranger, LeaveRequest::STATUS_APPROVED);
    $mine = leaveFor($worker, LeaveRequest::STATUS_PENDING, $manager);

    $props = leavesPagePayload($this, $manager);

    $listed = collect($props['assignedLeaveRequests']);
    expect($listed->pluck('id')->sort()->values()->all())->toBe([$pending->id, $approved->id])
        ->and($listed->every(fn ($row) => $row['can_delete'] === true))->toBeTrue();
    expect(collect($props['myLeaveRequests'])->firstWhere('id', $mine->id)['can_delete'])->toBeTrue();
    expect($props['canDeleteLeaveRequests'])->toBeFalse();
});

it('masque le bouton et la liste sans permission', function (): void {
    $manager = leaveActor();
    $worker = leaveActor();
    assign($manager, $worker);
    $mine = leaveFor($worker, LeaveRequest::STATUS_PENDING, $manager);
    leaveFor($worker, LeaveRequest::STATUS_APPROVED);

    $props = leavesPagePayload($this, $manager);

    expect($props['assignedLeaveRequests'])->toBe([])
        ->and(collect($props['myLeaveRequests'])->firstWhere('id', $mine->id)['can_delete'])->toBeFalse();
});
