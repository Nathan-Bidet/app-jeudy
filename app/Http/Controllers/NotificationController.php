<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class NotificationController extends Controller
{
    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($notification) => $this->mapNotification($notification))
            ->values()
            ->all();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => (int) $user->unreadNotifications()->count(),
        ]);
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
