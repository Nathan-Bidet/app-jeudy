<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class UserManagementController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const TASK_DATA_PERMISSIONS = [
        'task.data.view',
        'task.data.jeudy.view',
        'task.data.jeudy.manage',
        'task.data.transporters.view',
        'task.data.transporters.manage',
        'task.data.depots.view',
        'task.data.depots.manage',
        'task.archive.view',
        'task.archive.manage',
        'calendar.view',
        'calendar.event.manage',
        'calendar.category.manage',
        'calendar.feed.manage',
        'cotations.view',
        'cotations.manage',
        'cotations.admin',
        'heures.view',
        'heures.create',
        'heures.export',
    ];

    public function __construct(
        private readonly AccessManager $accessManager,
        private readonly AuditLogService $auditLogService,
    )
    {
    }

    public function index(): Response
    {
        $this->ensureTaskDataPermissionsExist();

        $users = User::query()
            ->with(['sector:id,name', 'roles:id,name', 'accessExceptions'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user): array {
                $allow = $user->accessExceptions
                    ->whereNull('sector_id')
                    ->where('effect', 'allow')
                    ->pluck('ability')
                    ->values()
                    ->all();

                $deny = $user->accessExceptions
                    ->whereNull('sector_id')
                    ->where('effect', 'deny')
                    ->pluck('ability')
                    ->values()
                    ->all();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'hours_tracking_starts_at' => $user->hours_tracking_starts_at?->toDateString(),
                    'sector_id' => $user->sector_id,
                    'sector_name' => $user->sector?->name,
                    'role' => $user->roles->pluck('name')->first(),
                    'is_active' => (bool) $user->is_active,
                    'allow_overrides' => $allow,
                    'deny_overrides' => $deny,
                ];
            });

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'sectors' => Sector::query()->orderBy('name')->get(['id', 'name']),
            'roles' => ['admin', 'utilisateur'],
            'abilities' => Permission::query()->orderBy('name')->pluck('name')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'utilisateur'])],
            'sector_id' => ['required', 'integer', 'exists:sectors,id'],
            'hours_tracking_starts_at' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $fullName = trim(preg_replace('/\s+/', ' ', $validated['first_name'].' '.$validated['last_name']) ?? '');

        $user = User::query()->create([
            'name' => $fullName,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'sector_id' => (int) $validated['sector_id'],
            'hours_tracking_starts_at' => $validated['hours_tracking_starts_at'] ?? null,
        ]);

        $user->syncRoles([$validated['role']]);

        $actorLabel = $this->actorLabel($request);
        $targetLabel = $this->userLabel($user);
        $this->auditLogService->log([
            'action' => 'create_user',
            'module' => 'admin',
            'description' => sprintf('%s a créé l’utilisateur %s', $actorLabel, $targetLabel),
            'payload' => [
                'target_user_id' => (int) $user->id,
                'target_user_email' => (string) $user->email,
                'target_user_name' => $targetLabel,
                'role' => (string) $validated['role'],
                'sector_id' => (int) $validated['sector_id'],
            ],
        ]);
        $this->auditLogService->log([
            'action' => 'assign_role',
            'module' => 'admin',
            'description' => sprintf('%s a attribué le rôle %s à %s', $actorLabel, (string) $validated['role'], $targetLabel),
            'payload' => [
                'target_user_id' => (int) $user->id,
                'target_user_name' => $targetLabel,
                'role' => (string) $validated['role'],
            ],
        ]);

        return back()->with('status', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $beforeRole = $user->roles->pluck('name')->first();
        $before = [
            'role' => $beforeRole,
            'sector_id' => $user->sector_id,
            'is_active' => (bool) $user->is_active,
        ];

        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'utilisateur'])],
            'sector_id' => ['required', 'integer', 'exists:sectors,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $nextIsActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : (bool) $user->is_active;

        if (! $nextIsActive && (int) $request->user()?->id === (int) $user->id) {
            return back()->withErrors([
                'is_active' => 'Vous ne pouvez pas désactiver votre propre compte.',
            ]);
        }

        if (! $nextIsActive && $beforeRole === 'admin') {
            $activeAdminsCount = User::query()
                ->where('is_active', true)
                ->role('admin')
                ->count();

            if ($activeAdminsCount <= 1) {
                return back()->withErrors([
                    'is_active' => 'Impossible de désactiver le dernier administrateur actif.',
                ]);
            }
        }

        $user->forceFill([
            'sector_id' => (int) $validated['sector_id'],
            'is_active' => $nextIsActive,
        ])->save();

        $user->syncRoles([$validated['role']]);
        $afterRole = (string) $validated['role'];

        $this->auditLogService->log([
            'action' => 'update_user',
            'module' => 'admin',
            'description' => sprintf(
                '%s a modifié le rôle/secteur de %s',
                $this->actorLabel($request),
                $this->userLabel($user)
            ),
            'payload' => [
                'user_id' => $user->id,
                'target_user_id' => (int) $user->id,
                'target_user_name' => $this->userLabel($user),
                'before' => $before,
                'after' => [
                    'role' => $validated['role'],
                    'sector_id' => (int) $validated['sector_id'],
                    'is_active' => (bool) $user->is_active,
                ],
            ],
        ]);

        if ((bool) $before['is_active'] !== (bool) $user->is_active) {
            $isNowActive = (bool) $user->is_active;
            $action = $isNowActive ? 'enable_user' : 'disable_user';
            $description = $isNowActive
                ? sprintf('%s a réactivé le compte de %s', $this->actorLabel($request), $this->userLabel($user))
                : sprintf('%s a désactivé le compte de %s', $this->actorLabel($request), $this->userLabel($user));

            $this->auditLogService->log([
                'action' => $action,
                'module' => 'admin',
                'description' => $description,
                'payload' => [
                    'target_user_id' => (int) $user->id,
                    'target_user_name' => $this->userLabel($user),
                    'before' => [
                        'is_active' => (bool) $before['is_active'],
                    ],
                    'after' => [
                        'is_active' => (bool) $user->is_active,
                    ],
                ],
            ]);
        }

        if ($beforeRole !== $afterRole) {
            if (filled($beforeRole)) {
                $this->auditLogService->log([
                    'action' => 'revoke_role',
                    'module' => 'admin',
                    'description' => sprintf(
                        '%s a retiré le rôle %s à %s',
                        $this->actorLabel($request),
                        (string) $beforeRole,
                        $this->userLabel($user)
                    ),
                    'payload' => [
                        'target_user_id' => (int) $user->id,
                        'target_user_name' => $this->userLabel($user),
                        'role' => (string) $beforeRole,
                    ],
                ]);
            }

            $this->auditLogService->log([
                'action' => 'assign_role',
                'module' => 'admin',
                'description' => sprintf(
                    '%s a attribué le rôle %s à %s',
                    $this->actorLabel($request),
                    $afterRole,
                    $this->userLabel($user)
                ),
                'payload' => [
                    'target_user_id' => (int) $user->id,
                    'target_user_name' => $this->userLabel($user),
                    'role' => $afterRole,
                ],
            ]);
        }

        return back()->with('status', 'User access scope updated.');
    }

    public function updateAccount(Request $request, User $user): RedirectResponse
    {
        $before = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'hours_tracking_starts_at' => $user->hours_tracking_starts_at?->toDateString(),
        ];

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'hours_tracking_starts_at' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $fullName = trim(preg_replace('/\s+/', ' ', $validated['first_name'].' '.$validated['last_name']) ?? '');

        $user->forceFill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => $fullName,
            'email' => $validated['email'],
            'hours_tracking_starts_at' => $validated['hours_tracking_starts_at'] ?? null,
        ]);

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->auditLogService->log([
            'action' => 'update_user',
            'module' => 'admin',
            'description' => sprintf(
                '%s a modifié le compte de %s',
                $this->actorLabel($request),
                $this->userLabel($user)
            ),
            'payload' => [
                'user_id' => $user->id,
                'target_user_id' => (int) $user->id,
                'target_user_name' => $this->userLabel($user),
                'before' => $before,
                'after' => [
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'hours_tracking_starts_at' => $user->hours_tracking_starts_at?->toDateString(),
                    'password_changed' => ! empty($validated['password']),
                ],
            ],
        ]);

        if (! empty($validated['password'])) {
            $this->auditLogService->log([
                'action' => 'reset_user_password_admin',
                'module' => 'admin',
                'description' => sprintf(
                    '%s a réinitialisé le mot de passe de %s',
                    $this->actorLabel($request),
                    $this->userLabel($user)
                ),
                'payload' => [
                    'target_user_id' => (int) $user->id,
                    'target_user_name' => $this->userLabel($user),
                    'password_changed' => true,
                ],
            ]);
        }

        return back()->with('status', 'User account updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ((int) $request->user()?->id === (int) $user->id) {
            return back()->withErrors([
                'delete_user' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ]);
        }

        $targetLabel = $this->userLabel($user);
        $rolesBeforeDelete = $user->roles()->pluck('name')->values()->all();

        $user->syncRoles([]);
        $user->delete();

        foreach ($rolesBeforeDelete as $roleName) {
            $this->auditLogService->log([
                'action' => 'revoke_role',
                'module' => 'admin',
                'description' => sprintf(
                    '%s a retiré le rôle %s à %s',
                    $this->actorLabel($request),
                    (string) $roleName,
                    $targetLabel
                ),
                'payload' => [
                    'target_user_id' => (int) $user->id,
                    'target_user_name' => $targetLabel,
                    'role' => (string) $roleName,
                ],
            ]);
        }

        $this->auditLogService->log([
            'action' => 'delete_user',
            'module' => 'admin',
            'description' => sprintf('%s a supprimé l’utilisateur %s', $this->actorLabel($request), $targetLabel),
            'payload' => [
                'target_user_id' => (int) $user->id,
                'target_user_name' => $targetLabel,
                'target_user_email' => (string) $user->email,
                'roles_before_delete' => $rolesBeforeDelete,
            ],
        ]);

        return back()->with('status', 'User deleted.');
    }

    public function updateOverrides(Request $request, User $user): RedirectResponse
    {
        $before = [
            'allow_abilities' => $user->accessExceptions
                ->whereNull('sector_id')
                ->where('effect', 'allow')
                ->pluck('ability')
                ->values()
                ->all(),
            'deny_abilities' => $user->accessExceptions
                ->whereNull('sector_id')
                ->where('effect', 'deny')
                ->pluck('ability')
                ->values()
                ->all(),
        ];

        $validated = $request->validate([
            'allow_abilities' => ['nullable'],
            'allow_abilities.*' => ['string'],
            'deny_abilities' => ['nullable'],
            'deny_abilities.*' => ['string'],
        ]);

        $allow = $this->parseAbilityInput($validated['allow_abilities'] ?? []);
        $deny = $this->parseAbilityInput($validated['deny_abilities'] ?? []);

        $this->accessManager->replaceGlobalOverrides(
            user: $user,
            allowAbilities: $allow,
            denyAbilities: $deny,
            actorId: $request->user()?->id
        );

        $this->auditLogService->log([
            'action' => 'permission_change',
            'module' => 'admin',
            'description' => sprintf(
                '%s a modifié les exceptions d’accès de %s',
                $this->actorLabel($request),
                $this->userLabel($user)
            ),
            'payload' => [
                'user_id' => $user->id,
                'target_user_id' => (int) $user->id,
                'target_user_name' => $this->userLabel($user),
                'before' => $before,
                'after' => [
                    'allow_abilities' => $allow,
                    'deny_abilities' => $deny,
                ],
            ],
        ]);

        return back()->with('status', 'User exceptions updated.');
    }

    /**
     * @return array<int, string>
     */
    private function parseAbilityInput(mixed $value): array
    {
        if (is_array($value)) {
            return $this->accessManager->normalizeAbilities($value);
        }

        return $this->parseAbilityText((string) $value);
    }

    /**
     * @return array<int, string>
     */
    private function parseAbilityText(string $value): array
    {
        $normalized = str_replace([',', ';'], "\n", $value);
        $parts = preg_split('/\r\n|\r|\n/', $normalized) ?: [];

        return $this->accessManager->normalizeAbilities($parts);
    }

    private function ensureTaskDataPermissionsExist(): void
    {
        $created = false;

        foreach (self::TASK_DATA_PERMISSIONS as $permission) {
            $model = Permission::query()
                ->where('guard_name', 'web')
                ->where('name', $permission)
                ->first();

            if (! $model) {
                Permission::findOrCreate($permission, 'web');
                $created = true;
            }
        }

        if ($created) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function actorLabel(Request $request): string
    {
        $firstName = trim((string) ($request->user()?->first_name ?? ''));
        $lastName = trim((string) ($request->user()?->last_name ?? ''));
        $fullName = trim($firstName.' '.$lastName);

        if ($fullName !== '') {
            return $fullName;
        }

        $fallback = trim((string) ($request->user()?->name ?? ''));
        return $fallback !== '' ? $fallback : 'Utilisateur';
    }

    private function userLabel(User $user): string
    {
        $fullName = trim((string) $user->first_name.' '.(string) $user->last_name);
        if ($fullName !== '') {
            return $fullName;
        }

        return trim((string) $user->name) !== '' ? (string) $user->name : (string) $user->email;
    }
}
