<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SectorController extends Controller
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
        'cotations.cereals.view',
        'cotations.cereals.edit',
        'cotations.fuel.view',
        'cotations.fuel.edit',
        'cotations.admin',
        'heures.view',
        'heures.create',
        'heures.export',
        'engrais.view',
        'engrais.view.current_week_only',
        'engrais.create',
        'engrais.update',
        'engrais.delete',
        'engrais.point',
        'engrais.export',
        'engrais.sms',
    ];

    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function index(): Response
    {
        $this->ensureTaskDataPermissionsExist();

        return Inertia::render('Admin/Sectors/Index', [
            'sectors' => Sector::query()
                ->with(['defaultPermissions:id,sector_id,ability'])
                ->withCount('users')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'description', 'leave_color'])
                ->map(fn (Sector $sector): array => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'slug' => $sector->slug,
                    'description' => $sector->description,
                    'leave_color' => $sector->leave_color,
                    'users_count' => $sector->users_count,
                    'default_abilities' => $sector->defaultPermissions
                        ->pluck('ability')
                        ->values()
                        ->all(),
                ]),
            'abilities' => Permission::query()->orderBy('name')->pluck('name')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:sectors,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'leave_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        Sector::query()->create([
            'name' => $validated['name'],
            'slug' => $this->generateUniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'leave_color' => $this->normalizeColor($validated['leave_color'] ?? null),
        ]);

        return back()->with('status', 'Sector created.');
    }

    public function update(Request $request, Sector $sector): RedirectResponse
    {
        $before = [
            'name' => $sector->name,
            'slug' => $sector->slug,
            'description' => $sector->description,
            'leave_color' => $sector->leave_color,
        ];

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('sectors', 'name')->ignore($sector->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'leave_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $slug = $sector->slug;
        $expectedBase = Str::slug($sector->name);
        $newBase = Str::slug($validated['name']);

        if ($expectedBase !== $newBase) {
            $slug = $this->generateUniqueSlug($validated['name'], $sector->id);
        }

        $sector->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'leave_color' => $this->normalizeColor($validated['leave_color'] ?? null),
        ]);

        $this->auditLogService->log([
            'action' => 'update_sector',
            'module' => 'admin',
            'description' => 'Mise a jour secteur',
            'payload' => [
                'sector_id' => $sector->id,
                'before' => $before,
                'after' => [
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'description' => $validated['description'] ?? null,
                    'leave_color' => $this->normalizeColor($validated['leave_color'] ?? null),
                ],
            ],
        ]);

        return back()->with('status', 'Sector updated.');
    }

    public function duplicate(Sector $sector): RedirectResponse
    {
        $abilities = $sector->defaultPermissions()->pluck('ability')->values()->all();
        $duplicateName = $this->generateDuplicateName($sector->name);

        $duplicatedSector = DB::transaction(function () use ($sector, $abilities, $duplicateName): Sector {
            $copy = Sector::query()->create([
                'name' => $duplicateName,
                'slug' => $this->generateUniqueSlug($duplicateName),
                'description' => $sector->description,
                'leave_color' => $sector->leave_color,
            ]);

            if ($abilities !== []) {
                $now = now();
                $rows = array_map(static fn (string $ability): array => [
                    'sector_id' => $copy->id,
                    'ability' => $ability,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $abilities);

                $copy->defaultPermissions()->insert($rows);
            }

            return $copy;
        });

        $this->auditLogService->log([
            'action' => 'duplicate_sector',
            'module' => 'admin',
            'description' => 'Duplication secteur',
            'payload' => [
                'source_sector_id' => $sector->id,
                'source_sector_name' => $sector->name,
                'duplicated_sector_id' => $duplicatedSector->id,
                'duplicated_sector_name' => $duplicatedSector->name,
                'default_abilities' => $abilities,
            ],
        ]);

        return back()->with('status', 'Sector duplicated.');
    }

    public function updatePermissions(Request $request, Sector $sector): RedirectResponse
    {
        $before = $sector->defaultPermissions()->pluck('ability')->values()->all();

        $validated = $request->validate([
            'default_abilities' => ['nullable', 'array'],
            'default_abilities.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $abilities = array_values(array_unique($validated['default_abilities'] ?? []));

        $sector->defaultPermissions()->delete();

        if ($abilities !== []) {
            $now = now();
            $rows = array_map(static fn (string $ability): array => [
                'sector_id' => $sector->id,
                'ability' => $ability,
                'created_at' => $now,
                'updated_at' => $now,
            ], $abilities);

            $sector->defaultPermissions()->insert($rows);
        }

        $this->auditLogService->log([
            'action' => 'permission_change',
            'module' => 'admin',
            'description' => 'Mise a jour permissions par defaut du secteur',
            'payload' => [
                'sector_id' => $sector->id,
                'before' => $before,
                'after' => $abilities,
            ],
        ]);

        return back()->with('status', 'Sector default permissions updated.');
    }

    public function save(Request $request, Sector $sector): RedirectResponse
    {
        $before = [
            'name' => $sector->name,
            'slug' => $sector->slug,
            'description' => $sector->description,
            'leave_color' => $sector->leave_color,
            'default_abilities' => $sector->defaultPermissions()->pluck('ability')->values()->all(),
        ];

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('sectors', 'name')->ignore($sector->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'leave_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_abilities' => ['nullable', 'array'],
            'default_abilities.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        DB::transaction(function () use ($sector, $validated): void {
            $slug = $sector->slug;
            $expectedBase = Str::slug($sector->name);
            $newBase = Str::slug($validated['name']);

            if ($expectedBase !== $newBase) {
                $slug = $this->generateUniqueSlug($validated['name'], $sector->id);
            }

            $sector->update([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'leave_color' => $this->normalizeColor($validated['leave_color'] ?? null),
            ]);

            $abilities = array_values(array_unique($validated['default_abilities'] ?? []));

            $sector->defaultPermissions()->delete();

            if ($abilities !== []) {
                $now = now();
                $rows = array_map(static fn (string $ability): array => [
                    'sector_id' => $sector->id,
                    'ability' => $ability,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $abilities);

                $sector->defaultPermissions()->insert($rows);
            }
        });

        $afterAbilities = array_values(array_unique($validated['default_abilities'] ?? []));

        $this->auditLogService->log([
            'action' => 'update_sector',
            'module' => 'admin',
            'description' => 'Mise a jour secteur',
            'payload' => [
                'sector_id' => $sector->id,
                'before' => [
                    'name' => $before['name'],
                    'slug' => $before['slug'],
                    'description' => $before['description'],
                    'leave_color' => $before['leave_color'],
                ],
                'after' => [
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'leave_color' => $this->normalizeColor($validated['leave_color'] ?? null),
                ],
            ],
        ]);

        if ($before['default_abilities'] !== $afterAbilities) {
            $this->auditLogService->log([
                'action' => 'permission_change',
                'module' => 'admin',
                'description' => 'Mise a jour permissions par defaut du secteur',
                'payload' => [
                    'sector_id' => $sector->id,
                    'before' => $before['default_abilities'],
                    'after' => $afterAbilities,
                ],
            ]);
        }

        return back()->with('status', 'Sector saved.');
    }

    public function destroy(Sector $sector): RedirectResponse
    {
        if ($sector->users()->exists()) {
            return back()->withErrors([
                'sector' => 'Cannot delete a sector with assigned users.',
            ]);
        }

        if (Sector::query()->count() <= 1) {
            return back()->withErrors([
                'sector' => 'At least one sector must remain.',
            ]);
        }

        $sector->delete();

        return back()->with('status', 'Sector deleted.');
    }

    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 1;

        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return Sector::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }

    private function generateDuplicateName(string $name): string
    {
        $base = trim($name) !== '' ? trim($name) : 'Secteur';
        $suffix = ' (copie)';
        $counter = 1;

        while (true) {
            $counterSuffix = $counter === 1 ? $suffix : " (copie {$counter})";
            $availableLength = max(1, 120 - Str::length($counterSuffix));
            $trimmedBase = Str::limit($base, $availableLength, '');
            $candidate = $trimmedBase.$counterSuffix;

            if (! Sector::query()->where('name', $candidate)->exists()) {
                return $candidate;
            }

            $counter++;
        }
    }

    private function normalizeColor(?string $color): ?string
    {
        $value = trim((string) $color);

        if ($value === '') {
            return null;
        }

        return strtolower($value);
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
