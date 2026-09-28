/**
 * Éléments d'affichage partagés d'une journée d'heures.
 *
 * Extraits de la page Heures pour que le détail en modal les rende à
 * l'identique : un badge défini deux fois finirait par diverger.
 */

/** Date d'une journée, en toutes lettres : « Mercredi 2 septembre 2026 ». */
export function formatHourSheetDate(workDate) {
    const source = String(workDate || '').trim();
    if (!source) {
        return workDate;
    }

    const date = new Date(`${source}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return workDate;
    }

    const formatter = new Intl.DateTimeFormat('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
    const formatted = formatter.format(date);

    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
}

/**
 * Badge des heures supplémentaires d'une journée.
 *
 * Rendu seulement quand il y a un dépassement : une journée conforme ou plus
 * courte que la normale n'affiche rien. Le libellé vient de
 * `dayOvertimeLabel()`, unique source du calcul.
 */
export function OvertimeBadge({ label }) {
    if (!label) {
        return null;
    }

    return (
        <span
            title="Heures supplémentaires par rapport à la durée normale de la journée"
            className="inline-flex shrink-0 items-center rounded-full border border-[#ef4444] bg-white px-2.5 py-0.5 text-xs font-semibold text-[#b91c1c]"
        >
            {label}
        </span>
    );
}

/**
 * Badge d'état d'une journée, destiné au salarié.
 *
 * Il résume le circuit sans dire lequel des deux valideurs manque : « En
 * validation » tant que les deux accords ne sont pas réunis, puis « Traitée »
 * — le serveur ne transmet au salarié ni l'issue (validée ou refusée) ni le
 * motif (HourSheetOwnerView). Une journée saisie avant la mise en place de la
 * validation n'a pas d'état de circuit et le dit telle quelle.
 */
export function HourSheetStatusBadge({ sheet }) {
    const status = String(sheet?.status || '').toLowerCase();

    const { label, dot, className } = (() => {
        if (status === 'processed') {
            // Même famille jaune que « En validation », mais pleine et en texte
            // noir : lisible, et distincte d'une journée encore en attente.
            return {
                label: sheet?.status_label || 'Traitée',
                dot: '#a16207',
                className: 'border-[#eab308] bg-[#fde047] text-black',
            };
        }

        if (status === '') {
            return {
                label: sheet?.status_label || 'Saisie antérieure à la validation',
                dot: '#9ca3af',
                className: 'border-gray-300 text-gray-600',
            };
        }

        return {
            label: 'En validation',
            dot: '#eab308',
            className: 'border-[#eab308] text-[#a16207]',
        };
    })();

    return (
        <span
            data-status={status || 'legacy'}
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${status === 'processed' ? '' : 'bg-white'} ${className}`}
        >
            <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: dot }} aria-hidden="true" />
            {label}
        </span>
    );
}
