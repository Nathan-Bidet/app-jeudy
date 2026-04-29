<?php

namespace App\Http\Controllers;

use App\Models\CalendarCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CalendarCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        CalendarCategory::query()->create([
            'name' => trim((string) $validated['name']),
            'color' => strtoupper((string) ($validated['color'] ?? '#0F6930')),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return back()->with('status', 'Catégorie créée.');
    }

    public function update(Request $request, CalendarCategory $calendarCategory): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $calendarCategory->update([
            'name' => trim((string) $validated['name']),
            'color' => strtoupper((string) ($validated['color'] ?? '#0F6930')),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return back()->with('status', 'Catégorie mise à jour.');
    }

    public function destroy(CalendarCategory $calendarCategory): RedirectResponse
    {
        $calendarCategory->delete();

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
}

