<?php

namespace App\Http\Controllers;

use App\Models\CalendarCategory;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CalendarCategoryController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.category.manage');
        $validated = $this->validatePayload($request);

        $category = CalendarCategory::query()->create([
            'name' => trim((string) $validated['name']),
            'color' => strtoupper((string) ($validated['color'] ?? '#0F6930')),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $this->auditLogService->log([
            'action' => 'create_calendar_category',
            'module' => 'calendar',
            'description' => sprintf('%s a créé une catégorie Calendrier', $this->actorLabel($request)),
            'payload' => [
                'category_id' => (int) $category->id,
                'after' => $this->categoryAuditSnapshot($category),
            ],
        ]);

        return back()->with('status', 'Catégorie créée.');
    }

    public function update(Request $request, CalendarCategory $calendarCategory): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.category.manage');
        $beforeSnapshot = $this->categoryAuditSnapshot($calendarCategory);
        $validated = $this->validatePayload($request);

        $calendarCategory->update([
            'name' => trim((string) $validated['name']),
            'color' => strtoupper((string) ($validated['color'] ?? '#0F6930')),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);
        $calendarCategory->refresh();

        $this->auditLogService->log([
            'action' => 'update_calendar_category',
            'module' => 'calendar',
            'description' => sprintf('%s a modifié une catégorie Calendrier', $this->actorLabel($request)),
            'payload' => [
                'category_id' => (int) $calendarCategory->id,
                'before' => $beforeSnapshot,
                'after' => $this->categoryAuditSnapshot($calendarCategory),
            ],
        ]);

        return back()->with('status', 'Catégorie mise à jour.');
    }

    public function destroy(Request $request, CalendarCategory $calendarCategory): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.category.manage');
        $beforeSnapshot = $this->categoryAuditSnapshot($calendarCategory);
        $calendarCategory->delete();

        $this->auditLogService->log([
            'action' => 'delete_calendar_category',
            'module' => 'calendar',
            'description' => sprintf('%s a supprimé une catégorie Calendrier', $this->actorLabel($request)),
            'payload' => [
                'category_id' => (int) $calendarCategory->id,
                'before' => $beforeSnapshot,
            ],
        ]);

        return back()->with('status', 'Catégorie supprimée.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function categoryAuditSnapshot(CalendarCategory $calendarCategory): array
    {
        return [
            'id' => (int) $calendarCategory->id,
            'name' => $calendarCategory->name,
            'color' => $calendarCategory->color,
            'is_active' => (bool) $calendarCategory->is_active,
        ];
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

    private function assertCanManage(Request $request, string $permission): void
    {
        $user = $request->user();
        abort_unless($user && app(AccessManager::class)->can($user, $permission), 403);
    }
}
