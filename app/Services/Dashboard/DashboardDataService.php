<?php

namespace App\Services\Dashboard;

use App\Models\AprevoirTask;
use App\Models\CotationSetting;
use App\Models\LdtEntry;
use App\Models\User;
use App\Support\Access\AccessManager;
use App\Support\Cotations\CotationPdfFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class DashboardDataService
{
    /**
     * @return array<string, mixed>
     */
    public function buildForUser(User $user): array
    {
        $isAdmin = $user->hasRole('admin');
        $sectorName = $user->sector?->name ?? 'Secteur non défini';

        return [
            'meta' => [
                'scope' => $isAdmin ? 'global' : 'sector',
                'scope_label' => $isAdmin ? 'Vue globale administrateur' : "Vue secteur : {$sectorName}",
                'generated_at' => now()->toIso8601String(),
            ],
            'widgets' => array_values(array_filter([
                $this->tasksWidget($user),
                $this->cotationsWidget($user),
                $this->quickAccessWidget($user),
            ])),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tasksWidget(User $user): ?array
    {
        $items = $this->upcomingTasksForUser($user);

        if (empty($items)) {
            return null;
        }

        return [
            'key' => 'tasks-today',
            'title' => 'Mes tâches',
            'type' => 'list',
            'icon' => 'check',
            'accent' => 'yellow',
            'clickable' => true,
            'href' => $this->safeRoute('ldt.index', ['search' => $user->name]),
            'items' => $items,
            'empty_message' => 'Aucune tâche à venir',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cotationsWidget(User $user): ?array
    {
        $access = app(AccessManager::class);
        $canViewCereals = $access->can($user, 'cotations.cereals.view') || $access->can($user, 'cotations.cereals.edit');
        $canViewFuel = $access->can($user, 'cotations.fuel.view') || $access->can($user, 'cotations.fuel.edit');

        if ((! $canViewCereals && ! $canViewFuel) || ! Route::has('cotations.index')) {
            return null;
        }

        $cereals = $canViewCereals ? $this->dashboardCereals() : [];
        $fuelBlocks = $canViewFuel ? $this->dashboardFuelBlocks() : [];
        $mobileFuel = $canViewFuel ? [[
            'label' => 'Carburant',
            'href' => route('cotations.index', ['section' => 'fuel']),
            'kind' => 'fuel',
        ]] : [];

        return [
            'key' => 'cotations',
            'title' => 'Cotations',
            'type' => 'cotations',
            'icon' => 'cotations',
            'accent' => 'green',
            'cereals' => $cereals,
            'fuel_blocks' => $fuelBlocks,
            'mobile_cereals' => array_slice($cereals, 0, 3),
            'mobile_fuel' => $mobileFuel,
        ];
    }

    /**
     * @return array<int, array{label:string,href:string,kind:string,code:string}>
     */
    private function dashboardCereals(): array
    {
        $labels = $this->cerealLabelsConfig();

        return [
            ['label' => $this->dashboardCerealLabel($labels['EBM'] ?? 'Blé', 'Blé'), 'href' => route('cotations.index', ['cereal' => 'EBM']), 'kind' => 'cereal', 'code' => 'EBM'],
            ['label' => $this->dashboardCerealLabel($labels['ECO'] ?? 'Colza', 'Colza'), 'href' => route('cotations.index', ['cereal' => 'ECO']), 'kind' => 'cereal', 'code' => 'ECO'],
            ['label' => $this->dashboardCerealLabel($labels['EMA'] ?? 'Maïs', 'Maïs'), 'href' => route('cotations.index', ['cereal' => 'EMA']), 'kind' => 'cereal', 'code' => 'EMA'],
        ];
    }

    private function dashboardCerealLabel(string $label, string $fallback): string
    {
        $cleaned = CotationPdfFormatter::text($label);

        return $cleaned !== '' ? $cleaned : $fallback;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function quickAccessWidget(User $user): ?array
    {
        $access = app(AccessManager::class);
        $links = [];

        if (Route::has('calendar.index') && $access->can($user, 'calendar.view')) {
            $links[] = [
                'label' => 'Calendrier',
                'href' => route('calendar.index'),
                'icon' => 'calendar',
            ];
        }

        if (Route::has('leaves.index')) {
            $links[] = [
                'label' => 'Congés',
                'href' => route('leaves.index'),
                'icon' => 'leaves',
            ];
        }

        if (Route::has('hours.index') && $access->can($user, 'heures.view')) {
            $links[] = [
                'label' => 'Heures',
                'href' => route('hours.index'),
                'icon' => 'hours',
            ];
        }

        if (Route::has('ldt.index') && $access->can($user, 'ldt.view')) {
            $links[] = [
                'label' => 'Livre du travail',
                'href' => route('ldt.index'),
                'icon' => 'ldt-book',
            ];
        }

        if (Route::has('a_prevoir.index') && $access->can($user, 'a_prevoir.view')) {
            $links[] = [
                'label' => 'À prévoir',
                'href' => route('a_prevoir.index'),
                'icon' => 'ldt-planning',
            ];
        }

        if (Route::has('directory.index') && $user->can('viewAny', User::class)) {
            $links[] = [
                'label' => 'Annuaire',
                'href' => route('directory.index'),
                'icon' => 'annuaire',
            ];
        }

        if ($links === []) {
            return null;
        }

        return [
            'key' => 'quick-access',
            'title' => 'Accès rapides',
            'type' => 'quick_links',
            'icon' => 'shortcut',
            'accent' => 'green',
            'links' => $links,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function cerealLabelsConfig(): array
    {
        $defaults = [
            'ECO' => 'Colza',
            'EBM' => 'Blé',
            'EMA' => 'Maïs',
        ];

        if (! Schema::hasTable('cotation_settings')) {
            return $defaults;
        }

        $note = CotationSetting::query()->where('key', 'cereal_display_labels')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;
        if (! is_array($decoded)) {
            return $defaults;
        }

        foreach ($defaults as $code => $defaultLabel) {
            $label = trim((string) ($decoded[$code] ?? ''));
            $defaults[$code] = $label !== '' ? $label : $defaultLabel;
        }

        return $defaults;
    }

    /**
     * @return array<int, array{label:string,href:string,kind:string}>
     */
    private function dashboardFuelBlocks(): array
    {
        $config = $this->fuelGridConfig();
        $blocks = collect($config['sections'] ?? [])
            ->filter(fn ($section): bool => is_array($section))
            ->map(fn (array $section): array => [
                'label' => trim((string) ($section['label'] ?? '')) ?: 'Carburant',
                'href' => route('cotations.index', ['section' => 'fuel']),
                'kind' => 'fuel',
            ])
            ->values()
            ->all();

        $gazoleLabel = trim((string) ($config['gazole']['label'] ?? ''));
        if ($gazoleLabel !== '') {
            $blocks[] = [
                'label' => $gazoleLabel,
                'href' => route('cotations.index', ['section' => 'fuel']),
                'kind' => 'fuel',
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function fuelGridConfig(): array
    {
        $default = [
            'sections' => [
                ['id' => 'fuel_grand_froid', 'label' => 'FUEL GRAND FROID'],
                ['id' => 'gnr_agri', 'label' => 'GNR AGRI Enregistré'],
                ['id' => 'gnr_taxe', 'label' => 'GNR Taxé'],
            ],
            'gazole' => ['label' => 'GAZOLE'],
        ];

        if (! Schema::hasTable('cotation_settings')) {
            return $default;
        }

        $note = CotationSetting::query()->where('key', 'fuel_grid_config')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;
        if (! is_array($decoded)) {
            return $default;
        }

        return [
            'sections' => collect($decoded['sections'] ?? $default['sections'])
                ->filter(fn ($section): bool => is_array($section))
                ->map(fn (array $section, int $index): array => [
                    'label' => trim((string) ($section['label'] ?? '')) ?: ($default['sections'][$index]['label'] ?? 'Carburant'),
                ])
                ->values()
                ->all(),
            'gazole' => [
                'label' => trim((string) ($decoded['gazole']['label'] ?? '')) ?: 'GAZOLE',
            ],
        ];
    }

    private function safeRoute(string $name, array $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }

    /**
     * @return array<int, array{label:string,meta:string,status:string}>
     */
    private function upcomingTasksForUser(User $user): array
    {
        $today = Carbon::today();

        $entries = LdtEntry::query()
            ->where('assignee_type', 'user')
            ->where('assignee_id', $user->id)
            ->whereDate('date', '>=', $today->toDateString())
            ->orderBy('date')
            ->get(['id', 'date', 'tasks_text', 'comments_text']);

        $sourceIds = $entries
            ->flatMap(fn (LdtEntry $entry) => is_array($entry->source_task_ids) ? $entry->source_task_ids : [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $tasksById = collect();
        if ($sourceIds->isNotEmpty()) {
            $tasksById = AprevoirTask::query()
                ->whereIn('id', $sourceIds->all())
                ->get(['id', 'task', 'comment', 'position'])
                ->mapWithKeys(fn (AprevoirTask $task) => [
                    (int) $task->id => [
                        'task' => (string) $task->task,
                        'comment' => (string) $task->comment,
                        'position' => (int) $task->position,
                    ],
                ]);
        }

        $items = [];

        foreach ($entries as $entry) {
            $date = $entry->date ? Carbon::parse($entry->date) : null;
            $sourceTaskIds = is_array($entry->source_task_ids) ? $entry->source_task_ids : [];

            if (! empty($sourceTaskIds) && $tasksById->isNotEmpty()) {
                $sortedIds = array_values(array_filter(array_map('intval', $sourceTaskIds)));
                usort($sortedIds, function (int $a, int $b) use ($tasksById): int {
                    $posA = $tasksById->get($a)['position'] ?? PHP_INT_MAX;
                    $posB = $tasksById->get($b)['position'] ?? PHP_INT_MAX;
                    return $posA <=> $posB;
                });

                foreach ($sortedIds as $taskId) {
                    $taskData = $tasksById->get($taskId);
                    if (! is_array($taskData)) {
                        continue;
                    }

                    $label = $this->cleanTaskLabel((string) ($taskData['task'] ?? ''));
                    if ($label === '') {
                        continue;
                    }

                    $time = $this->extractTime($label);
                    $metaParts = [];
                    if ($date) {
                        $metaParts[] = $date->format('d/m');
                    }
                    if ($time) {
                        $metaParts[] = $time;
                    }

                    $items[] = [
                        'id' => $taskId,
                        'entry_id' => $entry->id,
                        'label' => $label,
                        'meta' => implode(' • ', $metaParts),
                        'status' => '',
                        'href' => $this->safeRoute('ldt.index', [
                            'focus_task_id' => $taskId,
                            'focus_task_id' => $entry->id,
                        ]),
                    ];

                    if (count($items) >= 4) {
                        break 2;
                    }
                }

                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', (string) $entry->tasks_text) ?: [];

            foreach ($lines as $line) {
                $task = $this->cleanTaskLabel((string) $line);
                if ($task === '') {
                    continue;
                }

                $time = $this->extractTime($task);
                $metaParts = [];
                if ($date) {
                    $metaParts[] = $date->format('d/m');
                }
                if ($time) {
                    $metaParts[] = $time;
                }

                $items[] = [
                    'label' => $task,
                    'meta' => implode(' • ', $metaParts),
                    'status' => '',
                    'href' => $this->safeRoute('ldt.index', [
                        'focus_task_id' => $entry->id,
                    ]),
                ];

                if (count($items) >= 4) {
                    break 2;
                }
            }
        }

        return $items;
    }

    private function cleanTaskLabel(string $text): string
    {
        $label = trim($text);
        $label = preg_replace('/^[+\\-•\\x{2022}]+\\s*/u', '', $label) ?? $label;

        return trim($label);
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/\\b(\\d{1,2})\\s*[:hH]\\s*(\\d{2})\\b/', $text, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
