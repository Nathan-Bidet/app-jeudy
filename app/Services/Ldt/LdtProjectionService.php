<?php

namespace App\Services\Ldt;

use App\Events\LdtEntryUpdated;
use App\Models\AprevoirTask;
use App\Models\Depot;
use App\Models\LdtEntry;
use App\Models\Transporter;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\FormattingRuleService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class LdtProjectionService
{
    public function __construct(
        private readonly FormattingRuleService $formattingRuleService,
        private readonly AuditLogService $auditLogService,
    )
    {
    }

    public function rebuildEntryForGroup(string $date, ?string $assigneeType, ?int $assigneeId, ?string $assigneeLabelFree = null): ?LdtEntry
    {
        $group = $this->normalizeGroup([
            'date' => $date,
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
            'assignee_label_free' => $assigneeLabelFree,
        ]);

        $tasks = $this->groupTasksQuery($group)->get();

        if ($tasks->isEmpty()) {
            $this->deleteEntryIfGroupEmpty($group['date'], $group['assignee_type'], $group['assignee_id']);

            return null;
        }

        $assignee = $this->resolveAssignee($group['assignee_type'], $group['assignee_id'], $group['assignee_label_free']);
        $phones = $this->resolvePhones($assignee, $group['assignee_type']);

        $tasksLines = $tasks->pluck('task')->map(fn ($value) => trim((string) $value))->filter()->values();
        $commentsLines = $tasks->pluck('comment')->map(fn ($value) => trim((string) $value))->filter()->values();

        $vehicles = $tasks
            ->map(function (AprevoirTask $task): ?string {
                $name = trim((string) ($task->vehicle?->name ?? ''));

                if ($name !== '') {
                    return $name;
                }

                $registration = trim((string) ($task->vehicle?->registration ?? ''));

                return $registration !== '' ? $registration : null;
            })
            ->filter()
            ->unique()
            ->values();

        $indicators = $this->mergeIndicators($tasks->pluck('indicators')->all());
        $styles = $this->resolveColorStyle($tasks->all());

        $entry = LdtEntry::query()->firstOrNew([
            'date' => $group['date'],
            'assignee_type' => $group['assignee_type'],
            'assignee_id' => $group['assignee_id'],
            'assignee_label_free' => $group['assignee_label_free'],
        ]);

        $entry->assignee_label = $assignee['label'];
        $entry->assignee_label_free = $group['assignee_label_free'];
        $entry->phones = $phones;
        $entry->tasks_text = $this->buildTasksText($tasksLines->all());
        $entry->comments_text = $commentsLines->isNotEmpty() ? implode("\n", $commentsLines->all()) : null;
        $entry->vehicles_text = $vehicles->isNotEmpty() ? implode(' • ', $vehicles->all()) : null;
        $entry->indicators = $indicators;
        $entry->is_all_pointed = $tasks->every(fn (AprevoirTask $task) => (bool) $task->pointed);
        $entry->source_task_ids = $tasks->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $entry->color_style = $styles;
        $entry->updated_from_source_at = now();
        $entry->save();

        $this->auditLogService->log([
            'action' => 'generate_ldt',
            'module' => 'ldt',
            'description' => 'Projection LDT generee/mise a jour',
            'payload' => [
                'entry_id' => $entry->id,
                'date' => $entry->date?->toDateString(),
                'assignee_type' => $entry->assignee_type,
                'assignee_id' => $entry->assignee_id,
                'source_task_ids' => is_array($entry->source_task_ids) ? $entry->source_task_ids : [],
                'tasks_count' => $tasks->count(),
            ],
        ]);

        $this->broadcastEntryUpdated($entry->id, $entry->date?->toDateString(), (int) $entry->assignee_id);

        return $entry;
    }

    public function handleSourceTaskChanged(?int $taskId, ?array $before = null, ?array $after = null): void
    {
        if ($before !== null) {
            $this->rebuildEntryForGroup(
                (string) $before['date'],
                $before['assignee_type'],
                $before['assignee_id'],
                $before['assignee_label_free'] ?? null
            );
        }

        if ($after !== null) {
            $this->rebuildEntryForGroup(
                (string) $after['date'],
                $after['assignee_type'],
                $after['assignee_id'],
                $after['assignee_label_free'] ?? null
            );
        }

        if ($before === null && $after === null && $taskId) {
            $task = AprevoirTask::query()->find($taskId);

            if ($task) {
                $snapshot = $this->normalizeGroup([
                    'date' => $task->date?->toDateString(),
                    'assignee_type' => $task->assignee_type,
                    'assignee_id' => $task->assignee_id,
                    'assignee_label_free' => $task->assignee_label_free,
                ]);

                $this->rebuildEntryForGroup(
                    (string) $snapshot['date'],
                    $snapshot['assignee_type'],
                    $snapshot['assignee_id'],
                    $snapshot['assignee_label_free']
                );
            }
        }
    }

    public function deleteEntryIfGroupEmpty(string $date, ?string $assigneeType, ?int $assigneeId): void
    {
        $group = $this->normalizeGroup([
            'date' => $date,
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
            'assignee_label_free' => null,
        ]);

        $hasSource = $this->groupTasksQuery($group)->exists();

        if ($hasSource) {
            return;
        }

        $deleted = LdtEntry::query()
            ->whereDate('date', $group['date'])
            ->where('assignee_type', $group['assignee_type'])
            ->where('assignee_id', $group['assignee_id'])
            ->where('assignee_label_free', $group['assignee_label_free'])
            ->delete();

        if ($deleted > 0) {
            $this->broadcastEntryUpdated(null, $group['date'], (int) $group['assignee_id']);
        }
    }

    /**
     * @return array{rebuilt:int, deleted:int}
     */
    public function rebuildRange(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = AprevoirTask::query()
            ->selectRaw("DATE(`date`) as `date`")
            ->selectRaw("IFNULL(`assignee_type`, 'none') as `assignee_type`")
            ->selectRaw("IFNULL(`assignee_id`, 0) as `assignee_id`")
            ->selectRaw("IFNULL(`assignee_label_free`, '') as `assignee_label_free`")
            ->distinct();

        if ($from) {
            $query->whereDate('date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->whereDate('date', '<=', $to->toDateString());
        }

        $groups = $query->get();
        $rebuilt = 0;

        foreach ($groups as $group) {
            $this->rebuildEntryForGroup(
                (string) $group->date,
                (string) $group->assignee_type,
                (int) $group->assignee_id,
                (string) $group->assignee_label_free
            );
            $rebuilt++;
        }

        $deleteQuery = LdtEntry::query();

        if ($from) {
            $deleteQuery->whereDate('date', '>=', $from->toDateString());
        }
        if ($to) {
            $deleteQuery->whereDate('date', '<=', $to->toDateString());
        }

        $deleted = 0;
        $deleteQuery->get()->each(function (LdtEntry $entry) use (&$deleted): void {
            $exists = AprevoirTask::query()
                ->whereDate('date', $entry->date)
                ->whereRaw("IFNULL(`assignee_type`, 'none') = ?", [$entry->assignee_type])
                ->whereRaw("IFNULL(`assignee_id`, 0) = ?", [$entry->assignee_id])
                ->whereRaw("IFNULL(`assignee_label_free`, '') = ?", [$entry->assignee_label_free])
                ->exists();

            if (! $exists) {
                $entry->delete();
                $deleted++;
            }
        });

        return ['rebuilt' => $rebuilt, 'deleted' => $deleted];
    }

    /**
     * @param  array{date:mixed, assignee_type:mixed, assignee_id:mixed, assignee_label_free:mixed}  $group
     * @return array{date:string, assignee_type:string, assignee_id:int, assignee_label_free:string}
     */
    private function normalizeGroup(array $group): array
    {
        $date = (string) ($group['date'] ?? '');
        $assigneeType = $group['assignee_type'];
        $assigneeId = $group['assignee_id'];
        $assigneeLabelFree = trim((string) ($group['assignee_label_free'] ?? ''));

        if ($assigneeType === 'free' && $assigneeLabelFree !== '') {
            return [
                'date' => $date,
                'assignee_type' => 'free',
                'assignee_id' => 0,
                'assignee_label_free' => $assigneeLabelFree,
            ];
        }

        if (! in_array($assigneeType, ['user', 'transporter', 'depot'], true)) {
            $assigneeType = 'none';
            $assigneeId = 0;
            $assigneeLabelFree = '';
        }

        return [
            'date' => $date,
            'assignee_type' => (string) $assigneeType,
            'assignee_id' => max(0, (int) ($assigneeId ?? 0)),
            'assignee_label_free' => $assigneeLabelFree,
        ];
    }

    /**
     * @param  array{date:string, assignee_type:string, assignee_id:int, assignee_label_free:string}  $group
     */
    private function groupTasksQuery(array $group): Builder
    {
        $query = AprevoirTask::query()
            ->with([
                'vehicle:id,name,registration',
                'assigneeUser:id,name,first_name,last_name,phone,mobile_phone,internal_number,directory_phones',
                'assigneeTransporter:id,first_name,last_name,company_name,phone,email',
                'assigneeDepot:id,name,phone,email,address_line1,address_line2,postal_code,city,country',
            ])
            ->whereDate('date', $group['date'])
            ->orderBy('position')
            ->orderBy('id');

        if ($group['assignee_type'] === 'free') {
            return $query
                ->where('assignee_type', 'free')
                ->where('assignee_label_free', $group['assignee_label_free']);
        }

        if ($group['assignee_type'] === 'none') {
            return $query->whereNull('assignee_type')->whereNull('assignee_id');
        }

        return $query
            ->where('assignee_type', $group['assignee_type'])
            ->where('assignee_id', $group['assignee_id']);
    }

    /**
     * @return array{label:string,payload:array<string,mixed>}
     */
    private function resolveAssignee(string $type, int $id, ?string $assigneeLabelFree = null): array
    {
        if ($type === 'free') {
            $label = trim((string) ($assigneeLabelFree ?? ''));
            return [
                'label' => $label !== '' ? $label : 'Chauffeur libre',
                'payload' => [
                    'type' => 'free',
                    'id' => 0,
                ],
            ];
        }

        if ($type === 'depot') {
            $depot = Depot::query()->find($id);

            return [
                'label' => $depot?->name ?: 'Dépôt #'.$id,
                'payload' => [
                    'phone' => $depot?->phone,
                    'email' => $depot?->email,
                    'address_line1' => $depot?->address_line1,
                    'address_line2' => $depot?->address_line2,
                    'postal_code' => $depot?->postal_code,
                    'city' => $depot?->city,
                    'country' => $depot?->country,
                ],
            ];
        }

        if ($type === 'user') {
            $user = User::query()->find($id);
            $name = trim((string) (($user?->first_name ?? '').' '.($user?->last_name ?? '')));

            return [
                'label' => $name !== '' ? $name : ($user?->name ?: 'Utilisateur #'.$id),
                'payload' => [
                    'phone' => $user?->phone,
                    'mobile_phone' => $user?->mobile_phone,
                    'internal_number' => $user?->internal_number,
                    'directory_phones' => is_array($user?->directory_phones) ? $user->directory_phones : [],
                ],
            ];
        }

        if ($type === 'transporter') {
            $transporter = Transporter::query()->find($id);
            $fullName = trim((string) (($transporter?->first_name ?? '').' '.($transporter?->last_name ?? '')));
            $company = trim((string) ($transporter?->company_name ?? ''));

            $label = $fullName !== ''
                ? ($company !== '' ? $fullName.' ('.$company.')' : $fullName)
                : ($company !== '' ? $company : ('Transporteur #'.$id));

            return [
                'label' => $label,
                'payload' => [
                    'phone' => $transporter?->phone,
                    'email' => $transporter?->email,
                ],
            ];
        }

        return [
            'label' => 'Sans chauffeur',
            'payload' => [],
        ];
    }

    /**
     * @param  array{label:string,payload:array<string,mixed>}  $assignee
     * @return array<int,array{label:string,number:string}>
     */
    private function resolvePhones(array $assignee, string $assigneeType): array
    {
        $pool = [];

        if ($assigneeType === 'user') {
            $pool[] = ['label' => 'Mobile', 'number' => $assignee['payload']['mobile_phone'] ?? null];
            $pool[] = ['label' => 'Téléphone', 'number' => $assignee['payload']['phone'] ?? null];
            $pool[] = ['label' => 'Interne', 'number' => $assignee['payload']['internal_number'] ?? null];

            $extra = $assignee['payload']['directory_phones'] ?? [];
            if (is_array($extra)) {
                foreach ($extra as $entry) {
                    $pool[] = [
                        'label' => trim((string) ($entry['label'] ?? 'Autre')),
                        'number' => $entry['number'] ?? null,
                    ];
                }
            }
        } elseif ($assigneeType === 'transporter') {
            $pool[] = ['label' => 'Téléphone', 'number' => $assignee['payload']['phone'] ?? null];
        } elseif ($assigneeType === 'depot') {
            $pool[] = ['label' => 'Téléphone', 'number' => $assignee['payload']['phone'] ?? null];
        }

        $seen = [];
        $result = [];

        foreach ($pool as $item) {
            $number = trim((string) ($item['number'] ?? ''));
            if ($number === '') {
                continue;
            }

            $normalized = preg_replace('/[^\d+]/', '', $number) ?? $number;
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $result[] = [
                'label' => trim((string) ($item['label'] ?? 'Téléphone')),
                'number' => $number,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int,string>  $lines
     */
    private function buildTasksText(array $lines): string
    {
        $lines = array_values(array_filter(array_map(static fn ($line) => trim((string) $line), $lines)));

        if (count($lines) <= 1) {
            return $lines[0] ?? '';
        }

        return implode("\n", array_map(static fn (string $line): string => '+ '.$line, $lines));
    }

    /**
     * @param  array<int,mixed>  $rawIndicators
     * @return array<int,mixed>|null
     */
    private function mergeIndicators(array $rawIndicators): ?array
    {
        $encoded = [];

        foreach ($rawIndicators as $indicator) {
            if ($indicator === null || $indicator === '' || $indicator === []) {
                continue;
            }

            $json = json_encode($indicator);
            if (! $json || isset($encoded[$json])) {
                continue;
            }
            $encoded[$json] = true;
        }

        if ($encoded === []) {
            return null;
        }

        return array_map(static fn (string $json) => json_decode($json, true), array_keys($encoded));
    }

    /**
     * @param  array<int,AprevoirTask>  $tasks
     * @return array<string,mixed>|null
     */
    private function resolveColorStyle(array $tasks): ?array
    {
        $rules = $this->formattingRuleService->getActiveRulesForTarget(FormattingRuleService::TARGET_LDT);

        if ($rules->isEmpty()) {
            return null;
        }

        $winner = null;

        foreach ($tasks as $task) {
            $style = $this->formattingRuleService->resolveFormattingWithRules(
                taskText: (string) ($task->task ?? ''),
                commentText: (string) ($task->comment ?? ''),
                targetModule: FormattingRuleService::TARGET_LDT,
                rules: $rules->all(),
            );

            if (($style['matchedRuleId'] ?? null) === null) {
                continue;
            }

            $priority = (int) ($style['matchedPriority'] ?? 9999);

            if ($winner === null || $priority < $winner['priority']) {
                $winner = [
                    'priority' => $priority,
                    'rule_id' => $style['matchedRuleId'],
                    'rule_name' => $style['ruleName'] ?? null,
                    'rule_pattern' => $style['matchedPattern'] ?? null,
                    'text_color' => $style['textColor'] ?? null,
                    'bg_color' => $style['bgColor'] ?? null,
                ];
            }
        }

        if ($winner === null) {
            return null;
        }

        return [
            'rule_id' => $winner['rule_id'],
            'rule_name' => $winner['rule_name'],
            'rule_pattern' => $winner['rule_pattern'],
            'text_color' => $winner['text_color'],
            'bg_color' => $winner['bg_color'],
        ];
    }

    private function broadcastEntryUpdated(?int $entryId, ?string $date, ?int $assigneeId): void
    {
        try {
            LdtEntryUpdated::dispatch($entryId, $date, $assigneeId);
        } catch (Throwable $exception) {
            Log::warning('LDT realtime broadcast failed on projection update.', [
                'entry_id' => $entryId,
                'date' => $date,
                'assignee_id' => $assigneeId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
