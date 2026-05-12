<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CalendarFeedController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.feed.manage');
        $validated = $this->validatePayload($request);

        $feed = CalendarFeed::query()->create([
            'name' => trim((string) $validated['name']),
            'url' => trim((string) $validated['url']),
            'color' => $this->normalizedColor($validated['color'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $actorLabel = $this->actorLabel($request);

        $this->auditLogService->log([
            'action' => 'create_calendar_feed',
            'module' => 'calendar',
            'description' => sprintf('%s a créé un flux calendrier', $actorLabel),
            'payload' => [
                'feed_id' => (int) $feed->id,
                'url' => $feed->url,
                'after' => $this->feedAuditSnapshot($feed),
            ],
        ]);
        $this->auditLogService->log([
            'action' => 'import_calendar_feed',
            'module' => 'calendar',
            'description' => sprintf('%s a importé un flux calendrier', $actorLabel),
            'payload' => [
                'feed_id' => (int) $feed->id,
                'url' => $feed->url,
                'feed' => $this->feedAuditSnapshot($feed),
            ],
        ]);

        return back()->with('status', 'Calendrier public ajouté.');
    }

    public function update(Request $request, CalendarFeed $calendarFeed): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.feed.manage');
        $beforeSnapshot = $this->feedAuditSnapshot($calendarFeed);
        $validated = $this->validatePayload($request);

        $calendarFeed->update([
            'name' => trim((string) $validated['name']),
            'url' => trim((string) $validated['url']),
            'color' => $this->normalizedColor($validated['color'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);
        $calendarFeed->refresh();

        $this->auditLogService->log([
            'action' => 'update_calendar_feed',
            'module' => 'calendar',
            'description' => sprintf('%s a modifié un flux calendrier', $this->actorLabel($request)),
            'payload' => [
                'feed_id' => (int) $calendarFeed->id,
                'url' => $calendarFeed->url,
                'before' => $beforeSnapshot,
                'after' => $this->feedAuditSnapshot($calendarFeed),
            ],
        ]);

        return back()->with('status', 'Calendrier public mis à jour.');
    }

    public function destroy(Request $request, CalendarFeed $calendarFeed): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.feed.manage');
        $beforeSnapshot = $this->feedAuditSnapshot($calendarFeed);
        $calendarFeed->delete();

        $this->auditLogService->log([
            'action' => 'delete_calendar_feed',
            'module' => 'calendar',
            'description' => sprintf('%s a supprimé un flux calendrier', $this->actorLabel($request)),
            'payload' => [
                'feed_id' => (int) $calendarFeed->id,
                'url' => $beforeSnapshot['url'] ?? null,
                'before' => $beforeSnapshot,
            ],
        ]);

        return back()->with('status', 'Calendrier public supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'url' => ['required', 'string', 'max:2000', 'regex:/^(https?|webcal):\/\/.+$/i'],
            'color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function normalizedColor(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $color = strtoupper(trim((string) $value));
        return $color === '' ? null : $color;
    }

    /**
     * @return array<string,mixed>
     */
    private function feedAuditSnapshot(CalendarFeed $calendarFeed): array
    {
        return [
            'id' => (int) $calendarFeed->id,
            'name' => $calendarFeed->name,
            'url' => $calendarFeed->url,
            'color' => $calendarFeed->color,
            'is_active' => (bool) $calendarFeed->is_active,
            'last_synced_at' => $calendarFeed->last_synced_at?->toIso8601String(),
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
