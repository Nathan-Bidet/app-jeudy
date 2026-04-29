<?php

namespace App\Http\Middleware;

use App\Models\HourSheet;
use App\Support\Access\AccessManager;
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
                    ...$user->toArray(),
                    'photo_url' => $this->photoUrl($user->photo_path),
                ] : null,
                'is_admin' => (bool) $user?->hasRole('admin'),
                'permissions' => [
                    'a_prevoir_view' => (bool) ($user && $accessManager->can($user, 'a_prevoir.view')),
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
                    'task_formatting_view' => (bool) ($user && ($accessManager->can($user, 'task.formatting.view') || $accessManager->can($user, 'task.formatting.manage'))),
                    'task_formatting_manage' => (bool) ($user && $accessManager->can($user, 'task.formatting.manage')),
                    'calendar_view' => (bool) ($user && $accessManager->can($user, 'calendar.view')),
                    'calendar_event_manage' => (bool) ($user && $accessManager->can($user, 'calendar.event.manage')),
                    'calendar_category_manage' => (bool) ($user && $accessManager->can($user, 'calendar.category.manage')),
                    'calendar_feed_manage' => (bool) ($user && $accessManager->can($user, 'calendar.feed.manage')),
                    'hours_view' => (bool) ($user && $accessManager->can($user, 'heures.view')),
                    'hours_create' => (bool) ($user && $accessManager->can($user, 'heures.create')),
                    'admin_users_view' => (bool) ($user && ($accessManager->can($user, 'admin.users.view') || $accessManager->can($user, 'admin.users.manage'))),
                    'admin_sectors_view' => (bool) ($user && ($accessManager->can($user, 'admin.sectors.view') || $accessManager->can($user, 'admin.sectors.manage'))),
                    'admin_entities_view' => (bool) ($user && ($accessManager->can($user, 'admin.entities.view') || $accessManager->can($user, 'admin.entities.manage'))),
                    'admin_logs_view' => (bool) ($user && $accessManager->can($user, 'admin.logs.view')),
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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'notifications' => fn () => $user ? [
                'items' => $user->notifications()
                    ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get()
                    ->map(fn ($notification) => $this->mapNotification($notification))
                    ->values()
                    ->all(),
                'unread_count' => (int) $user->unreadNotifications()->count(),
            ] : [
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

    private function mapNotification(object $notification): array
    {
        $type = (string) ($notification->data['type'] ?? $notification->type);
        $leaveRequestId = $notification->data['leave_request_id'] ?? null;

        return [
            'id' => (string) $notification->id,
            'type' => $type,
            'message' => (string) ($notification->data['message'] ?? 'Notification'),
            'period' => [
                'start_at' => $notification->data['period']['start_at'] ?? null,
                'end_at' => $notification->data['period']['end_at'] ?? null,
            ],
            'requester_label' => $notification->data['requester_label'] ?? null,
            'leave_request_id' => $leaveRequestId,
            'url' => $this->notificationUrl($type, $leaveRequestId),
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
        ];
    }

    private function notificationUrl(string $type, mixed $leaveRequestId): ?string
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

        return null;
    }
}
