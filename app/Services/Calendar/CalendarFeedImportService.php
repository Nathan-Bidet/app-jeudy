<?php

namespace App\Services\Calendar;

use App\Models\CalendarFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalendarFeedImportService
{
    private const PAYLOAD_CACHE_MINUTES = 15;
    private const PARSED_EVENTS_CACHE_MINUTES = 10;

    /**
     * @param  array<int, CalendarFeed>  $feeds
     * @return array<int, array<string, mixed>>
     */
    public function eventsForRange(array $feeds, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $events = [];

        foreach ($feeds as $feed) {
            if (! $feed->is_active) {
                continue;
            }

            try {
                $icsPayload = $this->getCachedPayload($feed);
            } catch (Throwable $exception) {
                Log::warning('calendar_feed_fetch_failed', [
                    'feed_id' => $feed->id,
                    'url' => $feed->url,
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            if (! is_string($icsPayload) || trim($icsPayload) === '') {
                continue;
            }

            $feedEvents = $this->getCachedParsedEvents($feed, $icsPayload, $rangeStart, $rangeEnd);
            if ($feedEvents !== []) {
                $events = array_merge($events, $feedEvents);
            }

            $feed->forceFill(['last_synced_at' => now()])->save();
        }

        usort($events, static fn (array $left, array $right): int => strcmp((string) $left['start_at'], (string) $right['start_at']));

        return $events;
    }

    private function getCachedPayload(CalendarFeed $feed): string
    {
        $fetchUrl = preg_replace('/^webcal:\/\//i', 'https://', (string) $feed->url) ?: (string) $feed->url;
        $key = sprintf(
            'calendar_feed:payload:%d:%s',
            (int) $feed->id,
            sha1($fetchUrl.'|'.(string) $feed->updated_at),
        );

        /** @var string $payload */
        $payload = Cache::remember($key, now()->addMinutes(self::PAYLOAD_CACHE_MINUTES), static function () use ($fetchUrl): string {
            return (string) Http::timeout(6)->get($fetchUrl)->body();
        });

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCachedParsedEvents(
        CalendarFeed $feed,
        string $icsPayload,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd
    ): array {
        $key = sprintf(
            'calendar_feed:events:%d:%s:%s:%s:%s',
            (int) $feed->id,
            $rangeStart->format('YmdHis'),
            $rangeEnd->format('YmdHis'),
            sha1((string) $feed->updated_at),
            sha1($icsPayload),
        );

        /** @var array<int, array<string, mixed>> $events */
        $events = Cache::remember(
            $key,
            now()->addMinutes(self::PARSED_EVENTS_CACHE_MINUTES),
            fn (): array => $this->parseIcs($icsPayload, $feed, $rangeStart, $rangeEnd),
        );

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseIcs(
        string $icsPayload,
        CalendarFeed $feed,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd
    ): array {
        $lines = preg_split('/\r\n|\r|\n/', $icsPayload) ?: [];
        $lines = $this->unfoldLines($lines);

        $events = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }

            if ($line === 'END:VEVENT') {
                if (is_array($current)) {
                    $mapped = $this->mapEvent($current, $feed, $rangeStart, $rangeEnd);
                    if ($mapped !== null) {
                        $events[] = $mapped;
                    }
                }
                $current = null;
                continue;
            }

            if (! is_array($current)) {
                continue;
            }

            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $rawKey = substr($line, 0, $separator);
            $value = substr($line, $separator + 1);
            $key = strtoupper(explode(';', $rawKey)[0] ?? '');
            if ($key === '') {
                continue;
            }

            $current[$key] = $value;
        }

        return $events;
    }

    /**
     * @param  array<string, string>  $event
     * @return array<string, mixed>|null
     */
    private function mapEvent(
        array $event,
        CalendarFeed $feed,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd
    ): ?array {
        $startRaw = $event['DTSTART'] ?? null;
        if (! $startRaw) {
            return null;
        }

        $start = $this->parseIcsDate((string) $startRaw);
        if (! $start) {
            return null;
        }

        $allDay = $this->isAllDay((string) $startRaw);
        $endRaw = $event['DTEND'] ?? null;
        $end = $endRaw ? $this->parseIcsDate((string) $endRaw) : null;
        $normalizedEnd = $this->normalizeAllDayExclusiveEnd($start, $end, $allDay);

        $intersects = $start <= $rangeEnd && ($normalizedEnd === null ? $start >= $rangeStart : $normalizedEnd >= $rangeStart);
        if (! $intersects) {
            return null;
        }

        $title = trim((string) ($event['SUMMARY'] ?? 'Événement externe'));
        $description = trim((string) ($event['DESCRIPTION'] ?? ''));
        $uid = trim((string) ($event['UID'] ?? sha1($feed->id.'|'.$title.'|'.$start->toIso8601String())));

        return [
            'id' => 'feed-'.$feed->id.'-'.substr(sha1($uid), 0, 20),
            'title' => $title !== '' ? $title : 'Événement externe',
            'description' => $description !== '' ? $description : null,
            'start_at' => $start->toIso8601String(),
            'start_at_local' => $start->format('Y-m-d\TH:i'),
            'start_at_label' => $start->format('d/m/Y H:i'),
            'end_at' => $normalizedEnd?->toIso8601String(),
            'end_at_local' => $normalizedEnd?->format('Y-m-d\TH:i'),
            'end_at_label' => $normalizedEnd?->format('d/m/Y H:i'),
            'all_day' => $allDay,
            'category_id' => null,
            'category' => [
                'id' => null,
                'name' => $feed->name,
                'color' => $feed->color ?? '#3A86FF',
                'is_active' => true,
            ],
            'source' => 'feed',
            'is_external' => true,
            'feed_id' => (int) $feed->id,
            'feed_name' => $feed->name,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function unfoldLines(array $lines): array
    {
        $unfolded = [];

        foreach ($lines as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                $lastIndex = count($unfolded) - 1;
                if ($lastIndex >= 0) {
                    $unfolded[$lastIndex] .= ltrim($line);
                }
                continue;
            }

            $unfolded[] = $line;
        }

        return $unfolded;
    }

    private function parseIcsDate(string $value): ?CarbonImmutable
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/', $normalized) === 1) {
            return CarbonImmutable::createFromFormat('Ymd', $normalized)?->startOfDay();
        }

        if (preg_match('/^\d{8}T\d{6}Z$/', $normalized) === 1) {
            return CarbonImmutable::createFromFormat('Ymd\THis\Z', $normalized, 'UTC')
                ?->setTimezone(config('app.timezone', 'Europe/Paris'));
        }

        if (preg_match('/^\d{8}T\d{6}$/', $normalized) === 1) {
            return CarbonImmutable::createFromFormat('Ymd\THis', $normalized, config('app.timezone', 'Europe/Paris'));
        }

        if (preg_match('/^\d{8}T\d{4}$/', $normalized) === 1) {
            return CarbonImmutable::createFromFormat('Ymd\THi', $normalized, config('app.timezone', 'Europe/Paris'));
        }

        return null;
    }

    private function isAllDay(string $startRaw): bool
    {
        return preg_match('/^\d{8}$/', trim($startRaw)) === 1;
    }

    private function normalizeAllDayExclusiveEnd(
        CarbonImmutable $start,
        ?CarbonImmutable $end,
        bool $allDay
    ): ?CarbonImmutable {
        if (! $allDay || $end === null) {
            return $end;
        }

        // In ICS, all-day DTEND is exclusive (next day). Convert to inclusive range.
        $isMidnightEnd = $end->format('H:i:s') === '00:00:00';
        if ($isMidnightEnd && $end->greaterThan($start)) {
            return $end->subDay()->startOfDay();
        }

        return $end;
    }
}
