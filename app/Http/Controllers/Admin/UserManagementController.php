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
                    'sector_id' => $user->sector_id,
                    'sector_name' => $user->sector?->name,
                    'role' => $user->roles->pluck('name')->first(),
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
        ]);

        $fullName = trim(preg_replace('/\s+/', ' ', $validated['first_name'].' '.$validated['last_name']) ?? '');

        $user = User::query()->create([
            'name' => $fullName,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'sector_id' => (int) $validated['sector_id'],
        ]);

        $user->syncRoles([$validated['role']]);

        return back()->with('status', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $before = [
            'role' => $user->roles->pluck('name')->first(),
            'sector_id' => $user->sector_id,
        ];

        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'utilisateur'])],
            'sector_id' => ['required', 'integer', 'exists:sectors,id'],
        ]);

        $user->forceFill([
            'sector_id' => (int) $validated['sector_id'],
        ])->save();

        $user->syncRoles([$validated['role']]);

        $this->auditLogService->log([
            'action' => 'update_user',
            'module' => 'admin',
            'description' => 'Mise a jour role/secteur utilisateur',
            'payload' => [
                'user_id' => $user->id,
                'before' => $before,
                'after' => [
                    'role' => $validated['role'],
                    'sector_id' => (int) $validated['sector_id'],
                ],
            ],
        ]);

        return back()->with('status', 'User access scope updated.');
    }

    public function updateAccount(Request $request, User $user): RedirectResponse
    {
        $before = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ];

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $fullName = trim(preg_replace('/\s+/', ' ', $validated['first_name'].' '.$validated['last_name']) ?? '');

        $user->forceFill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => $fullName,
            'email' => $validated['email'],
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
            'description' => 'Mise a jour compte utilisateur',
            'payload' => [
                'user_id' => $user->id,
                'before' => $before,
                'after' => [
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'password_changed' => ! empty($validated['password']),
                ],
            ],
        ]);

        return back()->with('status', 'User account updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ((int) $request->user()?->id === (int) $user->id) {
            return back()->withErrors([
                'delete_user' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ]);
        }

        $user->syncRoles([]);
        $user->delete();

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
            'description' => 'Mise a jour des exceptions utilisateur',
            'payload' => [
                'user_id' => $user->id,
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
}
