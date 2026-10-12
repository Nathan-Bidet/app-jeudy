/** Utilitaires de l'historique des cotations de céréales (dates au format Y-m-d). */

export const WEEKDAY_LABELS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

const pad = (value) => String(value).padStart(2, '0');

/** '2026-10-01' → '01/10/2026' (jamais de « Présent » : toujours la date consultée). */
export function formatFrDate(isoDate) {
    const match = String(isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return match ? `${match[3]}/${match[2]}/${match[1]}` : '';
}

export function monthLabel(year, monthIndex) {
    const label = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(year, monthIndex, 1)));

    return label.charAt(0).toUpperCase() + label.slice(1);
}

/**
 * Grille d'un mois, semaines du lundi au dimanche ; les cases hors mois valent null.
 * @returns {Array<Array<string|null>>}
 */
export function buildMonthGrid(year, monthIndex) {
    const daysInMonth = new Date(Date.UTC(year, monthIndex + 1, 0)).getUTCDate();
    const firstWeekday = (new Date(Date.UTC(year, monthIndex, 1)).getUTCDay() + 6) % 7;
    const cells = [
        ...Array(firstWeekday).fill(null),
        ...Array.from({ length: daysInMonth }, (_, i) => `${year}-${pad(monthIndex + 1)}-${pad(i + 1)}`),
    ];
    while (cells.length % 7 !== 0) cells.push(null);

    return Array.from({ length: cells.length / 7 }, (_, week) => cells.slice(week * 7, week * 7 + 7));
}

/** Dates triées du plus récent au plus ancien : voisin plus ancien/plus récent, ou null. */
export function neighborDate(datesDesc, current, direction) {
    const index = datesDesc.indexOf(current);
    if (index < 0) return null;

    return (direction === 'older' ? datesDesc[index + 1] : datesDesc[index - 1]) ?? null;
}

export function parseMonth(isoDate) {
    const match = String(isoDate || '').match(/^(\d{4})-(\d{2})-/);

    return match ? { year: Number(match[1]), monthIndex: Number(match[2]) - 1 } : null;
}

/* ------------------------------------------------------------------ Graphiques */

export const CHART_PERIODS = [
    { id: '7d', label: '7 derniers jours' },
    { id: '30d', label: '30 derniers jours' },
    { id: '3m', label: '3 derniers mois' },
    { id: '1y', label: '1 an' },
    { id: 'custom', label: 'Période personnalisée' },
];

export const MAX_CHART_DAYS = 366;

const toUtc = (isoDate) => {
    const match = String(isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return match ? new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))) : null;
};

const toIso = (date) => `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;

export function addDays(isoDate, days) {
    const date = toUtc(isoDate);
    if (!date) return '';
    date.setUTCDate(date.getUTCDate() + days);

    return toIso(date);
}

function addMonths(isoDate, months) {
    const date = toUtc(isoDate);
    if (!date) return '';
    const day = date.getUTCDate();
    date.setUTCDate(1);
    date.setUTCMonth(date.getUTCMonth() + months);
    const lastDay = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
    date.setUTCDate(Math.min(day, lastDay));

    return toIso(date);
}

/**
 * Plage d'une période prédéfinie, se terminant à la date consultée (incluse) :
 * 7 jours = fin − 6 j ; 30 jours = fin − 29 j ; 3 mois / 1 an = même jour 3 mois / 1 an plus tôt, + 1 jour.
 */
export function computeRange(period, endDate) {
    if (!toUtc(endDate)) return null;

    switch (period) {
        case '7d': return { from: addDays(endDate, -6), to: endDate };
        case '30d': return { from: addDays(endDate, -29), to: endDate };
        case '3m': return { from: addDays(addMonths(endDate, -3), 1), to: endDate };
        case '1y': return { from: addDays(addMonths(endDate, -12), 1), to: endDate };
        default: return null;
    }
}

/** Tous les jours de la plage (Y-m-d), bornes incluses. */
export function enumerateDays(from, to) {
    const days = [];
    const start = toUtc(from);
    const end = toUtc(to);
    if (!start || !end) return days;
    for (let d = start; d <= end; d = new Date(d.getTime() + 86400000)) days.push(toIso(d));

    return days;
}

/** Message d'erreur d'une plage personnalisée, ou '' si elle est valide. */
export function validateCustomRange(from, to) {
    if (!toUtc(from) || !toUtc(to)) return 'Indiquez une date de début et une date de fin.';
    if (from > to) return 'La date de fin doit être postérieure ou égale à la date de début.';
    if (enumerateDays(from, to).length > MAX_CHART_DAYS) return 'La période ne peut pas dépasser un an (366 jours).';

    return '';
}

/** Libellé d'une série : la ligne de cotation (livraison, échéance) et sa récolte. */
export function seriesLabel(series) {
    const label = String(series?.display_label || '').trim();
    const maturity = String(series?.maturity_label || '').trim();

    return `${label ? `${label} — ` : ''}${maturity} · récolte ${series?.harvest_year}`;
}

/**
 * Segments de tracé : on ne relie que deux jours CONSÉCUTIFS ayant chacun un point ;
 * un jour sans cotation interrompt la courbe (aucune valeur intermédiaire suggérée).
 * @param {Array<{date:string}>} points triés par date
 * @param {string[]} days jours de la plage
 * @returns {Array<Array<{date:string}>>}
 */
export function buildSegments(points, days) {
    const byDate = new Map(points.map((point) => [point.date, point]));
    const segments = [];
    let current = [];

    days.forEach((day) => {
        const point = byDate.get(day);
        if (point) {
            current.push(point);
        } else if (current.length) {
            segments.push(current);
            current = [];
        }
    });
    if (current.length) segments.push(current);

    return segments;
}
