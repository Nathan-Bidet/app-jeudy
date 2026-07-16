<?php

namespace App\Listeners;

use App\Events\AprevoirTaskChanged;
use App\Jobs\SendWebPushNotificationJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyWorkbookTaskAssigneeListener
{
    public function handle(AprevoirTaskChanged $event): void
    {
        if (! in_array($event->action, ['created', 'updated', 'deleted'], true)) {
            return;
        }

        $before = $event->before;
        $after = $event->after;
        $taskId = $event->taskId;

        $oldUserId = $this->extractUserId($before);
        $newUserId = $this->extractUserId($after);

        // Même utilisateur : tâche modifiée
        if ($oldUserId !== null && $oldUserId === $newUserId) {
            $desc = $this->shortDescription($after);
            $date = $this->formatDate($after['date'] ?? null);
            $body = $this->buildBody(
                $date ? sprintf('Votre tâche du %s a été mise à jour.', $date) : 'Votre tâche a été mise à jour.',
                $desc,
            );
            $this->sendPush($oldUserId, $taskId, 'modified', [
                'title' => 'Tâche modifiée',
                'body' => $body,
                'url' => route('ldt.index', ['focus_task_id' => $taskId]),
                'resourceType' => 'workbook_task',
                'resourceId' => $taskId,
            ]);

            return;
        }

        // Ancien utilisateur retiré
        if ($oldUserId !== null) {
            $desc = $this->shortDescription($before);
            $detail = $this->buildDetailLine($before['date'] ?? null, $desc);
            $body = $this->buildBody('Une tâche ne vous est plus affectée.', $detail);
            $this->sendPush($oldUserId, $taskId, 'removed', [
                'title' => 'Tâche retirée',
                'body' => $body,
                'url' => route('ldt.index'),
                'resourceType' => 'workbook_task',
                'resourceId' => null,
            ]);
        }

        // Nouvel utilisateur affecté
        if ($newUserId !== null) {
            $desc = $this->shortDescription($after);
            $detail = $this->buildDetailLine($after['date'] ?? null, $desc);
            $body = $this->buildBody('Une nouvelle tâche vous a été affectée.', $detail);
            $this->sendPush($newUserId, $taskId, 'assigned', [
                'title' => 'Nouvelle tâche affectée',
                'body' => $body,
                'url' => route('ldt.index', ['focus_task_id' => $taskId]),
                'resourceType' => 'workbook_task',
                'resourceId' => $taskId,
            ]);
        }
    }

    private function extractUserId(?array $snapshot): ?int
    {
        if ($snapshot === null) {
            return null;
        }
        if (($snapshot['assignee_type'] ?? null) !== 'user') {
            return null;
        }
        $id = $snapshot['assignee_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    private function shortDescription(?array $snapshot): ?string
    {
        if ($snapshot === null) {
            return null;
        }
        $value = ($snapshot['task_label'] ?? null)
            ?: ($snapshot['delivery_place'] ?? null)
            ?: ($snapshot['loading_place'] ?? null);

        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57).'...' : $value;
    }

    private function formatDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        try {
            return Carbon::parse($date)->format('d/m/Y');
        } catch (Throwable) {
            return $date;
        }
    }

    private function buildDetailLine(?string $date, ?string $description): ?string
    {
        $formattedDate = $this->formatDate($date);
        $parts = array_filter([$formattedDate, $description]);

        return $parts ? implode(' • ', $parts) : null;
    }

    private function buildBody(string $mainLine, ?string $detailLine): string
    {
        if ($detailLine === null) {
            return $mainLine;
        }

        return $mainLine."\n".$detailLine;
    }

    private function sendPush(?int $userId, ?int $taskId, string $notifType, array $payload): void
    {
        if ($userId === null) {
            return;
        }

        $cacheKey = sprintf('push_workbook_%s_%d_%d', $notifType, $userId, (int) $taskId);
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, 60);

        try {
            SendWebPushNotificationJob::dispatch($userId, array_merge($payload, [
                'icon' => '/pwa-192.png',
            ]));
        } catch (Throwable $e) {
            Log::warning('NotifyWorkbookTaskAssigneeListener: push dispatch failed', [
                'user_id' => $userId,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
