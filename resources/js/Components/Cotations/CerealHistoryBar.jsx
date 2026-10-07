import CerealHistoryCalendar from '@/Components/Cotations/CerealHistoryCalendar';
import { formatFrDate, neighborDate } from '@/Support/cerealHistory';
import { CalendarDays, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useRef, useState } from 'react';

const BUTTON = 'inline-flex items-center justify-center gap-1 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-[11px] font-black uppercase tracking-[0.06em] disabled:opacity-50 sm:gap-1.5 sm:px-3 sm:py-2 sm:text-xs sm:tracking-[0.1em]';

/**
 * Barre de navigation de l'historique des cotations de céréales :
 * « Plus ancienne » · [date consultée → calendrier] · « Plus récente » · « Fermer l'historique ».
 * `dates` : jours disponibles, du plus récent au plus ancien.
 */
export default function CerealHistoryBar({ dates, activeDate, loading = false, onSelectDate, onClose }) {
    const [calendarOpen, setCalendarOpen] = useState(false);
    const dateButtonRef = useRef(null);
    const older = neighborDate(dates, activeDate, 'older');
    const newer = neighborDate(dates, activeDate, 'newer');

    return (
        <div className="flex flex-wrap items-center gap-1.5 sm:gap-2" role="group" aria-label="Navigation dans l'historique des cotations">
            <button type="button" className={BUTTON} onClick={() => older && onSelectDate(older)} disabled={!older || loading}>
                <ChevronLeft className="h-3.5 w-3.5" strokeWidth={2.3} />
                Plus ancienne
            </button>

            <button
                ref={dateButtonRef}
                type="button"
                className={`${BUTTON} bg-[var(--brand-yellow-dark)] text-[var(--color-black)]`}
                aria-haspopup="dialog"
                aria-expanded={calendarOpen}
                onClick={() => setCalendarOpen((open) => !open)}
                disabled={loading}
            >
                <CalendarDays className="h-3.5 w-3.5" strokeWidth={2.3} />
                {formatFrDate(activeDate)}
            </button>

            <button type="button" className={BUTTON} onClick={() => newer && onSelectDate(newer)} disabled={!newer || loading}>
                Plus récente
                <ChevronRight className="h-3.5 w-3.5" strokeWidth={2.3} />
            </button>

            <button
                type="button"
                className={BUTTON}
                onClick={() => {
                    setCalendarOpen(false);
                    onClose();
                }}
            >
                <X className="h-3.5 w-3.5" strokeWidth={2.3} />
                Fermer l'historique
            </button>

            {calendarOpen ? (
                <CerealHistoryCalendar
                    anchorRef={dateButtonRef}
                    availableDates={dates}
                    selectedDate={activeDate}
                    onClose={() => setCalendarOpen(false)}
                    onSelect={(date) => {
                        setCalendarOpen(false);
                        onSelectDate(date);
                    }}
                />
            ) : null}
        </div>
    );
}
