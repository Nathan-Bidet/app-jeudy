<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarEventController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.event.manage');
        $validated = $this->validatePayload($request);

        $createdEvent = CalendarEvent::query()->create([
            'title' => trim((string) $validated['title']),
            'description' => $this->normalizedNullableString($validated['description'] ?? null),
            'start_at' => $validated['start_at'],
            'end_at' => $this->normalizedNullableString($validated['end_at'] ?? null),
            'all_day' => (bool) ($validated['all_day'] ?? false),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'depot_id' => isset($validated['depot_id']) ? (int) $validated['depot_id'] : null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        $actorLabel = $this->actorLabel($request);
        $duplicatedFromEventId = $request->integer('duplicated_from_event_id') ?: $request->integer('source_event_id');
        $isDuplicate = $duplicatedFromEventId > 0;

        $this->auditLogService->log([
            'action' => $isDuplicate ? 'duplicate_calendar_event' : 'create_calendar_event',
            'module' => 'calendar',
            'description' => $isDuplicate
                ? sprintf('%s a dupliqué un événement Calendrier', $actorLabel)
                : sprintf('%s a créé un événement Calendrier', $actorLabel),
            'payload' => [
                'event_id' => (int) $createdEvent->id,
                'duplicated_from_event_id' => $isDuplicate ? $duplicatedFromEventId : null,
                'event' => $this->eventAuditSnapshot($createdEvent),
                'before' => null,
                'after' => $this->eventAuditSnapshot($createdEvent),
                'target_user_ids' => $this->normalizeUserIds($request->input('user_ids')),
            ],
        ]);

        return back()->with('status', 'Événement créé.');
    }

    public function update(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.event.manage');
        $beforeSnapshot = $this->eventAuditSnapshot($calendarEvent);
        $validated = $this->validatePayload($request);

        $calendarEvent->update([
            'title' => trim((string) $validated['title']),
            'description' => $this->normalizedNullableString($validated['description'] ?? null),
            'start_at' => $validated['start_at'],
            'end_at' => $this->normalizedNullableString($validated['end_at'] ?? null),
            'all_day' => (bool) ($validated['all_day'] ?? false),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'depot_id' => isset($validated['depot_id']) ? (int) $validated['depot_id'] : null,
        ]);
        $calendarEvent->refresh();
        $afterSnapshot = $this->eventAuditSnapshot($calendarEvent);

        $action = $this->resolveUpdateAction($beforeSnapshot, $afterSnapshot);
        $actorLabel = $this->actorLabel($request);
        $description = match ($action) {
            'move_calendar_event' => sprintf(
                '%s a déplacé un événement du %s au %s',
                $actorLabel,
                (string) ($beforeSnapshot['start_at'] ?? ''),
                (string) ($afterSnapshot['start_at'] ?? '')
            ),
            'resize_calendar_event' => sprintf(
                '%s a redimensionné un événement Calendrier',
                $actorLabel
            ),
            default => sprintf('%s a modifié un événement Calendrier', $actorLabel),
        };

        $this->auditLogService->log([
            'action' => $action,
            'module' => 'calendar',
            'description' => $description,
            'payload' => [
                'event_id' => (int) $calendarEvent->id,
                'target_user_ids' => $this->normalizeUserIds($request->input('user_ids')),
                'before' => $beforeSnapshot,
                'after' => $afterSnapshot,
            ],
        ]);

        return back()->with('status', 'Événement mis à jour.');
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        $this->assertCanManage($request, 'calendar.event.manage');
        $beforeSnapshot = $this->eventAuditSnapshot($calendarEvent);
        $calendarEvent->delete();

        $this->auditLogService->log([
            'action' => 'delete_calendar_event',
            'module' => 'calendar',
            'description' => sprintf('%s a supprimé un événement Calendrier', $this->actorLabel($request)),
            'payload' => [
                'event_id' => (int) $calendarEvent->id,
                'event' => $beforeSnapshot,
                'before' => $beforeSnapshot,
                'after' => null,
            ],
        ]);

        return back()->with('status', 'Événement supprimé.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer', Rule::exists('calendar_categories', 'id')],
            'depot_id' => ['nullable', 'integer', Rule::exists('depots', 'id')],
        ]);
    }

    private function normalizedNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    private function resolveUpdateAction(array $before, array $after): string
    {
        $startChanged = ($before['start_at'] ?? null) !== ($after['start_at'] ?? null);
        $endChanged = ($before['end_at'] ?? null) !== ($after['end_at'] ?? null);

        if ($startChanged && ! $endChanged) {
            return 'move_calendar_event';
        }

        if (! $startChanged && $endChanged) {
            return 'resize_calendar_event';
        }

        if ($startChanged && $endChanged) {
            return 'move_calendar_event';
        }

        return 'update_calendar_event';
    }

    /**
     * @return array<string,mixed>
     */
    private function eventAuditSnapshot(CalendarEvent $calendarEvent): array
    {
        return [
            'id' => (int) $calendarEvent->id,
            'title' => $calendarEvent->title,
            'description' => $calendarEvent->description,
            'start_at' => $calendarEvent->start_at?->format('Y-m-d H:i:s'),
            'end_at' => $calendarEvent->end_at?->format('Y-m-d H:i:s'),
            'all_day' => (bool) $calendarEvent->all_day,
            'category_id' => $calendarEvent->category_id ? (int) $calendarEvent->category_id : null,
            'depot_id' => $calendarEvent->depot_id ? (int) $calendarEvent->depot_id : null,
        ];
    }

    /**
     * @return array<int>
     */
    private function normalizeUserIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            array_filter($value, static fn ($id): bool => is_numeric($id))
        )));
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
