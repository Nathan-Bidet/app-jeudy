<?php

namespace App\Services\Aprevoir;

use App\Models\AprevoirTask;
use App\Models\FormattingRule;
use App\Models\Transporter;
use App\Models\User;
use App\Services\FormattingRuleService;
use App\Services\Visibility\DateRestrictionScope;
use Illuminate\Database\Eloquent\Builder;

class AprevoirService
{
    public function __construct(private readonly FormattingRuleService $formattingRuleService)
    {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{groups: array<int, array<string,mixed>>, meta: array<string,mixed>}
     */
    public function getGroupedTasks(array $filters): array
    {
        /** @var User|null $viewer */
        $viewer = $filters['user'] ?? null;

        $query = AprevoirTask::query()
            ->with([
                'vehicle:id,name,registration,vehicle_type_id',
                'vehicle.type:id,code',
                'remorque:id,name,registration',
                'assigneeUser:id,name,first_name,last_name,sector_id,email,phone,mobile_phone,internal_number,depot_address,display_order',
                'assigneeTransporter:id,first_name,last_name,company_name,phone,email,display_order',
                'assigneeDepot:id,name,phone,email,address_line1,address_line2,postal_code,city,country',
                'createdBy:id,name,first_name,last_name',
                'updatedBy:id,name,first_name,last_name',
                'pointedBy:id,name,first_name,last_name',
            ]);

        if ($viewer) {
            DateRestrictionScope::apply($query, $viewer, 'a_prevoir');
        }

        $this->applyFilters($query, $filters);

        $tasks = $query
            ->orderBy('date')
            ->orderBy('assignee_type')
            ->orderBy('assignee_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $rules = $this->formattingRuleService->getActiveRulesForTarget(FormattingRuleService::TARGET_A_PREVOIR);

        $groups = [];

        foreach ($tasks as $task) {
            $style = $this->applyColorRules($task, $rules->all());

            if (($filters['color_filter'] ?? 'all') === 'colored' && ! $style['matched']) {
                continue;
            }

            if (($filters['color_filter'] ?? 'all') === 'unstyled' && $style['matched']) {
                continue;
            }

            $groupKey = $this->groupKey($task);
            $groupDate = $task->date?->toDateString();
            $assignee = $this->assigneeMeta($task);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'date' => $groupDate,
                    'date_label' => $task->date?->translatedFormat('l d/m/Y') ?? $groupDate,
                    'assignee' => $assignee,
                    'tasks' => [],
                ];
            }

            $groups[$groupKey]['tasks'][] = $this->mapTask($task, $style);
        }

        $sortedGroups = array_values($groups);
        usort($sortedGroups, fn (array $left, array $right): int => $this->compareGroups($left, $right));

        return [
            'groups' => $sortedGroups,
            'meta' => [
                'count_groups' => count($sortedGroups),
                'count_tasks' => array_sum(array_map(static fn (array $g): int => count($g['tasks']), $sortedGroups)),
            ],
        ];
    }

    /**
     * @param  AprevoirTask|array<string,mixed>  $task
     * @param  array<int, FormattingRule>|null  $rules
     * @return array{matched: bool, rule_id: ?int, rule_pattern: ?string, rule_name: ?string, text_color: ?string, bg_color: ?string}
     */
    public function applyColorRules(AprevoirTask|array $task, ?array $rules = null): array
    {
        $taskText = trim((string) data_get($task, 'task', ''));
        $comment = trim((string) data_get($task, 'comment', ''));
        $resolved = $rules === null
            ? $this->formattingRuleService->resolveFormatting($taskText, $comment, FormattingRuleService::TARGET_A_PREVOIR)
            : $this->formattingRuleService->resolveFormattingWithRules($taskText, $comment, FormattingRuleService::TARGET_A_PREVOIR, $rules);

        return [
            'matched' => $resolved['matchedRuleId'] !== null,
            'rule_id' => $resolved['matchedRuleId'],
            'rule_pattern' => $resolved['matchedPattern'],
            'rule_name' => $resolved['ruleName'],
            'text_color' => $resolved['textColor'],
            'bg_color' => $resolved['bgColor'],
        ];
    }

    /**
     * @return array{is_direct: bool, is_boursagri: bool}
     */
    public function autoDetectFlags(string $taskText, ?string $comment = null): array
    {
        $combined = mb_strtolower(trim($taskText.' '.($comment ?? '')));

        return [
            'is_direct' => str_contains($combined, 'direct'),
            'is_boursagri' => str_contains($combined, 'boursagri'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['assignee_type'])) {
            $type = (string) $filters['assignee_type'];
            $query->where('assignee_type', $type);

            if ($type !== 'free' && ! empty($filters['assignee_id'])) {
                $query->where('assignee_id', (int) $filters['assignee_id']);
            }
        } elseif (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', (int) $filters['assignee_id']);
        }

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', (int) $filters['vehicle_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $sub) use ($search): void {
                $sub->where('task', 'like', '%'.$search.'%')
                    ->orWhere('loading_place', 'like', '%'.$search.'%')
                    ->orWhere('delivery_place', 'like', '%'.$search.'%')
                    ->orWhere('comment', 'like', '%'.$search.'%')
                    ->orWhere('boursagri_contract_number', 'like', '%'.$search.'%')
                    ->orWhere('assignee_label_free', 'like', '%'.$search.'%')
                    ->orWhereRaw("DATE_FORMAT(`date`, '%d/%m/%Y') LIKE ?", ['%'.$search.'%'])
                    ->orWhereRaw("DATE_FORMAT(`date`, '%d/%m') LIKE ?", ['%'.$search.'%'])
                    ->orWhereRaw("DATE_FORMAT(`fin_date`, '%d/%m/%Y') LIKE ?", ['%'.$search.'%'])
                    ->orWhereRaw("DATE_FORMAT(`fin_date`, '%d/%m') LIKE ?", ['%'.$search.'%'])
                    ->orWhereHas('vehicle', function (Builder $vehicle) use ($search): void {
                        $vehicle
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('registration', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('remorque', function (Builder $vehicle) use ($search): void {
                        $vehicle
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('registration', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('assigneeUser', function (Builder $user) use ($search): void {
                        $user
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%')
                            ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", ['%'.$search.'%']);
                    })
                    ->orWhereHas('assigneeDepot', function (Builder $depot) use ($search): void {
                        $depot->where('name', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('assigneeTransporter', function (Builder $transporter) use ($search): void {
                        $transporter
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%')
                            ->orWhere('company_name', 'like', '%'.$search.'%')
                            ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", ['%'.$search.'%']);
                    });
            });
        }

        if (! empty($filters['only_boursagri'])) {
            $query->where('is_boursagri', true);
        }

        if (! empty($filters['boursagri_contract_number'])) {
            $query->where('boursagri_contract_number', 'like', '%'.trim((string) $filters['boursagri_contract_number']).'%');
        }

        if (($filters['pointed_filter'] ?? 'all') === 'pointed') {
            $query->where('pointed', true);
        } elseif (($filters['pointed_filter'] ?? 'all') === 'unpointed') {
            $query->where('pointed', false);
        }
    }

    private function groupKey(AprevoirTask $task): string
    {
        if ($task->assignee_type === 'free') {
            return implode('|', [
                $task->date?->toDateString() ?? '',
                'free',
                $task->assignee_label_free ?? '',
            ]);
        }

        return implode('|', [
            $task->date?->toDateString() ?? '',
            $task->assignee_type ?? 'none',
            $task->assignee_id !== null ? (string) $task->assignee_id : 'none',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function assigneeMeta(AprevoirTask $task): array
    {
        if ($task->assignee_type === 'free') {
            $label = trim((string) ($task->assignee_label_free ?? ''));
            return [
                'id' => 0,
                'type' => 'free',
                'name' => $label !== '' ? $label : 'Chauffeur libre',
                'display_order' => null,
                'phone' => null,
                'mobile_phone' => null,
                'email' => null,
                'internal_number' => null,
                'depot_address' => null,
            ];
        }

        if ($task->assignee_type === null || $task->assignee_id === null) {
            return [
                'id' => 0,
                'type' => 'none',
                'name' => 'Sans chauffeur',
                'phone' => null,
                'mobile_phone' => null,
                'email' => null,
                'internal_number' => null,
                'depot_address' => null,
            ];
        }

        if ($task->assignee_type === 'depot') {
            $depot = $task->assigneeDepot;
            return [
                'id' => (int) $task->assignee_id,
                'type' => 'depot',
                'name' => $depot?->name ?? ('Dépôt #'.$task->assignee_id),
                'phone' => $this->normalizePhone($depot?->phone),
                'mobile_phone' => null,
                'email' => $this->normalizeString($depot?->email),
                'internal_number' => null,
                'depot_address' => null,
                'address_line1' => $this->normalizeString($depot?->address_line1),
                'address_line2' => $this->normalizeString($depot?->address_line2),
                'postal_code' => $this->normalizeString($depot?->postal_code),
                'city' => $this->normalizeString($depot?->city),
                'country' => $this->normalizeString($depot?->country),
            ];
        }

        if ($task->assignee_type === 'transporter') {
            /** @var Transporter|null $transporter */
            $transporter = $task->assigneeTransporter;
            $name = trim((string) (($transporter?->first_name ?? '').' '.($transporter?->last_name ?? '')));

            if ($name === '') {
                $name = trim((string) ($transporter?->company_name ?? ''));
            }

            return [
                'id' => (int) $task->assignee_id,
                'type' => 'transporter',
                'name' => $name !== '' ? $name : ('Transporteur #'.$task->assignee_id),
                'display_order' => $transporter?->display_order,
                'phone' => $this->normalizePhone($transporter?->phone),
                'mobile_phone' => null,
                'email' => $this->normalizeString($transporter?->email),
                'internal_number' => null,
                'depot_address' => null,
                'company_name' => $this->normalizeString($transporter?->company_name),
            ];
        }

        $user = $task->assigneeUser;
        $name = trim((string) (($user?->first_name ?? '').' '.($user?->last_name ?? '')));

        return [
            'id' => (int) $task->assignee_id,
            'type' => 'user',
            'name' => $name !== '' ? $name : ($user?->name ?? ('Utilisateur #'.$task->assignee_id)),
            'display_order' => $user?->display_order,
            'phone' => $this->normalizePhone($user?->mobile_phone ?: $user?->phone),
            'mobile_phone' => $this->normalizePhone($user?->mobile_phone),
            'email' => $this->normalizeString($user?->email),
            'internal_number' => $this->normalizeString($user?->internal_number),
            'depot_address' => $this->normalizeString($user?->depot_address),
        ];
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    private function compareGroups(array $left, array $right): int
    {
        $leftDate = (string) ($left['date'] ?? '');
        $rightDate = (string) ($right['date'] ?? '');
        $dateCompare = strcmp($leftDate, $rightDate);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        $leftAssignee = is_array($left['assignee'] ?? null) ? $left['assignee'] : [];
        $rightAssignee = is_array($right['assignee'] ?? null) ? $right['assignee'] : [];

        $leftBucket = $this->assigneeBucket($leftAssignee);
        $rightBucket = $this->assigneeBucket($rightAssignee);
        if ($leftBucket !== $rightBucket) {
            return $leftBucket <=> $rightBucket;
        }

        if ($leftBucket === 0) {
            $leftOrder = $this->toNullableInt($leftAssignee['display_order'] ?? null);
            $rightOrder = $this->toNullableInt($rightAssignee['display_order'] ?? null);
            if ($leftOrder !== $rightOrder) {
                return ($leftOrder ?? 0) <=> ($rightOrder ?? 0);
            }
        }

        $leftName = mb_strtolower(trim((string) ($leftAssignee['name'] ?? '')));
        $rightName = mb_strtolower(trim((string) ($rightAssignee['name'] ?? '')));
        $nameCompare = $leftName <=> $rightName;
        if ($nameCompare !== 0) {
            return $nameCompare;
        }

        return ((int) ($leftAssignee['id'] ?? 0)) <=> ((int) ($rightAssignee['id'] ?? 0));
    }

    /**
     * 0: chauffeur avec ordre, 1: chauffeur sans ordre, 2: depot/autre, 3: sans chauffeur
     *
     * @param  array<string,mixed>  $assignee
     */
    private function assigneeBucket(array $assignee): int
    {
        $type = (string) ($assignee['type'] ?? '');
        if ($type === 'none' || $type === '') {
            return 3;
        }

        if ($type === 'user' || $type === 'transporter' || $type === 'free') {
            return $this->toNullableInt($assignee['display_order'] ?? null) === null ? 1 : 0;
        }

        return 2;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizePhone(?string $value): ?string
    {
        $phone = trim((string) $value);

        return $phone !== '' ? $phone : null;
    }

    private function normalizeString(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array{matched: bool, rule_id: ?int, rule_pattern: ?string, rule_name: ?string, text_color: ?string, bg_color: ?string}  $style
     * @return array<string,mixed>
     */
    private function mapTask(AprevoirTask $task, array $style): array
    {
        $createdName = $this->personName($task->createdBy);
        $updatedName = $this->personName($task->updatedBy);
        $pointedName = $this->personName($task->pointedBy);

        $indicators = is_array($task->indicators) ? $task->indicators : [];
        $isProjectedToLdt = true;

        return [
            'id' => $task->id,
            'date' => $task->date?->toDateString(),
            'fin_date' => $task->fin_date?->toDateString(),
            'fin_label' => $task->fin_date?->format('d/m'),
            'task' => $task->task,
            'loading_place' => $task->loading_place,
            'delivery_place' => $task->delivery_place,
            'comment' => $task->comment,
            'vehicle_id' => $task->vehicle_id,
            'vehicle' => $task->vehicle ? [
                'id' => $task->vehicle->id,
                'name' => $task->vehicle->name,
                'registration' => $task->vehicle->registration,
                'type_code' => $task->vehicle->type?->code,
                'mode' => $task->vehicle->type?->code === 'ensemble_pl' ? 'ensemble_pl' : 'vehicle',
            ] : null,
            'remorque_id' => $task->remorque_id,
            'remorque' => $task->remorque ? [
                'id' => $task->remorque->id,
                'name' => $task->remorque->name,
                'registration' => $task->remorque->registration,
            ] : null,
            'is_direct' => (bool) $task->is_direct,
            'is_boursagri' => (bool) $task->is_boursagri,
            'boursagri_contract_number' => $task->boursagri_contract_number,
            'assignee_label_free' => $task->assignee_label_free,
            'indicators' => $indicators,
            'pointed' => (bool) $task->pointed,
            'pointed_at' => $task->pointed_at?->toIso8601String(),
            'pointed_at_label' => $task->pointed_at?->format('d/m/Y H:i'),
            'pointed_by' => $pointedName,
            'position' => (int) $task->position,
            'created_by' => [
                'name' => $createdName,
                'initials' => $this->initials($task->createdBy),
            ],
            'updated_by' => [
                'name' => $updatedName,
                'initials' => $this->initials($task->updatedBy ?? $task->createdBy),
            ],
            'book' => [
                'projected' => $isProjectedToLdt,
                'url' => $isProjectedToLdt ? '/ldt?focus_task_id='.$task->id : null,
            ],
            'style' => $style,
        ];
    }

    private function personName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $full = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

        return $full !== '' ? $full : $user->name;
    }

    private function initials(?User $user): string
    {
        if (! $user) {
            return '--';
        }

        $first = mb_substr((string) ($user->first_name ?: $user->name ?: ''), 0, 1);
        $last = mb_substr((string) ($user->last_name ?: ''), 0, 1);

        $initials = mb_strtoupper(trim($first.$last));

        return $initials !== '' ? $initials : 'U';
    }
}
