<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessException;
use App\Models\Sector;
use App\Models\SectorPermission;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CotationAccessController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ABILITIES = [
        'cotations.cereals.view',
        'cotations.cereals.edit',
        'cotations.fuel.view',
        'cotations.fuel.edit',
        'cotations.fuel.history.view',
        'cotations.admin',
    ];

    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function index(): Response
    {
        $this->ensurePermissionsExist();

        return Inertia::render('Admin/Cotations/Index', [
            'abilities' => self::ABILITIES,
            'roles' => Role::query()
                ->with('permissions:id,name')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'abilities' => $role->permissions
                        ->pluck('name')
                        ->intersect(self::ABILITIES)
                        ->values()
                        ->all(),
                ]),
            'sectors' => Sector::query()
                ->with(['defaultPermissions' => fn ($query) => $query->whereIn('ability', self::ABILITIES)])
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Sector $sector): array => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'slug' => $sector->slug,
                    'abilities' => $sector->defaultPermissions->pluck('ability')->values()->all(),
                ]),
            'users' => User::query()
                ->with(['sector:id,name', 'roles:id,name', 'accessExceptions' => fn ($query) => $query->whereIn('ability', self::ABILITIES)->whereNull('sector_id')])
                ->orderBy('name')
                ->get(['id', 'name', 'first_name', 'last_name', 'email', 'sector_id'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $this->userLabel($user),
                    'email' => $user->email,
                    'sector_name' => $user->sector?->name,
                    'role' => $user->roles->pluck('name')->first(),
                    'overrides' => $user->accessExceptions
                        ->mapWithKeys(fn (AccessException $exception): array => [
                            $exception->ability => $exception->effect,
                        ])
                        ->all(),
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensurePermissionsExist();

        $validated = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*.id' => ['required', 'integer', 'exists:roles,id'],
            'roles.*.abilities' => ['nullable', 'array'],
            'roles.*.abilities.*' => ['string', Rule::in(self::ABILITIES)],
            'sectors' => ['nullable', 'array'],
            'sectors.*.id' => ['required', 'integer', 'exists:sectors,id'],
            'sectors.*.abilities' => ['nullable', 'array'],
            'sectors.*.abilities.*' => ['string', Rule::in(self::ABILITIES)],
            'users' => ['nullable', 'array'],
            'users.*.id' => ['required', 'integer', 'exists:users,id'],
            'users.*.overrides' => ['nullable', 'array'],
            'users.*.overrides.*' => ['nullable', Rule::in(['inherit', 'allow', 'deny'])],
        ]);

        $before = $this->snapshot();

        DB::transaction(function () use ($validated, $request): void {
            foreach ($validated['roles'] ?? [] as $row) {
                $role = Role::query()->find((int) $row['id']);
                if (! $role) {
                    continue;
                }

                $current = $role->permissions()->pluck('name')->all();
                $withoutCotations = array_values(array_diff($current, self::ABILITIES));
                $next = array_values(array_unique([
                    ...$withoutCotations,
                    ...($row['abilities'] ?? []),
                ]));
                $role->syncPermissions($next);
            }

            foreach ($validated['sectors'] ?? [] as $row) {
                SectorPermission::query()
                    ->where('sector_id', (int) $row['id'])
                    ->whereIn('ability', self::ABILITIES)
                    ->delete();

                $abilities = array_values(array_unique($row['abilities'] ?? []));
                if ($abilities === []) {
                    continue;
                }

                $now = now();
                SectorPermission::query()->insert(array_map(static fn (string $ability): array => [
                    'sector_id' => (int) $row['id'],
                    'ability' => $ability,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $abilities));
            }

            foreach ($validated['users'] ?? [] as $row) {
                AccessException::query()
                    ->where('user_id', (int) $row['id'])
                    ->whereNull('sector_id')
                    ->whereIn('ability', self::ABILITIES)
                    ->delete();

                $rows = [];
                foreach (($row['overrides'] ?? []) as $ability => $effect) {
                    if (! in_array($ability, self::ABILITIES, true) || ! in_array($effect, ['allow', 'deny'], true)) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => (int) $row['id'],
                        'sector_id' => null,
                        'ability' => $ability,
                        'effect' => $effect,
                        'created_by' => $request->user()?->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    AccessException::query()->insert($rows);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->auditLogService->log([
            'action' => 'permission_change',
            'module' => 'cotations',
            'description' => 'Mise à jour des droits du module Cotations',
            'payload' => [
                'before' => $before,
                'after' => $this->snapshot(),
            ],
        ]);

        return back()->with('status', 'Cotations permissions updated.');
    }

    private function ensurePermissionsExist(): void
    {
        $created = false;

        foreach (self::ABILITIES as $ability) {
            $permission = Permission::query()
                ->where('guard_name', 'web')
                ->where('name', $ability)
                ->first();

            if (! $permission) {
                Permission::findOrCreate($ability, 'web');
                $created = true;
            }
        }

        if ($created) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'roles' => Role::query()
                ->with('permissions:id,name')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->mapWithKeys(fn (Role $role): array => [
                    $role->name => $role->permissions->pluck('name')->intersect(self::ABILITIES)->values()->all(),
                ])
                ->all(),
            'sectors' => Sector::query()
                ->with(['defaultPermissions' => fn ($query) => $query->whereIn('ability', self::ABILITIES)])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->mapWithKeys(fn (Sector $sector): array => [
                    $sector->name => $sector->defaultPermissions->pluck('ability')->values()->all(),
                ])
                ->all(),
            'users' => User::query()
                ->with(['accessExceptions' => fn ($query) => $query->whereIn('ability', self::ABILITIES)->whereNull('sector_id')])
                ->orderBy('name')
                ->get(['id', 'name', 'first_name', 'last_name'])
                ->mapWithKeys(fn (User $user): array => [
                    $this->userLabel($user) => $user->accessExceptions
                        ->mapWithKeys(fn (AccessException $exception): array => [$exception->ability => $exception->effect])
                        ->all(),
                ])
                ->all(),
        ];
    }

    private function userLabel(User $user): string
    {
        $fullName = trim((string) $user->first_name.' '.(string) $user->last_name);
        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim((string) $user->name);

        return $name !== '' ? $name : (string) $user->email;
    }
}
