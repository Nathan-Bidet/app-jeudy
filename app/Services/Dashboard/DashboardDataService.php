<?php

namespace App\Services\Dashboard;

use App\Models\Announcement;
use App\Models\AnnouncementView;
use App\Models\AprevoirTask;
use App\Models\LdtEntry;
use App\Models\User;
use App\Services\Announcements\AnnouncementPollPresenter;
use App\Support\Access\AccessManager;
use App\Support\RichText\SimpleHtmlSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

class DashboardDataService
{
    public function __construct(
        private readonly AnnouncementPollPresenter $pollPresenter,
    ) {}

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
                $this->quickAccessWidget($user),
            ])),
            'dashboard_announcement' => $this->activeDashboardAnnouncement($user),
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
    private function quickAccessWidget(User $user): ?array
    {
        $access = app(AccessManager::class);
        $links = [];

        if (Route::has('cotations.index') && (
            $access->can($user, 'cotations.cereals.view')
            || $access->can($user, 'cotations.cereals.edit')
            || $access->can($user, 'cotations.fuel.view')
            || $access->can($user, 'cotations.fuel.edit')
        )) {
            $links[] = [
                'label' => 'Cotations',
                'href' => route('cotations.index'),
                'icon' => 'cotations',
                'full_width' => true,
            ];
        }

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
     * @return array<string, mixed>|null
     */
    private function activeDashboardAnnouncement(User $user): ?array
    {
        $userId = (int) $user->id;
        $sectorId = $user->sector_id ? (int) $user->sector_id : null;

        $announcement = Announcement::query()
            ->where('show_on_dashboard', true)
            ->where(fn ($q) => $q
                ->whereNull('dashboard_expires_at')
                ->orWhereDate('dashboard_expires_at', '>=', now()->toDateString())
            )
            ->with([
                'creator:id,name,first_name,last_name',
                'poll.options',
                'poll.responses.user:id,name,first_name,last_name',
            ])
            ->orderByDesc('sent_at')
            ->get()
            ->first(function (Announcement $a) use ($userId, $sectorId): bool {
                $sectorIds = array_map('intval', (array) ($a->sector_ids ?? []));
                $userIds = array_map('intval', (array) ($a->user_ids ?? []));
                $excludedIds = array_map('intval', (array) ($a->excluded_user_ids ?? []));

                if (in_array($userId, $excludedIds, true)) {
                    return false;
                }

                if ($sectorId !== null && in_array($sectorId, $sectorIds, true)) {
                    return true;
                }

                return in_array($userId, $userIds, true);
            });

        if (! $announcement) {
            return null;
        }

        $creatorName = null;
        if ($announcement->creator) {
            $first = trim((string) $announcement->creator->first_name.' '.(string) $announcement->creator->last_name);
            $creatorName = $first !== '' ? $first : (trim((string) $announcement->creator->name) ?: null);
        }

        $canManage = (bool) app(AccessManager::class)->can($user, 'annonces.manage');

        $hasBeenViewed = AnnouncementView::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $userId)
            ->exists();

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body_html' => SimpleHtmlSanitizer::render($announcement->body_html),
            'created_by' => $creatorName,
            'poll' => $this->pollPresenter->present($announcement, $user, $canManage),
            'has_been_viewed' => $hasBeenViewed,
            // Utilisé côté frontend pour ne proposer "Répondre au nom de..."
            // que si le sondage est réellement ouvert (même règle que la
            // page Annonces) — le serveur applique de toute façon cette
            // même contrainte dans respondPollFor(), ceci n'est qu'un
            // affinement d'affichage.
            'status' => $announcement->status,
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
