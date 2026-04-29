<?php

namespace App\Http\Controllers;

use App\Events\LdtEntryUpdated;
use App\Models\AprevoirTask;
use App\Models\Depot;
use App\Models\LdtEntry;
use App\Models\Transporter;
use App\Models\User;
use App\Services\FormattingRuleService;
use App\Services\Visibility\DateRestrictionScope;
use App\Support\Access\AccessManager;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LdtController extends Controller
{
    public function __construct(private readonly FormattingRuleService $formattingRuleService)
    {
    }

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'pointed_filter' => ['nullable', Rule::in(['all', 'done', 'todo'])],
            'focus_task_id' => ['nullable', 'integer'],
            'focus_entry_id' => ['nullable', 'integer'],
        ]);

        $pointedFilter = array_key_exists('pointed_filter', $validated) && $validated['pointed_filter'] !== null
            ? (string) $validated['pointed_filter']
            : 'todo';

        $query = LdtEntry::query()
            ->with('smsSentBy:id,name,first_name,last_name')
            ->whereIn('assignee_type', ['user', 'transporter', 'depot', 'free'])
            ->where(function ($sub): void {
                $sub->where('assignee_type', 'free')
                    ->orWhere('assignee_id', '>', 0);
            });

        $hasTransportersTable = Schema::hasTable('transporters');
        $hasUserDisplayOrder = Schema::hasColumn('users', 'display_order');
        $hasTransporterDisplayOrder = $hasTransportersTable && Schema::hasColumn('transporters', 'display_order');
        if ($hasUserDisplayOrder || $hasTransporterDisplayOrder) {
            $query
                ->leftJoin('users', function ($join): void {
                    $join->on('users.id', '=', 'ldt_entries.assignee_id')
                        ->where('ldt_entries.assignee_type', '=', 'user');
                });

            if ($hasTransportersTable) {
                $query->leftJoin('transporters', function ($join): void {
                    $join->on('transporters.id', '=', 'ldt_entries.assignee_id')
                        ->where('ldt_entries.assignee_type', '=', 'transporter');
                });
            }

            $query
                ->select('ldt_entries.*');
        }

        $viewer = $request->user();
        $canViewAllAssignees = $viewer && app(AccessManager::class)->can($viewer, 'ldt.view.all_assignees');

        if ($viewer) {
            DateRestrictionScope::apply($query, $viewer, 'ldt');

            if (! $canViewAllAssignees) {
                $query
                    ->where('assignee_type', 'user')
                    ->where('assignee_id', (int) $viewer->id);
            }
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('date', '>=', (string) $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('date', '<=', (string) $validated['date_to']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($sub) use ($search): void {
                $sub->where('assignee_label', 'like', '%'.$search.'%')
                    ->orWhere('tasks_text', 'like', '%'.$search.'%')
                    ->orWhere('comments_text', 'like', '%'.$search.'%')
                    ->orWhere('vehicles_text', 'like', '%'.$search.'%');
            });
        }

        if ($pointedFilter === 'done') {
            $query->where('is_all_pointed', true);
        } elseif ($pointedFilter === 'todo') {
            $query->where('is_all_pointed', false);
        }

        if ($hasUserDisplayOrder || $hasTransporterDisplayOrder) {
            $displayOrderExpression = 'users.display_order';
            if ($hasUserDisplayOrder && $hasTransporterDisplayOrder) {
                $displayOrderExpression = 'COALESCE(users.display_order, transporters.display_order)';
            } elseif (! $hasUserDisplayOrder && $hasTransporterDisplayOrder) {
                $displayOrderExpression = 'transporters.display_order';
            }
            $query
                ->orderBy('ldt_entries.date')
                ->orderByRaw("CASE WHEN {$displayOrderExpression} IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw($displayOrderExpression)
                ->orderBy('ldt_entries.assignee_label');
        } else {
            $query
                ->orderBy('date')
                ->orderBy('assignee_label');
        }

        $entries = $query->get();

        $taskMap = $this->buildSourceTaskMap($entries);
        $assigneeMap = $this->buildAssigneeUserMap($entries);
        $assigneeEmailMap = $this->buildTransporterEmailMap($entries);
        $depotPlaceMap = $this->buildDepotPlaceMap();

        $groups = $entries
            ->groupBy(fn (LdtEntry $entry) => $entry->date?->toDateString() ?? '')
            ->map(function ($items, $date) use ($taskMap, $assigneeMap, $assigneeEmailMap): array {
                return [
                    'date' => $date,
                    'date_label' => optional($items->first()?->date)->translatedFormat('l d/m/Y') ?? $date,
                    'entries' => $items->map(fn (LdtEntry $entry) => $this->mapEntry($entry, $taskMap, $assigneeMap, $assigneeEmailMap))->values()->all(),
                ];
            })
            ->values()
            ->all();

        $focusEntryId = null;
        if (! empty($validated['focus_entry_id'])) {
            $focusEntryId = (int) $validated['focus_entry_id'];
        } elseif (! empty($validated['focus_task_id'])) {
            $focusQuery = LdtEntry::query()
                ->whereIn('assignee_type', ['user', 'transporter', 'depot', 'free'])
                ->where(function ($sub): void {
                    $sub->where('assignee_type', 'free')
                        ->orWhere('assignee_id', '>', 0);
                })
                ->whereJsonContains('source_task_ids', (int) $validated['focus_task_id']);

            if ($viewer) {
                DateRestrictionScope::apply($focusQuery, $viewer, 'ldt');

                if (! $canViewAllAssignees) {
                    $focusQuery
                        ->where('assignee_type', 'user')
                        ->where('assignee_id', (int) $viewer->id);
                }
            }

            $focusEntryId = $focusQuery->value('id');
        }

        $access = app(AccessManager::class);

        return Inertia::render('Ldt/Index', [
            'groups' => $groups,
            'filters' => [
                'date_from' => $validated['date_from'] ?? '',
                'date_to' => $validated['date_to'] ?? '',
                'search' => $validated['search'] ?? '',
                'pointed_filter' => $pointedFilter,
            ],
            'meta' => [
                'count_groups' => count($groups),
                'count_entries' => $entries->count(),
            ],
            'focus_entry_id' => $focusEntryId,
            'focus_task_id' => $validated['focus_task_id'] ?? null,
            'permissions' => [
                'can_sms_mark' => $request->user() ? $access->can($request->user(), 'ldt.sms') : false,
            ],
            'depot_place_map' => $depotPlaceMap,
        ]);
    }

    public function markSms(Request $request, LdtEntry $entry): RedirectResponse
    {
        $validated = $request->validate([
            'sms_sent' => ['nullable', 'boolean'],
        ]);

        $smsSent = array_key_exists('sms_sent', $validated)
            ? (bool) $validated['sms_sent']
            : ! (bool) $entry->sms_sent;

        $entry->sms_sent = $smsSent;
        $entry->sms_sent_at = $smsSent ? now() : null;
        $entry->sms_sent_by_user_id = $smsSent ? $request->user()?->id : null;
        $entry->save();
        try {
            LdtEntryUpdated::dispatch($entry->id, $entry->date?->toDateString(), (int) $entry->assignee_id);
        } catch (Throwable $exception) {
            Log::warning('LDT realtime broadcast failed on sms mark.', [
                'entry_id' => $entry->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Statut SMS du LDT enregistré.');
    }

    /**
     * @return array<string,mixed>
     */
    private function mapEntry(LdtEntry $entry, Collection $taskMap, Collection $assigneeMap, Collection $assigneeEmailMap): array
    {
        $tasksLines = array_values(array_filter(array_map(
            static fn ($line) => trim((string) $line),
            preg_split('/\r\n|\r|\n/', (string) $entry->tasks_text) ?: [],
        )));

        $commentsLines = array_values(array_filter(array_map(
            static fn ($line) => trim((string) $line),
            preg_split('/\r\n|\r|\n/', (string) ($entry->comments_text ?? '')) ?: [],
        )));

        $taskItems = [];
        $sourceTaskIds = is_array($entry->source_task_ids) ? $entry->source_task_ids : [];
        foreach ($sourceTaskIds as $taskId) {
            $task = $taskMap->get((int) $taskId);
            if (! is_array($task)) {
                continue;
            }

            $taskText = trim((string) ($task['task'] ?? ''));
            if ($taskText === '') {
                continue;
            }

            $taskItems[] = [
                'id' => (int) ($task['id'] ?? 0),
                'task' => $taskText,
                'loading_place' => trim((string) ($task['loading_place'] ?? '')),
                'delivery_place' => trim((string) ($task['delivery_place'] ?? '')),
                'comment' => trim((string) ($task['comment'] ?? '')),
                'vehicle_label' => trim((string) ($task['vehicle_label'] ?? '')),
                'remorque_label' => trim((string) ($task['remorque_label'] ?? '')),
                'transport' => is_array($task['transport'] ?? null) ? $task['transport'] : null,
                'style' => is_array($task['style'] ?? null) ? $task['style'] : null,
            ];
        }

        if ($taskItems === [] && $tasksLines !== []) {
            foreach ($tasksLines as $index => $line) {
                $comment = $commentsLines[$index] ?? '';

                $taskItems[] = [
                    'id' => $index + 1,
                    'task' => $line,
                    'loading_place' => '',
                    'delivery_place' => '',
                    'comment' => $comment,
                    'vehicle_label' => trim((string) ($entry->vehicles_text ?? '')),
                    'remorque_label' => '',
                    'transport' => [
                        'mode' => trim((string) ($entry->vehicles_text ?? '')) !== '' ? 'vehicle' : 'none',
                        'ensemble_label' => '',
                        'camion_label' => trim((string) ($entry->vehicles_text ?? '')),
                        'remorque_label' => '',
                    ],
                    'style' => $this->resolveLineStyle((string) $line, (string) $comment),
                ];
            }
        }

        return [
            'id' => $entry->id,
            'date' => $entry->date?->toDateString(),
            'date_label' => $entry->date?->translatedFormat('l d/m/Y'),
            'assignee_type' => $entry->assignee_type,
            'assignee_id' => $entry->assignee_id,
            'assignee_label' => $entry->assignee_label,
            'assignee_photo_url' => $assigneeMap->get($entry->assignee_type.':'.(int) $entry->assignee_id),
            'assignee_email' => $assigneeEmailMap->get($entry->assignee_type.':'.(int) $entry->assignee_id),
            'phones' => is_array($entry->phones) ? $entry->phones : [],
            'tasks_text' => $entry->tasks_text,
            'tasks_lines' => $tasksLines,
            'task_items' => $taskItems,
            'tasks_count' => count($tasksLines),
            'comments_text' => $entry->comments_text,
            'comments_lines' => $commentsLines,
            'vehicles_text' => $entry->vehicles_text,
            'indicators' => is_array($entry->indicators) ? $entry->indicators : [],
            'is_all_pointed' => (bool) $entry->is_all_pointed,
            'sms_sent' => (bool) $entry->sms_sent,
            'sms_sent_at' => $entry->sms_sent_at?->toIso8601String(),
            'sms_sent_at_label' => $entry->sms_sent_at?->format('d/m/Y H:i'),
            'sms_sent_by' => $entry->smsSentBy?->name,
            'sms_sent_by_initials' => $this->initials($entry->smsSentBy),
            'source_task_ids' => is_array($entry->source_task_ids) ? $entry->source_task_ids : [],
            'color_style' => is_array($entry->color_style) ? $entry->color_style : null,
            'updated_from_source_at' => $entry->updated_from_source_at?->toIso8601String(),
            'updated_from_source_at_label' => $entry->updated_from_source_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @param  Collection<int, LdtEntry>  $entries
     * @return Collection<int, array{id:int,task:string,comment:?string,vehicle_label:string,remorque_label:string,transport:array<string,mixed>,style:?array<string,mixed>}>
     */
    private function buildSourceTaskMap(Collection $entries): Collection
    {
        $ids = $entries
            ->flatMap(fn (LdtEntry $entry) => is_array($entry->source_task_ids) ? $entry->source_task_ids : [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return AprevoirTask::query()
            ->with([
                'vehicle:id,name,registration,vehicle_type_id,tractor_vehicle_id',
                'vehicle.type:id,code',
                'vehicle.tractor:id,name,registration',
                'vehicle.bennes:id,name,registration',
                'remorque:id,name,registration',
            ])
            ->whereIn('id', $ids->all())
            ->get(['id', 'task', 'loading_place', 'delivery_place', 'comment', 'vehicle_id', 'remorque_id'])
            ->mapWithKeys(function (AprevoirTask $task): array {
                $vehicleLabel = $this->composeVehicleLabel(
                    $task->vehicle?->name,
                    $task->vehicle?->registration
                );
                $remorqueLabel = $this->composeVehicleLabel(
                    $task->remorque?->name,
                    $task->remorque?->registration
                );

                $transport = [
                    'mode' => 'none',
                    'ensemble_label' => '',
                    'camion_label' => '',
                    'remorque_label' => '',
                ];

                if ($task->vehicle && ($task->vehicle->type?->code === 'ensemble_pl')) {
                    $transport['mode'] = 'ensemble_pl';
                    $transport['ensemble_label'] = $vehicleLabel;
                    $transport['camion_label'] = $this->composeVehicleLabel(
                        $task->vehicle->tractor?->name,
                        $task->vehicle->tractor?->registration
                    );

                    $firstBenne = $task->vehicle->bennes->first();
                    $transport['remorque_label'] = $this->composeVehicleLabel(
                        $firstBenne?->name,
                        $firstBenne?->registration
                    );
                } else {
                    if ($vehicleLabel !== '' || $remorqueLabel !== '') {
                        $transport['mode'] = 'vehicle';
                    }
                    $transport['camion_label'] = $vehicleLabel;
                    $transport['remorque_label'] = $remorqueLabel;
                }

                $effectiveRemorqueLabel = trim((string) ($transport['remorque_label'] ?? ''));

                return [
                    (int) $task->id => [
                        'id' => (int) $task->id,
                        'task' => (string) $task->task,
                        'loading_place' => (string) ($task->loading_place ?? ''),
                        'delivery_place' => (string) ($task->delivery_place ?? ''),
                        'comment' => $task->comment,
                        'vehicle_label' => $vehicleLabel,
                        'remorque_label' => $effectiveRemorqueLabel,
                        'transport' => $transport,
                        'style' => $this->resolveLineStyle((string) ($task->task ?? ''), (string) ($task->comment ?? '')),
                    ],
                ];
            });
    }

    /**
     * @return array{rule_id:?int,rule_name:?string,rule_pattern:?string,text_color:?string,bg_color:?string}|null
     */
    private function resolveLineStyle(string $taskText, ?string $comment): ?array
    {
        $resolved = $this->formattingRuleService->resolveFormatting(
            $taskText,
            $comment,
            FormattingRuleService::TARGET_LDT,
        );

        if (($resolved['matchedRuleId'] ?? null) === null) {
            return null;
        }

        return [
            'rule_id' => $resolved['matchedRuleId'] ?? null,
            'rule_name' => $resolved['ruleName'] ?? null,
            'rule_pattern' => $resolved['matchedPattern'] ?? null,
            'text_color' => $resolved['textColor'] ?? null,
            'bg_color' => $resolved['bgColor'] ?? null,
        ];
    }

    private function composeVehicleLabel(?string $name, ?string $registration): string
    {
        $vehicleName = trim((string) ($name ?? ''));
        $vehicleRegistration = trim((string) ($registration ?? ''));

        return trim($vehicleName.($vehicleRegistration !== '' ? ' • '.$vehicleRegistration : ''));
    }

    /**
     * @param  Collection<int, LdtEntry>  $entries
     * @return Collection<string, string|null>
     */
    private function buildAssigneeUserMap(Collection $entries): Collection
    {
        $userIds = $entries
            ->filter(fn (LdtEntry $entry) => $entry->assignee_type === 'user' && (int) $entry->assignee_id > 0)
            ->pluck('assignee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds->all())
            ->get(['id', 'photo_path'])
            ->mapWithKeys(fn (User $user): array => [
                'user:'.(int) $user->id => $this->photoUrl($user->photo_path),
            ]);
    }

    /**
     * @param  Collection<int, LdtEntry>  $entries
     * @return Collection<string, string|null>
     */
    private function buildTransporterEmailMap(Collection $entries): Collection
    {
        $transporterIds = $entries
            ->filter(fn (LdtEntry $entry) => $entry->assignee_type === 'transporter' && (int) $entry->assignee_id > 0)
            ->pluck('assignee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($transporterIds->isEmpty()) {
            return collect();
        }

        return Transporter::query()
            ->whereIn('id', $transporterIds->all())
            ->get(['id', 'email'])
            ->mapWithKeys(function (Transporter $transporter): array {
                $email = trim((string) ($transporter->email ?? ''));

                return [
                    'transporter:'.(int) $transporter->id => $email !== '' ? $email : null,
                ];
            });
    }

    /**
     * @return array<string,string>
     */
    private function buildDepotPlaceMap(): array
    {
        return Depot::query()
            ->orderBy('name')
            ->get(['name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country'])
            ->reduce(function (array $carry, Depot $depot): array {
                $name = trim((string) ($depot->name ?? ''));
                if ($name === '') {
                    return $carry;
                }

                $address = trim(implode(', ', array_filter([
                    $depot->address_line1,
                    $depot->address_line2,
                    trim(implode(' ', array_filter([$depot->postal_code, $depot->city]))),
                    $depot->country,
                ])));

                if ($address !== '') {
                    $carry[$name] = $address;
                }

                return $carry;
            }, []);
    }

    private function photoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/'.$path;
    }

    private function initials(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $first = trim((string) ($user->first_name ?? ''));
        $last = trim((string) ($user->last_name ?? ''));

        if ($first !== '' || $last !== '') {
            $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
            return $initials !== '' ? $initials : null;
        }

        $name = trim((string) ($user->name ?? ''));
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $a = mb_substr((string) ($parts[0] ?? ''), 0, 1);
        $b = mb_substr((string) ($parts[1] ?? ''), 0, 1);
        $initials = mb_strtoupper($a.$b);

        return $initials !== '' ? $initials : null;
    }
}
