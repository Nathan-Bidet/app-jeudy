<?php

namespace App\Http\Middleware;

use App\Models\Announcement;
use App\Models\HourSheet;
use App\Services\Announcements\AnnouncementPollPresenter;
use App\Services\Hours\ApprovedLeaveDayService;
use App\Support\Access\AccessManager;
use App\Support\RichText\SimpleHtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly AnnouncementPollPresenter $pollPresenter,
    ) {
    }

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $accessManager = app(AccessManager::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => (string) $user->email,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'sector_id' => $user->sector_id ? (int) $user->sector_id : null,
                    'photo_url' => $this->photoUrl($user->photo_path),
                ] : null,
                'is_admin' => (bool) $user?->hasRole('admin'),
                'permissions' => [
                    'a_prevoir_view' => (bool) ($user && $accessManager->can($user, 'a_prevoir.view')),
                    'engrais_view' => (bool) ($user && $accessManager->can($user, 'engrais.view')),
                    'ldt_view' => (bool) ($user && $accessManager->can($user, 'ldt.view')),
                    'ldt_sms' => (bool) ($user && $accessManager->can($user, 'ldt.sms')),
                    'task_data_view' => (bool) ($user && $accessManager->can($user, 'task.data.view')),
                    'task_data_jeudy_view' => (bool) ($user && ($accessManager->can($user, 'task.data.jeudy.view') || $accessManager->can($user, 'task.data.jeudy.manage'))),
                    'task_data_jeudy_manage' => (bool) ($user && $accessManager->can($user, 'task.data.jeudy.manage')),
                    'task_data_transporters_view' => (bool) ($user && ($accessManager->can($user, 'task.data.transporters.view') || $accessManager->can($user, 'task.data.transporters.manage'))),
                    'task_data_transporters_manage' => (bool) ($user && $accessManager->can($user, 'task.data.transporters.manage')),
                    'task_data_depots_view' => (bool) ($user && ($accessManager->can($user, 'task.data.depots.view') || $accessManager->can($user, 'task.data.depots.manage'))),
                    'task_data_depots_manage' => (bool) ($user && $accessManager->can($user, 'task.data.depots.manage')),
                    'task_archive_view' => (bool) ($user && ($accessManager->can($user, 'task.archive.view') || $accessManager->can($user, 'task.archive.manage'))),
                    'task_archive_manage' => (bool) ($user && $accessManager->can($user, 'task.archive.manage')),
                    'task_fuel_view' => (bool) ($user && $accessManager->can($user, 'task.fuel.view')),
                    'task_fuel_update' => (bool) ($user && $accessManager->can($user, 'task.fuel.update')),
                    'task_fuel_delete' => (bool) ($user && $accessManager->can($user, 'task.fuel.delete')),
                    'task_tiers_view' => (bool) ($user && $accessManager->can($user, 'task.tiers.view')),
                    'task_tiers_import' => (bool) ($user && $accessManager->can($user, 'task.tiers.import')),
                    'task_tiers_update' => (bool) ($user && $accessManager->can($user, 'task.tiers.update')),
                    'task_tiers_delete' => (bool) ($user && $accessManager->can($user, 'task.tiers.delete')),
                    'task_formatting_view' => (bool) ($user && ($accessManager->can($user, 'task.formatting.view') || $accessManager->can($user, 'task.formatting.manage'))),
                    'task_formatting_manage' => (bool) ($user && $accessManager->can($user, 'task.formatting.manage')),
                    'calendar_view' => (bool) ($user && $accessManager->can($user, 'calendar.view')),
                    'calendar_event_manage' => (bool) ($user && $accessManager->can($user, 'calendar.event.manage')),
                    'calendar_category_manage' => (bool) ($user && $accessManager->can($user, 'calendar.category.manage')),
                    'calendar_feed_manage' => (bool) ($user && $accessManager->can($user, 'calendar.feed.manage')),
                    'cotations_view' => (bool) ($user && (
                        $accessManager->can($user, 'cotations.cereals.view')
                        || $accessManager->can($user, 'cotations.cereals.edit')
                        || $accessManager->can($user, 'cotations.fuel.view')
                        || $accessManager->can($user, 'cotations.fuel.edit')
                    )),
                    'cotations_cereals_view' => (bool) ($user && (
                        $accessManager->can($user, 'cotations.cereals.view')
                        || $accessManager->can($user, 'cotations.cereals.edit')
                    )),
                    'cotations_cereals_edit' => (bool) ($user && $accessManager->can($user, 'cotations.cereals.edit')),
                    'cotations_fuel_view' => (bool) ($user && (
                        $accessManager->can($user, 'cotations.fuel.view')
                        || $accessManager->can($user, 'cotations.fuel.edit')
                    )),
                    'cotations_fuel_edit' => (bool) ($user && $accessManager->can($user, 'cotations.fuel.edit')),
                    'cotations_admin' => (bool) ($user && $accessManager->can($user, 'cotations.admin')),
                    'hours_view' => (bool) ($user && $accessManager->can($user, 'heures.view')),
                    'hours_create' => (bool) ($user && $accessManager->can($user, 'heures.create')),
                    'admin_users_view' => (bool) ($user && ($accessManager->can($user, 'admin.users.view') || $accessManager->can($user, 'admin.users.manage'))),
                    'admin_sectors_view' => (bool) ($user && ($accessManager->can($user, 'admin.sectors.view') || $accessManager->can($user, 'admin.sectors.manage'))),
                    'admin_entities_view' => (bool) ($user && ($accessManager->can($user, 'admin.entities.view') || $accessManager->can($user, 'admin.entities.manage'))),
                    'admin_logs_view' => (bool) ($user && $accessManager->can($user, 'admin.logs.view')),
                    'annonces_view' => (bool) ($user && (
                        $accessManager->can($user, 'annonces.view')
                        || $accessManager->can($user, 'annonces.create')
                        || $accessManager->can($user, 'annonces.manage')
                    )),
                    'annonces_create' => (bool) ($user && $accessManager->can($user, 'annonces.create')),
                    'annonces_manage' => (bool) ($user && $accessManager->can($user, 'annonces.manage')),
                ],
            ],
            'hours_reminder' => function () use ($user, $accessManager): array {
                if (! $user || ! $accessManager->can($user, 'heures.create')) {
                    return [
                        'show' => false,
                    ];
                }

                $today = now(config('app.timezone', 'Europe/Paris'))->toDateString();
                $yesterday = now(config('app.timezone', 'Europe/Paris'))->subDay()->toDateString();
                $trackingStartDate = $user->hours_tracking_starts_at?->toDateString()
                    ?? (string) config('hours.min_visible_date', '2026-04-27');
                if ($yesterday < $trackingStartDate) {
                    return [
                        'show' => false,
                    ];
                }

                $approvedLeaveDayService = app(ApprovedLeaveDayService::class);
                if ($approvedLeaveDayService->isUserOnApprovedLeaveForDate((int) $user->id, $yesterday)) {
                    return [
                        'show' => false,
                    ];
                }

                $hasYesterdayEntry = HourSheet::query()
                    ->where('user_id', (int) $user->id)
                    ->whereDate('work_date', $yesterday)
                    ->exists();

                return [
                    'show' => ! $hasYesterdayEntry,
                    'message' => 'Vous n’avez pas saisi vos heures pour la journée d’hier.',
                    'yesterday_date' => $yesterday,
                    'dismiss_key' => $today,
                ];
            },
            'vapid_public_key' => config('webpush.vapid_public_key') ?: null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'notifications' => fn () => $user ? (function () use ($user, $accessManager) {
                $rawNotifications = $user->notifications()
                    ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();

                $announcementIds = $rawNotifications
                    ->filter(fn ($n) => ($n->data['type'] ?? $n->type) === 'announcement')
                    ->map(fn ($n) => $n->data['announcement_id'] ?? null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $announcements = count($announcementIds) > 0
                    ? Announcement::whereIn('id', $announcementIds)
                        ->with(['creator:id,name,first_name,last_name', 'poll.options', 'poll.responses.user:id,name,first_name,last_name'])
                        ->get()
                        ->keyBy('id')
                    : collect();

                $canManage = (bool) $accessManager->can($user, 'annonces.manage');

                return [
                    'items' => $rawNotifications
                        ->map(fn ($notification) => $this->mapNotification($notification, $announcements, $user, $canManage))
                        ->values()
                        ->all(),
                    'unread_count' => (int) $user->unreadNotifications()->count(),
                ];
            })() : [
                'items' => [],
                'unread_count' => 0,
            ],
        ];
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

    private function mapNotification(object $notification, \Illuminate\Support\Collection $announcements = null, $viewer = null, bool $canManage = false): array
    {
        $type = (string) ($notification->data['type'] ?? $notification->type);
        $leaveRequestId = $notification->data['leave_request_id'] ?? null;
        $announcementId = $notification->data['announcement_id'] ?? null;

        $title = isset($notification->data['title']) ? (string) $notification->data['title'] : null;
        $fullMessage = isset($notification->data['full_message']) ? (string) $notification->data['full_message'] : null;
        $announcementAuthor = null;
        $poll = null;

        if ($type === 'announcement' && $announcementId && $announcements) {
            $announcement = $announcements->get($announcementId);
            if ($announcement) {
                if ($title === null) {
                    $title = $announcement->title ?? null;
                }
                if ($fullMessage === null && $announcement->body_html) {
                    $fullMessage = SimpleHtmlSanitizer::toPlainText($announcement->body_html) ?: null;
                }
                $announcementAuthor = $announcement->creator ? $this->userLabel($announcement->creator) : null;
                $poll = $this->pollPresenter->present($announcement, $viewer, $canManage);
            }
        }

        return [
            'id' => (string) $notification->id,
            'type' => $type,
            'title' => $title,
            'message' => (string) ($notification->data['message'] ?? 'Notification'),
            'full_message' => $fullMessage,
            'announcement_author' => $announcementAuthor,
            'poll' => $poll,
            'period' => [
                'start_at' => $notification->data['period']['start_at'] ?? null,
                'end_at' => $notification->data['period']['end_at'] ?? null,
            ],
            'requester_label' => $notification->data['requester_label'] ?? null,
            'leave_request_id' => $leaveRequestId,
            'announcement_id' => $announcementId,
            'url' => $this->notificationUrl($type, $leaveRequestId, $announcementId),
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
        ];
    }

    private function userLabel(object $user): string
    {
        $fullName = trim((string) ($user->first_name ?? '').' '.(string) ($user->last_name ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : (string) ($user->email ?? '');
    }

    private function notificationUrl(string $type, mixed $leaveRequestId, mixed $announcementId = null): ?string
    {
        $leaveTypes = [
            'leave_request_submitted',
            'leave_request_approved',
            'leave_request_refused',
            'leave_request_counter_proposal',
            'leave_request_user_confirmation',
            'leave_request_modification_proposed',
            'leave_request_modification_accepted',
            'leave_request_modification_refused',
        ];

        if (in_array($type, $leaveTypes, true) && Route::has('leaves.index')) {
            return $leaveRequestId
                ? route('leaves.index', ['highlight' => $leaveRequestId])
                : route('leaves.index');
        }

        if ($type === 'hours_missing_entry_reminder' && Route::has('hours.index')) {
            return route('hours.index');
        }

        if ($type === 'announcement' && Route::has('annonces.index')) {
            return $announcementId
                ? route('annonces.index', ['highlight' => $announcementId])
                : route('annonces.index');
        }

        return null;
    }
}
