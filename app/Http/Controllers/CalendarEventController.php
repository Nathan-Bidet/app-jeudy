<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarEventController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        CalendarEvent::query()->create([
            'title' => trim((string) $validated['title']),
            'description' => $this->normalizedNullableString($validated['description'] ?? null),
            'start_at' => $validated['start_at'],
            'end_at' => $this->normalizedNullableString($validated['end_at'] ?? null),
            'all_day' => (bool) ($validated['all_day'] ?? false),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'depot_id' => isset($validated['depot_id']) ? (int) $validated['depot_id'] : null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return back()->with('status', 'Événement créé.');
    }

    public function update(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
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

        return back()->with('status', 'Événement mis à jour.');
    }

    public function destroy(CalendarEvent $calendarEvent): RedirectResponse
    {
        $calendarEvent->delete();

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
}
