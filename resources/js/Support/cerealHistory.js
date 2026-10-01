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
