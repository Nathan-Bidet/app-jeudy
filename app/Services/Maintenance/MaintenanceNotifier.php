<?php

namespace App\Services\Maintenance;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Notifications\MaintenanceRequestSubmittedNotification;
use App\Notifications\MaintenanceTaskAssignedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Envoi des notifications du module Maintenance.
 *
 * Deux canaux, comme partout ailleurs dans APP Jeudy : notification en base
 * (centre de notifications) et push web via SendWebPushNotificationJob.
 *
 * Règle absolue : aucun commentaire, masqué ou non, ne transite par une
 * notification. Les libellés sont construits à partir de la date et de la
 * description de la tâche uniquement (voir MaintenanceTaskSummary).
 */
class MaintenanceNotifier
{
    /**
     * Champs dont la modification justifie de prévenir la personne affectée.
     * Le commentaire en est volontairement absent : il peut être masqué, et
     * signaler sa modification renseignerait déjà sur son existence.
     */
    private const BUSINESS_FIELDS = [
        'date',
        'fin_date',
        'due_date',
        'depot_id',
        'address_free',
        'task',
    ];

    public function __construct(private readonly MaintenanceRecipientResolver $recipients) {}

    /**
     * Nouvelle demande : prévient ceux qui peuvent la traiter, c'est-à-dire les
     * détenteurs de maintenance.create (seuls habilités à reprendre n'importe
     * quelle tâche). Le demandeur ne se notifie pas lui-même.
     */
    public function requestSubmitted(MaintenanceTask $task, User $requester): void
    {
        $recipients = $this->recipients->usersWithAbility('maintenance.create', [$requester->id]);

        if ($recipients->isEmpty()) {
            return;
        }

        $label = $this->userLabel($requester);
        $notification = new MaintenanceRequestSubmittedNotification($task, $label);

        $this->safely(fn () => Notification::send($recipients, $notification), $task->id);

        foreach ($recipients as $recipient) {
            $this->push($recipient->id, $task->id, 'request', [
                'title' => 'Nouvelle demande de maintenance',
                'body' => sprintf(
                    "Demande de %s\n%s",
                    $label,
                    \App\Notifications\MaintenanceTaskSummary::excerpt($task->task),
                ),
            ], $task);
        }
    }

    /**
     * Affectation initiale à un utilisateur réel. Une personne saisie librement
     * ou une tâche non affectée ne déclenchent aucune notification utilisateur.
     */
    public function taskAssigned(MaintenanceTask $task, ?int $actorId = null): void
    {
        $this->notifyAssignee(
            $task->assignee_user_id,
            $task,
            MaintenanceTaskAssignedNotification::REASON_ASSIGNED,
            $actorId,
        );
    }

    /**
     * Suite d'une modification : réaffectation, retrait, ou simple mise à jour
     * métier de la tâche pour la personne déjà affectée.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function taskUpdated(MaintenanceTask $task, array $before, array $after, ?int $actorId = null): void
    {
        $previousAssignee = $this->toId($before['assignee_user_id'] ?? null);
        $currentAssignee = $this->toId($after['assignee_user_id'] ?? null);

        if ($previousAssignee !== $currentAssignee) {
            if ($previousAssignee !== null) {
                $this->notifyAssignee(
                    $previousAssignee,
                    $task,
                    MaintenanceTaskAssignedNotification::REASON_UNASSIGNED,
                    $actorId,
                );
            }

            if ($currentAssignee !== null) {
                $this->notifyAssignee(
                    $currentAssignee,
                    $task,
                    MaintenanceTaskAssignedNotification::REASON_ASSIGNED,
                    $actorId,
                );
            }

            return;
        }

        // Même personne affectée : on ne la dérange que si un champ métier a
        // réellement changé.
        if ($currentAssignee === null || ! $this->businessFieldsChanged($before, $after)) {
            return;
        }

        $this->notifyAssignee(
            $currentAssignee,
            $task,
            MaintenanceTaskAssignedNotification::REASON_UPDATED,
            $actorId,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function businessFieldsChanged(array $before, array $after): bool
    {
        foreach (self::BUSINESS_FIELDS as $field) {
            if ($this->normalize($before[$field] ?? null) !== $this->normalize($after[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function notifyAssignee(?int $userId, MaintenanceTask $task, string $reason, ?int $actorId): void
    {
        if ($userId === null) {
            return;
        }

        // On ne se notifie pas de sa propre action.
        if ($actorId !== null && (int) $actorId === $userId) {
            return;
        }

        $user = User::query()->where('is_active', true)->find($userId);

        if (! $user) {
            return;
        }

        $notification = new MaintenanceTaskAssignedNotification($task, $reason);

        $this->safely(fn () => $user->notify($notification), $task->id);

        $this->push($user->id, $task->id, $reason, [
            'title' => $notification->title(),
            'body' => $notification->message(),
        ], $task);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(int $userId, ?int $taskId, string $kind, array $payload, MaintenanceTask $task): void
    {
        // Même garde-fou anti-doublon que le module À Prévoir : une même
        // notification ne part pas deux fois en moins d'une minute.
        $cacheKey = sprintf('push_maintenance_%s_%d_%d', $kind, $userId, (int) $taskId);

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, 60);

        $this->safely(fn () => SendWebPushNotificationJob::dispatch($userId, array_merge($payload, [
            'icon' => '/pwa-192.png',
            'url' => route('maintenance.index', ['focus_task_id' => $task->id]),
            'resourceType' => 'maintenance_task',
            'resourceId' => $task->id,
        ])), $taskId);
    }

    /**
     * Une notification qui échoue ne doit jamais faire échouer l'action métier
     * qui l'a déclenchée.
     */
    private function safely(callable $callback, ?int $taskId): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('MaintenanceNotifier: envoi impossible', [
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function userLabel(User $user): string
    {
        $full = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

        if ($full !== '') {
            return $full;
        }

        $name = trim((string) $user->name);

        return $name !== '' ? $name : (string) $user->email;
    }

    private function toId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim(preg_replace('/ {2,}/', ' ', (string) $value) ?? (string) $value);
    }
}
