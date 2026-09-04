/**
 * Durées de travail du module Heures.
 *
 * Ces fonctions étaient définies dans la page Heures ; elles sont extraites ici
 * pour qu'il n'existe qu'UNE seule manière de déterminer le total travaillé, la
 * durée normale d'une journée et l'écart entre les deux — la page et les tests
 * lisent désormais la même source.
 *
 * Les horaires par défaut, dont découle la durée normale d'une journée, vivent
 * dans hoursReference.json : l'export Excel en a besoin côté serveur
 * (app/Support/Hours/WorkTimeReference.php) et lit exactement le même fichier.
 * Ni le front ni le back ne détient sa propre copie de ces valeurs.
 */

import HOURS_REFERENCE from './hoursReference.json';

export function normalizeTimeForSelect(value) {
    const source = String(value || '').trim();
    if (!source) {
        return '';
    }

    const [hours, minutes] = source.split(':');
    if (hours === undefined || minutes === undefined) {
        return '';
    }

    return `${String(hours).padStart(2, '0').slice(-2)}:${String(minutes).padStart(2, '0').slice(-2)}`;
}

export function timeToMinutes(timeValue) {
    if (!timeValue || !timeValue.includes(':')) {
        return null;
    }

    const [hours, minutes] = timeValue.split(':').map((value) => Number(value));
    if (Number.isNaN(hours) || Number.isNaN(minutes)) {
        return null;
    }

    return (hours * 60) + minutes;
}

// allowOvernight (créneau "soir" uniquement) : si la fin est antérieure au
// début, on considère qu'elle a lieu le lendemain (+24h) pour le calcul de la
// durée uniquement, sans jamais modifier la date du jour ni créer d'heures
// sur la journée suivante.
export function computeRangeDuration(start, end, label, allowOvernight = false) {
    if (!start || !end) {
        return { minutes: 0, error: null };
    }

    const startMinutes = timeToMinutes(start);
    let endMinutes = timeToMinutes(end);

    if (startMinutes === null || endMinutes === null) {
        return { minutes: 0, error: null };
    }

    if (endMinutes < startMinutes) {
        if (!allowOvernight) {
            return {
                minutes: 0,
                error: `Plage ${label} invalide: arrivée avant départ.`,
            };
        }

        endMinutes += 24 * 60;
    }

    return { minutes: endMinutes - startMinutes, error: null };
}

// Calcule la durée totale d'une journée et ses éventuelles erreurs, en
// tenant compte du mode "journée continue / demi-journée" (une seule plage
// Début -> Fin) ou du mode classique (matin + soir séparés).
export function computeDayTotals(dayState, coverage) {
    if (dayState?.is_continuous_day) {
        const range = (coverage.morning || coverage.afternoon)
            ? { minutes: 0, error: null }
            : computeRangeDuration(dayState.morning_start, dayState.afternoon_end, 'journée continue', true);

        return { totalMinutes: range.minutes, errors: [range.error].filter(Boolean) };
    }

    const morningRange = coverage.morning
        ? { minutes: 0, error: null }
        : computeRangeDuration(dayState?.morning_start, dayState?.morning_end, 'matin');
    const eveningRange = coverage.afternoon
        ? { minutes: 0, error: null }
        : computeRangeDuration(dayState?.afternoon_start, dayState?.afternoon_end, 'soir', true);

    return {
        totalMinutes: morningRange.minutes + eveningRange.minutes,
        errors: [morningRange.error, eveningRange.error].filter(Boolean),
    };
}

export function formatWorkedDuration(totalMinutes) {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${String(hours).padStart(2, '0')}h${String(minutes).padStart(2, '0')}`;
}

export function defaultDayState({ isFriday = false } = {}) {
    return {
        morning_start: HOURS_REFERENCE.morning_start,
        morning_end: HOURS_REFERENCE.morning_end,
        afternoon_start: HOURS_REFERENCE.afternoon_start,
        afternoon_end: isFriday
            ? HOURS_REFERENCE.afternoon_end_friday
            : HOURS_REFERENCE.afternoon_end,
        description: '',
        is_not_worked: false,
        is_continuous_day: false,
        has_breakfast_before_5: false,
        has_lunch: false,
        has_dinner_after_21: false,
        has_long_night: false,
    };
}

export function nonWorkedDayState() {
    return {
        morning_start: '',
        morning_end: '',
        afternoon_start: '',
        afternoon_end: '',
        description: '',
        is_not_worked: true,
        is_continuous_day: false,
        has_breakfast_before_5: false,
        has_lunch: false,
        has_dinner_after_21: false,
        has_long_night: false,
    };
}

export function leaveCoverage(leaveInfo) {
    const morning = Boolean(leaveInfo?.morning);
    const afternoon = Boolean(leaveInfo?.afternoon);

    return {
        morning,
        afternoon,
        fullDay: Boolean(leaveInfo?.is_full_day) || (morning && afternoon),
    };
}

/**
 * Durée normale d'une journée, en minutes.
 *
 * Elle n'est PAS écrite en dur : elle est calculée à partir des horaires
 * préremplis du jour, qui sont la configuration existante du module. Lundi à
 * jeudi 08:00-12:00 / 14:00-18:00 donnent 8 h, le vendredi 08:00-12:00 /
 * 14:00-17:00 donnent 7 h. Changer `defaultDayState()` change donc la
 * référence, sans qu'aucune valeur ait à être tenue à jour en double.
 *
 * @param {number} dayIndex 0 = lundi … 6 = dimanche
 * @returns {number|null} minutes, ou null si le jour n'a pas de durée normale
 */
export function referenceMinutesForDayIndex(dayIndex) {
    // Samedi et dimanche : le module ne définit aucun horaire de référence
    // pour le week-end. On n'en invente pas — pas de durée, donc pas d'écart.
    if (!Number.isInteger(dayIndex) || dayIndex < 0 || dayIndex > 4) {
        return null;
    }

    const { totalMinutes } = computeDayTotals(
        defaultDayState({ isFriday: dayIndex === 4 }),
        { morning: false, afternoon: false, fullDay: false },
    );

    return totalMinutes;
}

/**
 * Durée normale d'une journée à partir de sa date ISO (AAAA-MM-JJ).
 */
export function referenceMinutesForDate(workDate) {
    const source = String(workDate || '').trim();

    if (!source) {
        return null;
    }

    const date = new Date(`${source}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    // getDay() : 0 = dimanche. La page compte les jours à partir du lundi.
    return referenceMinutesForDayIndex((date.getDay() + 6) % 7);
}

/**
 * Heures supplémentaires d'une journée, en minutes.
 *
 * Uniquement l'écart POSITIF : une journée plus courte que la normale ne
 * produit pas d'heures supplémentaires négatives, elle ne produit rien.
 * Sans durée de référence (week-end), il n'y a rien à comparer.
 */
export function overtimeMinutes(totalMinutes, referenceMinutes) {
    if (referenceMinutes === null || ! Number.isFinite(Number(totalMinutes))) {
        return 0;
    }

    const extra = Number(totalMinutes) - referenceMinutes;

    return extra > 0 ? extra : 0;
}

/**
 * Libellé des heures supplémentaires, ou null quand il n'y en a pas.
 *
 * Format volontairement non décimal : « +30 min » en dessous d'une heure,
 * « +1h45 » au-delà.
 */
export function formatOvertime(minutes) {
    const extra = Number(minutes) || 0;

    if (extra <= 0) {
        return null;
    }

    if (extra < 60) {
        return `+${extra} min`;
    }

    return `+${Math.floor(extra / 60)}h${String(extra % 60).padStart(2, '0')}`;
}

/**
 * Écart d'une journée saisie, prêt à afficher.
 *
 * Point d'entrée unique de la page : elle lui donne l'état de la journée, la
 * couverture de congé et la date, et reçoit le total et le libellé du badge.
 * Une journée non travaillée n'a pas d'heures supplémentaires.
 */
export function dayOvertimeLabel({ dayState, coverage, totalMinutes, dayIndex = null, workDate = null }) {
    if (dayState?.is_not_worked) {
        return null;
    }

    const total = Number.isFinite(Number(totalMinutes))
        ? Number(totalMinutes)
        : computeDayTotals(dayState, coverage ?? { morning: false, afternoon: false, fullDay: false }).totalMinutes;

    const reference = dayIndex !== null
        ? referenceMinutesForDayIndex(dayIndex)
        : referenceMinutesForDate(workDate);

    return formatOvertime(overtimeMinutes(total, reference));
}

/**
 * Cases particulières d'une journée, dans l'ordre du formulaire.
 *
 * Les libellés courts sont ceux déjà employés dans le récapitulatif d'une
 * journée ; le formulaire de saisie, lui, utilise des libellés longs
 * (« Casse-croûte (Avant 5h) ») qui n'auraient pas leur place sur une carte.
 */
export const EXTRA_FIELDS = [
    { key: 'has_breakfast_before_5', label: 'Casse-croûte' },
    { key: 'has_lunch', label: 'Déjeuner' },
    { key: 'has_dinner_after_21', label: 'Dîner' },
    { key: 'has_long_night', label: 'Nuit' },
];

/**
 * Libellés des seules cases cochées.
 *
 * @returns {string[]} vide si aucune case n'est cochée
 */
export function checkedExtraLabels(sheet) {
    return EXTRA_FIELDS
        .filter((field) => Boolean(sheet?.[field.key]))
        .map((field) => field.label);
}

/**
 * '08:00' → '08:00', valeur absente → '--:--'.
 */
function hourOrPlaceholder(value) {
    const source = String(value ?? '').trim();

    if (!source) {
        return '--:--';
    }

    const [hours, minutes] = source.split(':');

    if (hours === undefined || minutes === undefined) {
        return '--:--';
    }

    return `${String(hours).padStart(2, '0').slice(-2)}:${String(minutes).padStart(2, '0').slice(-2)}`;
}

function hasRange(start, end) {
    return String(start ?? '').trim() !== '' || String(end ?? '').trim() !== '';
}

/**
 * Horaires réellement saisis pour une journée, prêts à afficher.
 *
 * Les demi-journées couvertes par un congé sont annoncées comme telles, et la
 * journée continue est rendue comme la plage unique qu'elle est. Les heures
 * proviennent toujours de l'enregistrement, jamais des horaires théoriques.
 *
 * `omitEmpty` sert aux écrans qui n'ont pas connaissance des congés — la file
 * de validation : une demi-journée non renseignée y est passée sous silence
 * plutôt qu'affichée en « --:-- ». Sur l'écran du salarié, où la couverture de
 * congé est connue, l'emplacement vide reste visible.
 */
export function dayHoursLabel(sheet, { coverage = null, omitEmpty = false } = {}) {
    const onLeave = {
        morning: Boolean(coverage?.morning),
        afternoon: Boolean(coverage?.afternoon),
    };

    if (Boolean(sheet?.is_continuous_day) && ! onLeave.morning && ! onLeave.afternoon) {
        return `${hourOrPlaceholder(sheet?.morning_start)} - ${hourOrPlaceholder(sheet?.afternoon_end)} (journée continue)`;
    }

    const parts = [];

    if (onLeave.morning) {
        parts.push('Congé / Congé');
    } else if (! omitEmpty || hasRange(sheet?.morning_start, sheet?.morning_end)) {
        parts.push(`${hourOrPlaceholder(sheet?.morning_start)} - ${hourOrPlaceholder(sheet?.morning_end)}`);
    }

    if (onLeave.afternoon) {
        parts.push('Congé / Congé');
    } else if (! omitEmpty || hasRange(sheet?.afternoon_start, sheet?.afternoon_end)) {
        parts.push(`${hourOrPlaceholder(sheet?.afternoon_start)} - ${hourOrPlaceholder(sheet?.afternoon_end)}`);
    }

    return parts.length > 0 ? parts.join(' / ') : null;
}
