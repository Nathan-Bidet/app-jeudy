import { WEEKDAY_LABELS, buildMonthGrid, formatFrDate, monthLabel, parseMonth } from '@/Support/cerealHistory';
import { computePopoverPosition } from '@/Support/popoverPosition';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const WIDTH = 296;

/**
 * Calendrier de sélection d'une journée de l'historique. Seules les dates de
 * `availableDates` sont sélectionnables ; les autres sont désactivées.
 * Menu en portail (position fixe) pour rester entièrement visible.
 * Clavier : Tab entre les jours disponibles, flèches pour passer au jour
 * disponible précédent/suivant du mois, PageUp/PageDown pour changer de mois,
 * Entrée/Espace pour choisir, Échap pour fermer.
 */
export default function CerealHistoryCalendar({ anchorRef, availableDates, selectedDate, onSelect, onClose }) {
    const available = useMemo(() => new Set(availableDates), [availableDates]);
    const bounds = useMemo(() => {
        const sorted = [...availableDates].sort();

        return { min: parseMonth(sorted[0]), max: parseMonth(sorted[sorted.length - 1]) };
    }, [availableDates]);
    const start = parseMonth(selectedDate) || bounds.max || { year: new Date().getFullYear(), monthIndex: new Date().getMonth() };
    const [view, setView] = useState(start);
    const [position, setPosition] = useState(null);
    const panelRef = useRef(null);

    const monthIndexOf = ({ year, monthIndex }) => year * 12 + monthIndex;
    const canGoPrev = bounds.min && monthIndexOf(view) > monthIndexOf(bounds.min);
    const canGoNext = bounds.max && monthIndexOf(view) < monthIndexOf(bounds.max);

    const shiftMonth = (delta) => {
        setView((current) => {
            const total = monthIndexOf(current) + delta;
            const next = { year: Math.floor(total / 12), monthIndex: ((total % 12) + 12) % 12 };
            if (bounds.min && monthIndexOf(next) < monthIndexOf(bounds.min)) return current;
            if (bounds.max && monthIndexOf(next) > monthIndexOf(bounds.max)) return current;

            return next;
        });
    };

    useLayoutEffect(() => {
        const update = () => {
            if (!anchorRef.current || !panelRef.current) return;
            setPosition(computePopoverPosition(
                anchorRef.current.getBoundingClientRect(),
                { width: WIDTH, height: panelRef.current.scrollHeight + 2 },
                { width: window.innerWidth, height: window.innerHeight },
            ));
        };

        update();
        window.addEventListener('resize', update);
        window.addEventListener('scroll', update, true);

        return () => {
            window.removeEventListener('resize', update);
            window.removeEventListener('scroll', update, true);
        };
    }, [anchorRef, view]);

    useEffect(() => {
        const onPointerDown = (event) => {
            if (!panelRef.current?.contains(event.target) && !anchorRef.current?.contains(event.target)) onClose();
        };
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
                anchorRef.current?.focus();
            }
        };
        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('touchstart', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('touchstart', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [anchorRef, onClose]);

    useEffect(() => {
        const target = panelRef.current?.querySelector('[data-selected="true"]')
            || panelRef.current?.querySelector('button[data-day]:not([disabled])');
        target?.focus({ preventScroll: true });
        // Focus à l'ouverture uniquement.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onGridKeyDown = (event) => {
        const days = [...(panelRef.current?.querySelectorAll('button[data-day]:not([disabled])') ?? [])];
        const index = days.indexOf(document.activeElement);
        const step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[event.key];

        if (event.key === 'PageUp' || event.key === 'PageDown') {
            event.preventDefault();
            shiftMonth(event.key === 'PageUp' ? -1 : 1);

            return;
        }
        if (step === undefined || index < 0) return;
        event.preventDefault();
        days[Math.min(Math.max(index + step, 0), days.length - 1)]?.focus();
    };

    const grid = buildMonthGrid(view.year, view.monthIndex);

    return createPortal(
        <div
            ref={panelRef}
            role="dialog"
            aria-label="Choisir une date de l'historique"
            style={{
                position: 'fixed',
                left: position?.left ?? 0,
                top: position?.top ?? 0,
                width: position?.width ?? WIDTH,
                maxHeight: position?.maxHeight,
                visibility: position ? 'visible' : 'hidden',
            }}
            className="z-[120] overflow-y-auto rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 text-[var(--app-text)] shadow-xl shadow-black/15"
        >
            <div className="mb-2 flex items-center justify-between gap-2">
                <button
                    type="button"
                    onClick={() => shiftMonth(-1)}
                    disabled={!canGoPrev}
                    aria-label="Mois précédent"
                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] disabled:opacity-40"
                >
                    <ChevronLeft className="h-4 w-4" strokeWidth={2.3} />
                </button>
                <p className="text-sm font-black" aria-live="polite">{monthLabel(view.year, view.monthIndex)}</p>
                <button
                    type="button"
                    onClick={() => shiftMonth(1)}
                    disabled={!canGoNext}
                    aria-label="Mois suivant"
                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] disabled:opacity-40"
                >
                    <ChevronRight className="h-4 w-4" strokeWidth={2.3} />
                </button>
            </div>

            <div role="grid" aria-label={monthLabel(view.year, view.monthIndex)} onKeyDown={onGridKeyDown}>
                <div role="row" className="grid grid-cols-7 text-center text-[10px] font-black uppercase text-[var(--app-muted)]">
                    {WEEKDAY_LABELS.map((label) => <span key={label} role="columnheader" className="py-1">{label}</span>)}
                </div>
                {grid.map((week, weekIndex) => (
                    <div key={weekIndex} role="row" className="grid grid-cols-7 gap-0.5">
                        {week.map((day, dayIndex) => {
                            if (!day) return <span key={dayIndex} role="gridcell" />;
                            const enabled = available.has(day);
                            const selected = day === selectedDate;

                            return (
                                <span key={day} role="gridcell" aria-selected={selected}>
                                    <button
                                        type="button"
                                        data-day={day}
                                        data-selected={selected ? 'true' : undefined}
                                        disabled={!enabled}
                                        aria-label={enabled ? `${formatFrDate(day)}, version disponible` : `${formatFrDate(day)}, aucun historique`}
                                        aria-current={selected ? 'date' : undefined}
                                        onClick={() => onSelect(day)}
                                        className={`h-9 w-full rounded-lg text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--brand-yellow-dark)] ${selected
                                            ? 'bg-[var(--brand-yellow-dark)] font-black text-[var(--color-black)]'
                                            : enabled
                                                ? 'border border-[var(--brand-yellow-dark)] font-bold hover:bg-[var(--app-surface-soft)]'
                                                : 'cursor-not-allowed text-[var(--app-muted)] opacity-40'}`}
                                    >
                                        {Number(day.slice(8))}
                                    </button>
                                </span>
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>,
        document.body,
    );
}
