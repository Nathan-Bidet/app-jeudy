<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\Announcements\AnnouncementPollPresenter;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use App\Support\RichText\SimpleHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class NotificationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AnnouncementPollPresenter $pollPresenter,
    ) {}

    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();

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

        $canManage = (bool) app(AccessManager::class)->can($user, 'annonces.manage');

        $notifications = $rawNotifications
            ->map(fn ($notification) => $this->mapNotification($notification, $announcements, $user, $canManage))
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
        $maintenanceTaskId = $notification?->data['maintenance_task_id'] ?? null;
        $destination = match ($type) {
            'hours_missing_entry_reminder', 'hours_attached_to_validation' => route('hours.index'),
            'hour_sheet_refused', 'hour_sheet_approved' => isset($notification?->data['hour_sheet_id'])
                ? route('hours.index', ['highlight' => (int) $notification->data['hour_sheet_id']])
                : route('hours.index'),
            'pending_validations_reminder' => ($notification?->data['target'] ?? null) === 'hours'
                ? route('hours.index')
                : route('leaves.index'),
            'maintenance_request_submitted', 'maintenance_task_assigned' => $maintenanceTaskId
                ? route('maintenance.index', ['focus_task_id' => (int) $maintenanceTaskId])
                : route('maintenance.index'),
            'announcement' => isset($notification?->data['announcement_id'])
                ? route('annonces.index', ['highlight' => (int) $notification->data['announcement_id']])
                : route('annonces.index'),
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

    public function destroyAll(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        // Ne supprime que les notifications déjà lues : si une nouvelle
        // notification arrive entre l'affichage du bouton et la validation
        // de l'utilisateur, elle est forcément non lue et survit donc à
        // cette opération plutôt que d'être supprimée par erreur.
        $deletedCount = DB::transaction(function () use ($user): int {
            $readNotifications = $user->notifications()
                ->whereNotNull('read_at')
                ->get(['id', 'read_at']);

            $count = $readNotifications->count();

            if ($count === 0) {
                return 0;
            }

            $this->auditLogService->log([
                'action' => 'Suppression de toutes les notifications',
                'module' => 'Notifications',
                'description' => 'Suppression de toutes les notifications lues',
                'payload' => [
                    'notification_ids' => $readNotifications->pluck('id')->map(fn ($id) => (string) $id)->all(),
                    'count' => $count,
                    'deleted_at' => now()->toIso8601String(),
                ],
            ]);

            $user->notifications()->whereNotNull('read_at')->delete();

            return $count;
        });

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'deleted_count' => $deletedCount]);
        }

        return back()->with('success', $deletedCount > 0 ? 'Notifications supprimées.' : 'Aucune notification à supprimer.');
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

    private function mapNotification(object $notification, ?\Illuminate\Support\Collection $announcements = null, $viewer = null, bool $canManage = false): array
    {
        $type = (string) ($notification->data['type'] ?? $notification->type);
        $leaveRequestId = $notification->data['leave_request_id'] ?? null;
        $announcementId = $notification->data['announcement_id'] ?? null;
        $maintenanceTaskId = $notification->data['maintenance_task_id'] ?? null;

        $title = isset($notification->data['title']) ? (string) $notification->data['title'] : null;
        $fullMessage = isset($notification->data['full_message']) ? (string) $notification->data['full_message'] : null;
        $announcementAuthor = null;
        $bodyHtml = null;
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
                $bodyHtml = SimpleHtmlSanitizer::render($announcement->body_html);
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
            'body_html' => $bodyHtml,
            'announcement_author' => $announcementAuthor,
            'poll' => $poll,
            'period' => [
                'start_at' => $notification->data['period']['start_at'] ?? null,
                'end_at' => $notification->data['period']['end_at'] ?? null,
            ],
            'requester_label' => $notification->data['requester_label'] ?? null,
            'leave_request_id' => $leaveRequestId,
            'announcement_id' => $announcementId,
            'maintenance_task_id' => $maintenanceTaskId,
            'url' => $this->notificationUrl($type, (array) $notification->data),
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

    /**
     * Destination d'une notification, déduite de son type et de sa charge utile.
     *
     * Prend le tableau `data` complet plutôt qu'une liste d'identifiants : chaque
     * type y puise ce dont il a besoin, sans qu'ajouter un type oblige à
     * rallonger la signature ni à corriger tous les appels.
     *
     * @param  array<string, mixed>  $data
     */
    private function notificationUrl(string $type, array $data): ?string
    {
        $maintenanceTypes = ['maintenance_request_submitted', 'maintenance_task_assigned'];

        if (in_array($type, $maintenanceTypes, true) && Route::has('maintenance.index')) {
            $maintenanceTaskId = $data['maintenance_task_id'] ?? null;

            return $maintenanceTaskId
                ? route('maintenance.index', ['focus_task_id' => (int) $maintenanceTaskId])
                : route('maintenance.index');
        }

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
            $leaveRequestId = $data['leave_request_id'] ?? null;

            return $leaveRequestId
                ? route('leaves.index', ['highlight' => $leaveRequestId])
                : route('leaves.index');
        }

        if ($type === 'hours_missing_entry_reminder' && Route::has('hours.index')) {
            return route('hours.index');
        }

        // Le rattrapage ne concerne que les heures ; le rappel hebdomadaire
        // peut porter sur les deux modules et transporte donc sa destination.
        if ($type === 'hours_attached_to_validation' && Route::has('hours.index')) {
            return route('hours.index');
        }

        // Décision sur une journée d'heures : le lien ouvre la journée
        // concernée, et non le haut de la page — l'historique peut en compter
        // des centaines. `hour_sheet_approved` n'est plus émis, mais les
        // notifications déjà en base doivent rester cliquables.
        if (in_array($type, ['hour_sheet_refused', 'hour_sheet_approved'], true) && Route::has('hours.index')) {
            $hourSheetId = $data['hour_sheet_id'] ?? null;

            return $hourSheetId
                ? route('hours.index', ['highlight' => (int) $hourSheetId])
                : route('hours.index');
        }

        if ($type === 'pending_validations_reminder') {
            $route = ($data['target'] ?? null) === 'hours' ? 'hours.index' : 'leaves.index';

            return Route::has($route) ? route($route) : null;
        }

        if ($type === 'announcement' && Route::has('annonces.index')) {
            $announcementId = $data['announcement_id'] ?? null;

            return $announcementId
                ? route('annonces.index', ['highlight' => $announcementId])
                : route('annonces.index');
        }

        return null;
    }
}
