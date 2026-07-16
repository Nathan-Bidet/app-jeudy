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
    /**
     * Champs métier surveillés pour "Tâche modifiée" (hors affectation chauffeur).
     * Texte  : date, fin_date, task_label, loading_place, delivery_place, comment, boursagri_contract_number
     * Entier : vehicle_id, remorque_id
     * Booléen: is_direct, is_boursagri
     */
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

        // Même chauffeur : notifier uniquement si un champ métier a réellement changé
        if ($oldUserId !== null && $oldUserId === $newUserId) {
            if ($this->hasBusinessFieldChanged($before, $after)) {
                $date = $this->formatDate($after['date'] ?? null);
                $body = $this->buildBody(
                    $date
                        ? sprintf('Votre tâche du %s a été mise à jour.', $date)
                        : 'Votre tâche a été mise à jour.',
                    $this->shortDescription($after),
                );
                $this->sendPush($oldUserId, $taskId, 'modified', [
                    'title' => 'Tâche modifiée',
                    'body' => $body,
                    'url' => route('ldt.index', ['focus_task_id' => $taskId]),
                    'resourceType' => 'workbook_task',
                    'resourceId' => $taskId,
                ]);
            }

            return;
        }

        // Ancien chauffeur retiré
        if ($oldUserId !== null) {
            $detail = $this->buildDetailLine($before['date'] ?? null, $this->shortDescription($before));
            $this->sendPush($oldUserId, $taskId, 'removed', [
                'title' => 'Tâche retirée',
                'body' => $this->buildBody('Une tâche ne vous est plus affectée.', $detail),
                'url' => route('ldt.index'),
                'resourceType' => 'workbook_task',
                'resourceId' => null,
            ]);
        }

        // Nouveau chauffeur affecté
        if ($newUserId !== null) {
            $detail = $this->buildDetailLine($after['date'] ?? null, $this->shortDescription($after));
            $this->sendPush($newUserId, $taskId, 'assigned', [
                'title' => 'Nouvelle tâche affectée',
                'body' => $this->buildBody('Une nouvelle tâche vous a été affectée.', $detail),
                'url' => route('ldt.index', ['focus_task_id' => $taskId]),
                'resourceType' => 'workbook_task',
                'resourceId' => $taskId,
            ]);
        }
    }

    /**
     * Compare les champs métier (hors affectation) et retourne true si au moins
     * un champ a réellement changé après normalisation.
     */
    private function hasBusinessFieldChanged(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null) {
            return true;
        }

        // Champs texte : null == '' et espaces inutiles ignorés
        foreach (['task_label', 'loading_place', 'delivery_place', 'comment', 'boursagri_contract_number'] as $field) {
            if ($this->normalizeText($before[$field] ?? null) !== $this->normalizeText($after[$field] ?? null)) {
                return true;
            }
        }

        // Dates déjà normalisées en 'Y-m-d' par Carbon dans le snapshot
        foreach (['date', 'fin_date'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return true;
            }
        }

        // Identifiants entiers
        foreach (['vehicle_id', 'remorque_id'] as $field) {
            $bv = ($before[$field] ?? null) !== null ? (int) $before[$field] : null;
            $av = ($after[$field] ?? null) !== null ? (int) $after[$field] : null;
            if ($bv !== $av) {
                return true;
            }
        }

        // Booléens
        foreach (['is_direct', 'is_boursagri'] as $field) {
            if ((bool) ($before[$field] ?? false) !== (bool) ($after[$field] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise un champ texte : trim + collapse des espaces multiples.
     * null et '' sont équivalents.
     * Les retours à la ligne internes sont conservés.
     */
    private function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = trim($value);

        return preg_replace('/ {2,}/', ' ', $value) ?? $value;
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
        return $detailLine === null ? $mainLine : $mainLine."\n".$detailLine;
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
