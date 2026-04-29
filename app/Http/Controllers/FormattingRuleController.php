<?php

namespace App\Http\Controllers;

use App\Models\FormattingRule;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FormattingRuleController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FormattingRule::class);

        $canManage = $request->user()?->can('create', FormattingRule::class) ?? false;

        $rules = FormattingRule::query()
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (FormattingRule $rule): array {
                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'scope' => $rule->scope,
                    'match_type' => $rule->match_type,
                    'pattern' => $rule->pattern,
                    'text_color' => $rule->text_color,
                    'bg_color' => $rule->bg_color,
                    'priority' => (int) $rule->priority,
                    'is_active' => (bool) $rule->is_active,
                    'applies_to_a_prevoir' => (bool) $rule->applies_to_a_prevoir,
                    'applies_to_ldt' => (bool) $rule->applies_to_ldt,
                    'description' => $rule->description,
                    'created_at' => $rule->created_at?->toIso8601String(),
                    'updated_at' => $rule->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('TaskFormatting/Index', [
            'rules' => $rules,
            'permissions' => [
                'can_manage' => (bool) $canManage,
            ],
            'options' => [
                'scopes' => ['task', 'comment', 'both'],
                'match_types' => ['contains', 'starts_with', 'regex'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', FormattingRule::class);

        $payload = $this->validatedPayload($request);
        $nextPriority = ((int) FormattingRule::query()->max('priority')) + 1;

        $rule = FormattingRule::query()->create([
            ...$payload,
            'priority' => $nextPriority > 0 ? $nextPriority : 1,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        $this->auditLogService->log([
            'action' => 'create_rule',
            'module' => 'formatting',
            'description' => 'Creation d une regle de mise en forme',
            'payload' => [
                'rule_id' => $rule->id,
                'after' => $rule->only([
                    'id',
                    'name',
                    'scope',
                    'match_type',
                    'pattern',
                    'text_color',
                    'bg_color',
                    'priority',
                    'is_active',
                    'applies_to_a_prevoir',
                    'applies_to_ldt',
                    'description',
                ]),
            ],
        ]);

        return back()->with('status', 'Formatting rule created.');
    }

    public function update(Request $request, FormattingRule $formattingRule): RedirectResponse
    {
        $this->authorize('update', $formattingRule);

        $before = $formattingRule->only([
            'id',
            'name',
            'scope',
            'match_type',
            'pattern',
            'text_color',
            'bg_color',
            'priority',
            'is_active',
            'applies_to_a_prevoir',
            'applies_to_ldt',
            'description',
        ]);
        $payload = $this->validatedPayload($request);

        $formattingRule->forceFill([
            ...$payload,
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        $this->auditLogService->log([
            'action' => 'update_rule',
            'module' => 'formatting',
            'description' => 'Mise a jour d une regle de mise en forme',
            'payload' => [
                'rule_id' => $formattingRule->id,
                'before' => $before,
                'after' => $formattingRule->only([
                    'id',
                    'name',
                    'scope',
                    'match_type',
                    'pattern',
                    'text_color',
                    'bg_color',
                    'priority',
                    'is_active',
                    'applies_to_a_prevoir',
                    'applies_to_ldt',
                    'description',
                ]),
            ],
        ]);

        return back()->with('status', 'Formatting rule updated.');
    }

    public function destroy(FormattingRule $formattingRule): RedirectResponse
    {
        $this->authorize('delete', $formattingRule);

        $before = $formattingRule->only([
            'id',
            'name',
            'scope',
            'match_type',
            'pattern',
            'text_color',
            'bg_color',
            'priority',
            'is_active',
            'applies_to_a_prevoir',
            'applies_to_ldt',
            'description',
        ]);
        $formattingRule->delete();

        $this->auditLogService->log([
            'action' => 'delete_rule',
            'module' => 'formatting',
            'description' => 'Suppression d une regle de mise en forme',
            'payload' => [
                'rule_id' => $before['id'] ?? null,
                'before' => $before,
            ],
        ]);

        return back()->with('status', 'Formatting rule deleted.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $this->authorize('create', FormattingRule::class);

        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'distinct'],
        ]);

        $orderedIds = array_map('intval', $validated['ordered_ids']);
        $existingIds = FormattingRule::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sortedOrdered = $orderedIds;

        sort($existingIds);
        sort($sortedOrdered);

        if ($existingIds !== $sortedOrdered) {
            throw ValidationException::withMessages([
                'ordered_ids' => 'Réordonnancement invalide: la sélection doit correspondre exactement aux règles existantes.',
            ]);
        }

        DB::transaction(function () use ($orderedIds, $request): void {
            foreach ($orderedIds as $index => $id) {
                FormattingRule::query()
                    ->whereKey($id)
                    ->update([
                        'priority' => $index + 1,
                        'updated_by_user_id' => $request->user()?->id,
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->auditLogService->log([
            'action' => 'update_rule',
            'module' => 'formatting',
            'description' => 'Reordonnancement des regles de mise en forme',
            'payload' => [
                'ordered_ids' => $orderedIds,
            ],
        ]);

        return back()->with('status', 'Formatting rules reordered.');
    }

    /**
     * @return array<string,mixed>
     * @throws ValidationException
     */
    private function validatedPayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in(['task', 'comment', 'both'])],
            'match_type' => ['required', Rule::in(['contains', 'starts_with', 'regex'])],
            'pattern' => ['required', 'string', 'max:500'],
            'text_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'bg_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'is_active' => ['nullable', 'boolean'],
            'applies_to_a_prevoir' => ['nullable', 'boolean'],
            'applies_to_ldt' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if (($validated['match_type'] ?? null) === 'regex' && @preg_match((string) $validated['pattern'], '') === false) {
            throw ValidationException::withMessages([
                'pattern' => 'Expression regex invalide.',
            ]);
        }

        $appliesToAprevoir = (bool) ($validated['applies_to_a_prevoir'] ?? true);
        $appliesToLdt = (bool) ($validated['applies_to_ldt'] ?? true);

        if (! $appliesToAprevoir && ! $appliesToLdt) {
            throw ValidationException::withMessages([
                'applies_to_a_prevoir' => 'Sélectionnez au moins un module cible (À Prévoir ou LDT).',
                'applies_to_ldt' => 'Sélectionnez au moins un module cible (À Prévoir ou LDT).',
            ]);
        }

        return [
            'name' => trim((string) ($validated['name'] ?? '')),
            'scope' => (string) $validated['scope'],
            'match_type' => (string) $validated['match_type'],
            'pattern' => trim((string) ($validated['pattern'] ?? '')),
            'text_color' => $this->nullableTrimmedString($validated['text_color'] ?? null),
            'bg_color' => $this->nullableTrimmedString($validated['bg_color'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'applies_to_a_prevoir' => $appliesToAprevoir,
            'applies_to_ldt' => $appliesToLdt,
            'description' => $this->nullableTrimmedString($validated['description'] ?? null),
        ];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
