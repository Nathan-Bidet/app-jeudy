<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\AuditLogService;
use App\Support\RichText\SimpleHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class NotificationController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();

        $rawNotifications = $user->notifications()
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $announcementIds = $rawNotifications
            ->filter(fn ($n) => ($n->data['type'] ?? $n->type) === 'announcement' && !isset($n->data['title']))
            ->map(fn ($n) => $n->data['announcement_id'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $announcements = count($announcementIds) > 0
            ? Announcement::whereIn('id', $announcementIds)->get()->keyBy('id')
            : collect();

        $notifications = $rawNotifications
            ->map(fn ($notification) => $this->mapNotification($notification, $announcements))
            ->values()
            ->all();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => (int) $user->unreadNotifications()->count(),
        ]);
    }

    public function readAndRedirect(Request $request, string $notificationId): RedirectResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($notificationId)
            ->first();

        if ($notification && $notification->read_at === null) {
            $notification->markAsRead();
        }

        $type = $notification?->data['type'] ?? null;
        $destination = match ($type) {
            'hours_missing_entry_reminder' => route('hours.index'),
            default => route('hours.index'),
        };

        return redirect($destination);
    }

    public function markAsRead(Request $request, string $notificationId): RedirectResponse|JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($notificationId)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    public function markAllAsRead(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    public function destroy(Request $request, string $notificationId): RedirectResponse|JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($notificationId)
            ->firstOrFail();

        abort_if($notification->read_at === null, 409);

        $notificationTitle = $notification->data['title'] ?? null;
        $notificationMessage = (string) ($notification->data['message'] ?? 'Notification');

        $this->auditLogService->log([
            'action' => 'Suppression notification',
            'module' => 'Notifications',
            'description' => 'Suppression notification',
            'payload' => [
                'notification_id' => (string) $notification->id,
                'title' => $notificationTitle !== null ? (string) $notificationTitle : null,
                'message' => $notificationMessage,
                'state' => $notification->read_at === null ? 'Non lue' : 'Lue',
                'read_at' => $notification->read_at?->toIso8601String(),
                'deleted_at' => now()->toIso8601String(),
            ],
        ]);

        $notification->delete();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    private function mapNotification(object $notification, \Illuminate\Support\Collection $announcements = null): array
    {
        $type = (string) ($notification->data['type'] ?? $notification->type);
        $leaveRequestId = $notification->data['leave_request_id'] ?? null;
        $announcementId = $notification->data['announcement_id'] ?? null;

        $title = isset($notification->data['title']) ? (string) $notification->data['title'] : null;
        $fullMessage = isset($notification->data['full_message']) ? (string) $notification->data['full_message'] : null;

        if ($type === 'announcement' && $announcementId && $announcements) {
            $announcement = $announcements->get($announcementId);
            if ($announcement) {
                if ($title === null) {
                    $title = $announcement->title ?? null;
                }
                if ($fullMessage === null && $announcement->body_html) {
                    $fullMessage = SimpleHtmlSanitizer::toPlainText($announcement->body_html) ?: null;
                }
            }
        }

        return [
            'id' => (string) $notification->id,
            'type' => $type,
            'title' => $title,
            'message' => (string) ($notification->data['message'] ?? 'Notification'),
            'full_message' => $fullMessage,
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
