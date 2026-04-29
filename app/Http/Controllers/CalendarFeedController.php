<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CalendarFeedController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        CalendarFeed::query()->create([
            'name' => trim((string) $validated['name']),
            'url' => trim((string) $validated['url']),
            'color' => $this->normalizedColor($validated['color'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return back()->with('status', 'Calendrier public ajouté.');
    }

    public function update(Request $request, CalendarFeed $calendarFeed): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $calendarFeed->update([
            'name' => trim((string) $validated['name']),
            'url' => trim((string) $validated['url']),
            'color' => $this->normalizedColor($validated['color'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return back()->with('status', 'Calendrier public mis à jour.');
    }

    public function destroy(CalendarFeed $calendarFeed): RedirectResponse
    {
        $calendarFeed->delete();

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
}
