<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\CalendarFeed;
use App\Models\CalendarCategory;
use App\Models\Depot;
use App\Models\LeaveHrUser;
use App\Models\LeaveAllowedCreatorPair;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Calendar\CalendarFeedImportService;
use App\Support\Access\AccessManager;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarFeedImportService $calendarFeedImportService
    ) {
    }

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'view' => ['nullable', Rule::in(['month', 'week'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $view = $validated['view'] ?? 'month';
        $date = $validated['date'] ?? now()->toDateString();
        $anchorDate = CarbonImmutable::createFromFormat('Y-m-d', $date) ?: CarbonImmutable::today();
        $range = $this->rangeForYear($anchorDate);

        $events = CalendarEvent::query()
            ->with([
                'category:id,name,color,is_active',
                'depot:id,name,is_active,address_line1,address_line2,postal_code,city,country,gps_lat,gps_lng',
            ])
            ->where(function ($query) use ($range): void {
                $query
                    ->whereBetween('start_at', [$range['start'], $range['end']])
                    ->orWhere(function ($subQuery) use ($range): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->whereBetween('end_at', [$range['start'], $range['end']]);
                    })
                    ->orWhere(function ($subQuery) use ($range): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->where('start_at', '<=', $range['start'])
                            ->where('end_at', '>=', $range['end']);
                    });
            })
            ->orderBy('start_at')
            ->orderBy('id')
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'id' => (int) $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'start_at' => $event->start_at?->toIso8601String(),
                'start_at_local' => $event->start_at?->format('Y-m-d\TH:i'),
                'start_at_label' => $event->start_at?->format('d/m/Y H:i'),
                'end_at' => $event->end_at?->toIso8601String(),
                'end_at_local' => $event->end_at?->format('Y-m-d\TH:i'),
                'end_at_label' => $event->end_at?->format('d/m/Y H:i'),
                'all_day' => (bool) $event->all_day,
                'category_id' => $event->category_id,
                'depot_id' => $event->depot_id,
                'category' => $event->category ? [
                    'id' => (int) $event->category->id,
                    'name' => $event->category->name,
                    'color' => $event->category->color,
                    'is_active' => (bool) $event->category->is_active,
                ] : null,
                'depot' => $event->depot ? [
                    'id' => (int) $event->depot->id,
                    'name' => $event->depot->name,
                    'is_active' => (bool) $event->depot->is_active,
                    'gps_lat' => $event->depot->gps_lat,
                    'gps_lng' => $event->depot->gps_lng,
                    'address_full' => collect([
                        $event->depot->address_line1,
                        $event->depot->address_line2,
                        trim(implode(' ', array_filter([
                            $event->depot->postal_code,
                            $event->depot->city,
                        ]))),
                        $event->depot->country,
                    ])->filter(fn ($value) => filled($value))->implode(', '),
                ] : null,
            ])
            ->values()
            ->all();

        $feeds = CalendarFeed::query()
            ->orderBy('name')
            ->get(['id', 'name', 'url', 'color', 'is_active', 'last_synced_at'])
            ->values();

        $externalEvents = $this->calendarFeedImportService->eventsForRange(
            $feeds->all(),
            CarbonImmutable::parse($range['start']),
            CarbonImmutable::parse($range['end']),
        );

        $leaveEvents = collect();
        if ($request->user() && Schema::hasTable('leave_requests')) {
            $userId = (int) $request->user()->id;
            $isAdmin = (bool) $request->user()?->hasRole('admin');
            $hrUserIds = LeaveHrUser::query()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $hasConfiguredHr = $hrUserIds !== [];

            $leaveEvents = LeaveRequest::query()
                ->with([
                    'target:id,name,first_name,last_name,email',
                    'requester:id,name,first_name,last_name,email',
                ])
                ->where(function ($query) use ($range): void {
                    $query
                        ->whereBetween('start_at', [$range['start'], $range['end']])
                        ->orWhere(function ($subQuery) use ($range): void {
                            $subQuery
                                ->whereNotNull('end_at')
                                ->whereBetween('end_at', [$range['start'], $range['end']]);
                        })
                        ->orWhere(function ($subQuery) use ($range): void {
                            $subQuery
                                ->whereNotNull('end_at')
                                ->where('start_at', '<=', $range['start'])
                                ->where('end_at', '>=', $range['end']);
                        });
                })
                ->orderBy('start_at')
                ->orderBy('id')
                ->get()
                ->filter(function (LeaveRequest $leave) use ($userId): bool {
                    return match ($leave->status) {
                        LeaveRequest::STATUS_PENDING => (int) $leave->requester_user_id === $userId
                            || (int) $leave->validator_user_id === $userId,
                        LeaveRequest::STATUS_APPROVED => true,
                        LeaveRequest::STATUS_REFUSED => false,
                        default => false,
                    };
                })
                ->map(function (LeaveRequest $leave): array {
                    $target = $leave->target;
                    $requester = $leave->requester;
                    $targetLabel = trim(
                        collect([$target?->first_name, $target?->last_name])
                            ->filter()
                            ->implode(' ')
                    ) ?: ($target?->name ?: $target?->email);
                    $requesterLabel = trim(
                        collect([$requester?->first_name, $requester?->last_name])
                            ->filter()
                            ->implode(' ')
                    ) ?: ($requester?->name ?: $requester?->email);
                    $leaveLabel = $targetLabel ?: $requesterLabel;

                    $colors = match ($leave->status) {
                        LeaveRequest::STATUS_APPROVED => [
                            'backgroundColor' => '#22c55e',
                            'borderColor' => '#22c55e',
                            'textColor' => '#000000',
                        ],
                        LeaveRequest::STATUS_REFUSED => [
                            'backgroundColor' => '#ef4444',
                            'borderColor' => '#ef4444',
                            'textColor' => '#000000',
                        ],
                        default => [
                            'backgroundColor' => '#9ca3af',
                            'borderColor' => '#9ca3af',
                            'textColor' => '#000000',
                        ],
                    };

                    return [
                        'id' => 'leave-'.$leave->id,
                        'title' => $leaveLabel ? 'Congés '.$leaveLabel : 'Congés',
                        'start' => $leave->start_at?->toIso8601String(),
                        'end' => $leave->end_at?->toIso8601String(),
                        'allDay' => (bool) $leave->is_all_day,
                        ...$colors,
                        // Keep compatibility with existing calendar event renderer.
                        'start_at' => $leave->start_at?->toIso8601String(),
                        'start_at_local' => $leave->start_at?->format('Y-m-d\TH:i'),
                        'start_at_label' => $leave->start_at?->format('d/m/Y H:i'),
                        'end_at' => $leave->end_at?->toIso8601String(),
                        'end_at_local' => $leave->end_at?->format('Y-m-d\TH:i'),
                        'end_at_label' => $leave->end_at?->format('d/m/Y H:i'),
                        'all_day' => (bool) $leave->is_all_day,
                    ];
                });
        }

        $events = collect($events)
            ->merge($externalEvents)
            ->merge($leaveEvents)
            ->sortBy('start_at')
            ->values()
            ->all();

        $categories = CalendarCategory::query()
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_active'])
            ->map(fn (CalendarCategory $category): array => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'color' => $category->color,
                'is_active' => (bool) $category->is_active,
            ])
            ->values()
            ->all();

        $feedPayload = $feeds->map(fn (CalendarFeed $feed): array => [
            'id' => (int) $feed->id,
            'name' => $feed->name,
            'url' => $feed->url,
            'color' => $feed->color,
            'is_active' => (bool) $feed->is_active,
            'last_synced_at' => $feed->last_synced_at?->toIso8601String(),
            'last_synced_at_label' => $feed->last_synced_at?->setTimezone(config('app.timezone', 'Europe/Paris'))->format('d/m/Y H:i'),
        ])->all();

        $depots = Depot::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Depot $depot): array => [
                'id' => (int) $depot->id,
                'name' => $depot->name,
                'is_active' => (bool) $depot->is_active,
            ])
            ->values()
            ->all();

        $accessManager = app(AccessManager::class);
        $canManageEvents = $request->user()
            ? $accessManager->can($request->user(), 'calendar.event.manage')
            : false;
        $canManageCategories = $request->user()
            ? $accessManager->can($request->user(), 'calendar.category.manage')
            : false;
        $canManageFeeds = $request->user()
            ? $accessManager->can($request->user(), 'calendar.feed.manage')
            : false;
        $canExportLeavesCsv = $request->user()
            && Schema::hasTable('leave_hr_users')
            ? LeaveHrUser::query()->where('user_id', (int) $request->user()->id)->exists()
            : false;
        $allowedTargetIds = $request->user()
            ? $this->resolveAllowedTargetIds($request->user())
            : [];
        $canRequestForOthers = collect($allowedTargetIds)
            ->contains(fn ($id) => (int) $id !== (int) $request->user()?->id);
        $leaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->visibleForUser((int) $request->user()->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'max_days'])
            ->map(fn (LeaveType $leaveType): array => [
                'id' => (int) $leaveType->id,
                'label' => $leaveType->name,
                'max_days' => (int) $leaveType->max_days,
            ])
            ->values()
            ->all();
        $leaveRequestUsers = $canRequestForOthers
            ? User::query()
                ->whereIn('id', $allowedTargetIds)
                ->orderByRaw('COALESCE(last_name, name) asc')
                ->orderByRaw('COALESCE(first_name, name) asc')
                ->get(['id', 'name', 'first_name', 'last_name', 'email'])
                ->map(function (User $candidate) {
                    $fullName = trim(
                        collect([$candidate->first_name, $candidate->last_name])
                            ->filter()
                            ->implode(' ')
                    );

                    return [
                        'id' => $candidate->id,
                        'label' => $fullName !== '' ? $fullName : ($candidate->name ?: $candidate->email),
                    ];
                })
                ->values()
                ->all()
            : [[
                'id' => $request->user()?->id,
                'label' => trim(
                    collect([$request->user()?->first_name, $request->user()?->last_name])
                        ->filter()
                        ->implode(' ')
                ) ?: ($request->user()?->name ?: $request->user()?->email),
            ]];

        return Inertia::render('Calendar/Index', [
            'calendar' => [
                'view' => $view,
                'date' => $date,
            ],
            'events' => $events,
            'categories' => $categories,
            'feeds' => $feedPayload,
            'depots' => $depots,
            'permissions' => [
                'can_manage_events' => $canManageEvents,
                'can_manage_categories' => $canManageCategories,
                'can_manage_feeds' => $canManageFeeds,
                'can_export_leaves_csv' => $canExportLeavesCsv,
            ],
            'users' => $leaveRequestUsers,
            'leaveTypes' => $leaveTypes,
            'defaultTargetUserId' => $request->user()?->id,
            'canRequestForOthers' => $canRequestForOthers,
            'leaveRequestForm' => [
                'users' => $leaveRequestUsers,
                'leaveTypes' => $leaveTypes,
                'defaultTargetUserId' => $request->user()?->id,
                'canRequestForOthers' => $canRequestForOthers,
            ],
        ]);
    }

    /**
     * @return int[]
     */
    private function resolveAllowedTargetIds(User $creator): array
    {
        return LeaveAllowedCreatorPair::query()
            ->where('creator_user_id', (int) $creator->id)
            ->pluck('target_user_id')
            ->map(fn ($targetUserId) => (int) $targetUserId)
            ->push((int) $creator->id)
            ->unique()
            ->values()
            ->all();
    }

    public function exportLeavesCsv(Request $request): StreamedResponse
    {
        abort_unless($request->user(), 403);
        abort_unless(Schema::hasTable('leave_hr_users'), 403);
        abort_unless(
            LeaveHrUser::query()->where('user_id', (int) $request->user()->id)->exists(),
            403
        );

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $anchorDate = CarbonImmutable::createFromFormat('Y-m-d', $validated['date']) ?: CarbonImmutable::today();
        $monthStart = $anchorDate->startOfMonth()->startOfDay();
        $monthEnd = $anchorDate->endOfMonth()->endOfDay();

        $rows = LeaveRequest::query()
            ->with(['target:id,first_name,last_name,name'])
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
                LeaveRequest::STATUS_REFUSED,
                LeaveRequest::STATUS_PENDING_USER_CONFIRMATION,
            ])
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query
                    ->whereBetween('start_at', [$monthStart->toDateTimeString(), $monthEnd->toDateTimeString()])
                    ->orWhere(function ($subQuery) use ($monthStart, $monthEnd): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->whereBetween('end_at', [$monthStart->toDateTimeString(), $monthEnd->toDateTimeString()]);
                    })
                    ->orWhere(function ($subQuery) use ($monthStart, $monthEnd): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->where('start_at', '<=', $monthStart->toDateTimeString())
                            ->where('end_at', '>=', $monthEnd->toDateTimeString());
                    });
            })
            ->orderBy('start_at')
            ->orderBy('id')
            ->get()
            ->map(function (LeaveRequest $leave): array {
                $target = $leave->target;

                return [
                    (string) ($target?->last_name ?: ''),
                    (string) ($target?->first_name ?: ($target?->name ?: '')),
                    $leave->start_at?->format('d-m-Y') ?: '',
                    $this->portionLabelFr($leave->start_portion),
                    $leave->end_at?->format('d-m-Y') ?: '',
                    $this->portionLabelFr($leave->end_portion),
                    $this->statusLabelFr($leave->status),
                    (string) ($leave->message ?: ''),
                ];
            })
            ->values()
            ->all();

        $fileName = sprintf('conges_%s.csv', $anchorDate->format('Y-m'));

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Nom',
                'Prénom',
                'Date du début',
                'Période du début',
                'Date de fin',
                'Période de fin',
                'Status',
                'Commentaire',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function portionLabelFr(?string $portion): string
    {
        return match ((string) $portion) {
            'morning' => 'Matin',
            'afternoon' => 'Après-midi',
            'full_day' => 'Journée complète',
            default => 'Journée complète',
        };
    }

    private function statusLabelFr(?string $status): string
    {
        return match ((string) $status) {
            LeaveRequest::STATUS_APPROVED => 'Approuvé',
            LeaveRequest::STATUS_REFUSED => 'Refusé',
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_PENDING_USER_CONFIRMATION => 'En attente',
            default => 'En attente',
        };
    }

    /**
     * @return array{start: string, end: string}
     */
    private function rangeForYear(CarbonImmutable $anchorDate): array
    {
        // Preload one full visible year to avoid navigation latency and
        // guarantee external/public events are already available when paging.
        $yearStart = $anchorDate->startOfYear();
        $start = $yearStart->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $yearEnd = $anchorDate->endOfYear();
        $end = $yearEnd->endOfWeek(CarbonInterface::SUNDAY)->endOfDay();

        return [
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
        ];
    }
}
