<?php

namespace App\Services\Dashboard;

use App\Models\LdtEntry;
use App\Models\AprevoirTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

class DashboardDataService
{
    /**
     * @return array<string, mixed>
     */
    public function buildForUser(User $user): array
    {
        $isAdmin = $user->hasRole('admin');
        $sectorName = $user->sector?->name ?? 'Secteur non défini';

        $widgets = [
            $this->tasksWidget($user),
            $this->newsWidget($isAdmin, $sectorName),
        ];

        if ($isAdmin) {
            array_splice($widgets, 1, 0, [$this->pendingLeavesWidget()]);
        }

        return [
            'meta' => [
                'scope' => $isAdmin ? 'global' : 'sector',
                'scope_label' => $isAdmin ? 'Vue globale administrateur' : "Vue secteur : {$sectorName}",
                'generated_at' => now()->toIso8601String(),
            ],
            'widgets' => $widgets,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tasksWidget(User $user): array
    {
        $items = $this->upcomingTasksForUser($user);

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
     * @return array<string, mixed>
     */
    private function pendingLeavesWidget(): array
    {
        return [
            'key' => 'pending-leaves',
            'title' => 'Congés en attente',
            'type' => 'metrics',
            'icon' => 'calendar',
            'accent' => 'red',
            'clickable' => false,
            'href' => null,
            'subtitle' => 'Vue admin globale (placeholder)',
            'metrics' => [
                ['label' => 'Demandes', 'value' => '07'],
                ['label' => 'Urgentes', 'value' => '02'],
                ['label' => 'Cette semaine', 'value' => '12'],
            ],
            'footer' => 'Module congés à brancher',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentsWidget(bool $isAdmin, string $sectorName): array
    {
        return [
            'key' => 'recent-documents',
            'title' => 'Documents récents',
            'type' => 'list',
            'icon' => 'document',
            'accent' => 'brown',
            'clickable' => false,
            'href' => null,
            'subtitle' => $isAdmin ? 'Tous secteurs (placeholder)' : "{$sectorName} uniquement (placeholder)",
            'items' => [
                ['label' => 'Procédure qualité v3.pdf', 'meta' => 'Il y a 1 h', 'status' => 'Mis à jour'],
                ['label' => 'Compte rendu équipe.docx', 'meta' => 'Il y a 3 h', 'status' => 'Nouveau'],
                ['label' => 'Tableau suivi.xlsx', 'meta' => 'Hier', 'status' => 'Consulté'],
            ],
            'footer' => 'Module GED à brancher',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newsWidget(bool $isAdmin, string $sectorName): array
    {
        return [
            'key' => 'recent-news',
            'title' => 'Actualités récentes',
            'type' => 'list',
            'icon' => 'news',
            'accent' => 'green',
            'clickable' => false,
            'href' => null,
            'subtitle' => $isAdmin ? 'Flux global (placeholder)' : "Flux {$sectorName} (placeholder)",
            'items' => [
                ['label' => 'Maintenance prévue vendredi', 'meta' => 'IT', 'status' => 'Info'],
                ['label' => 'Nouvelle procédure de validation', 'meta' => 'RH', 'status' => 'Important'],
                ['label' => 'Mise à jour du planning mensuel', 'meta' => 'Ops', 'status' => 'Info'],
            ],
            'footer' => 'Module actualités à brancher',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shortcutsWidget(bool $isAdmin): array
    {
        $links = [
            ['label' => 'Mon profil', 'href' => $this->safeRoute('profile.edit')],
            ['label' => 'Dashboard', 'href' => $this->safeRoute('dashboard')],
            ['label' => 'Annuaire', 'href' => $this->safeRoute('directory.index')],
        ];

        if ($isAdmin) {
            $links[] = ['label' => 'Utilisateurs', 'href' => $this->safeRoute('admin.users.index')];
            $links[] = ['label' => 'Secteurs', 'href' => $this->safeRoute('admin.sectors.index')];
            $links[] = ['label' => 'Logs', 'href' => $this->safeRoute('admin.logs.index')];
        }

        return [
            'key' => 'quick-shortcuts',
            'title' => 'Raccourcis rapides',
            'type' => 'links',
            'icon' => 'shortcut',
            'accent' => 'yellow',
            'clickable' => false,
            'href' => null,
            'subtitle' => 'Accès rapides aux modules',
            'links' => array_values(array_filter($links, fn (array $link): bool => ! empty($link['href']))),
            'footer' => 'Menu contextuel du dashboard',
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
