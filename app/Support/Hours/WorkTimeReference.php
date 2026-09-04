<?php

namespace App\Support\Hours;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Durée normale d'une journée de travail, et écart avec les heures réellement
 * saisies.
 *
 * SOURCE UNIQUE PARTAGÉE AVEC LE FRONT. La référence n'est pas écrite ici : elle
 * est DÉDUITE des horaires par défaut d'une journée, stockés dans
 * `resources/js/Support/hoursReference.json`. Le module JS
 * `hoursWorkTime.js` lit ce même fichier et procède exactement de la même
 * façon : 08:00-12:00 puis 14:00-18:00 font 8 h, et 14:00-17:00 font 7 h le
 * vendredi.
 *
 * C'est ce qui garantit ce que la carte de saisie, la file du valideur et
 * l'export Excel annoncent la même chose. Écrire « 480 » en dur ici aurait créé
 * une seconde vérité, libre de diverger au premier changement d'horaire.
 */
class WorkTimeReference
{
    /** @var array<string, string>|null */
    private static ?array $reference = null;

    /**
     * Durée normale d'une journée, en minutes, à partir de son indice.
     *
     * @param  int  $dayIndex  0 = lundi … 6 = dimanche
     * @return int|null null quand le jour n'a pas de durée normale
     */
    public static function referenceMinutesForDayIndex(int $dayIndex): ?int
    {
        // Samedi et dimanche : le module ne définit aucun horaire de référence
        // pour le week-end. On n'en invente pas — pas de durée, donc pas d'écart.
        if ($dayIndex < 0 || $dayIndex > 4) {
            return null;
        }

        $reference = self::reference();

        $morning = self::rangeMinutes($reference['morning_start'] ?? null, $reference['morning_end'] ?? null);
        $afternoon = self::rangeMinutes(
            $reference['afternoon_start'] ?? null,
            $dayIndex === 4
                ? ($reference['afternoon_end_friday'] ?? null)
                : ($reference['afternoon_end'] ?? null),
        );

        return $morning + $afternoon;
    }

    /**
     * Durée normale d'une journée à partir de sa date (AAAA-MM-JJ ou date).
     */
    public static function referenceMinutesForDate(CarbonImmutable|string|null $workDate): ?int
    {
        if ($workDate === null || $workDate === '') {
            return null;
        }

        try {
            $date = $workDate instanceof CarbonImmutable ? $workDate : CarbonImmutable::parse($workDate);
        } catch (Throwable) {
            return null;
        }

        // dayOfWeek : 0 = dimanche. Le module compte les jours à partir du lundi.
        return self::referenceMinutesForDayIndex(($date->dayOfWeek + 6) % 7);
    }

    /**
     * Heures supplémentaires d'une journée, en minutes.
     *
     * Uniquement l'écart POSITIF : une journée plus courte que la normale ne
     * produit pas d'heures supplémentaires négatives, elle ne produit rien.
     * Sans durée de référence (week-end), il n'y a rien à comparer.
     */
    public static function overtimeMinutes(int $totalMinutes, ?int $referenceMinutes): int
    {
        if ($referenceMinutes === null) {
            return 0;
        }

        return max(0, $totalMinutes - $referenceMinutes);
    }

    /**
     * Heures supplémentaires d'une journée saisie, en minutes.
     *
     * Point d'entrée de l'export : il donne le total déjà calculé et la date,
     * et reçoit l'écart. Une journée non travaillée n'a pas d'écart.
     */
    public static function overtimeForDay(int $totalMinutes, CarbonImmutable|string|null $workDate, bool $isNotWorked = false): int
    {
        if ($isNotWorked) {
            return 0;
        }

        return self::overtimeMinutes($totalMinutes, self::referenceMinutesForDate($workDate));
    }

    /**
     * Horaires par défaut, lus une fois par requête.
     *
     * @return array<string, string>
     */
    private static function reference(): array
    {
        if (self::$reference !== null) {
            return self::$reference;
        }

        $path = resource_path('js/Support/hoursReference.json');
        $decoded = is_readable($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;

        return self::$reference = is_array($decoded) ? $decoded : [];
    }

    /**
     * Durée d'une plage HH:MM → HH:MM, en minutes.
     *
     * Les horaires de référence ne franchissent pas minuit : une plage
     * incohérente vaut zéro plutôt que de produire une durée fantaisiste.
     */
    private static function rangeMinutes(?string $start, ?string $end): int
    {
        $startMinutes = self::timeToMinutes($start);
        $endMinutes = self::timeToMinutes($end);

        if ($startMinutes === null || $endMinutes === null || $endMinutes < $startMinutes) {
            return 0;
        }

        return $endMinutes - $startMinutes;
    }

    private static function timeToMinutes(?string $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches)) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }
}
