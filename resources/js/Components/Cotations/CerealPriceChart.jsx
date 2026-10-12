import { buildSegments, enumerateDays, formatFrDate, seriesLabel } from '@/Support/cerealHistory';
import { useMemo, useRef, useState } from 'react';

export const SERIES_COLORS = ['#0F6930', '#B45309', '#1D4ED8', '#B91C1C', '#7C3AED', '#0E7490', '#4D7C0F', '#BE185D'];

const W = 720;
const H = 300;
const M = { left: 58, right: 16, top: 16, bottom: 40 };
const PRICE = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

export const formatChartPrice = (value) => `${PRICE.format(Math.round(value))} €/t`;

function niceTicks(min, max, count = 5) {
    if (min === max) return [min - 1, min, min + 1];
    const step = (max - min) / (count - 1);
    const magnitude = 10 ** Math.floor(Math.log10(step));
    const nice = [1, 2, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= step) || step;
    const start = Math.floor(min / nice) * nice;
    const ticks = [];
    for (let value = start; value <= max + nice; value += nice) ticks.push(value);

    return ticks.slice(0, 9);
}

/**
 * Courbes à points des prix finaux d'une céréale : une série par ligne de cotation,
 * couleur et libellé distincts. Infobulle (survol, focus clavier, clic/toucher),
 * légende, axes datés. Les journées sans cotation interrompent la courbe.
 */
export default function CerealPriceChart({ cereal, from, to }) {
    const days = useMemo(() => enumerateDays(from, to), [from, to]);
    const [active, setActive] = useState(null); // { seriesKey, date, pinned }
    const pointRefs = useRef(new Map());

    const all = cereal.series.flatMap((series) => series.points.map((point) => point.price));
    const hasPoints = all.length > 0;
    const low = hasPoints ? Math.min(...all) : 0;
    const high = hasPoints ? Math.max(...all) : 1;
    const pad = Math.max((high - low) * 0.08, 2);
    const ticks = niceTicks(low - pad, high + pad);
    const yMin = ticks[0];
    const yMax = ticks[ticks.length - 1];

    const x = (date) => {
        const index = days.indexOf(date);
        const span = Math.max(days.length - 1, 1);

        return M.left + ((days.length === 1 ? 0.5 : index / span) * (W - M.left - M.right));
    };
    const y = (price) => M.top + ((yMax - price) / (yMax - yMin || 1)) * (H - M.top - M.bottom);

    const xLabels = useMemo(() => {
        const wanted = Math.min(6, days.length);
        if (wanted <= 1) return days;
        const picked = new Set();
        for (let i = 0; i < wanted; i += 1) picked.add(days[Math.round((i * (days.length - 1)) / (wanted - 1))]);

        return [...picked];
    }, [days]);

    const colorOf = (index) => SERIES_COLORS[index % SERIES_COLORS.length];
    const activeSeries = active ? cereal.series.find((series) => series.key === active.seriesKey) : null;
    const activePoint = activeSeries?.points.find((point) => point.date === active.date) || null;
    const activeColor = activeSeries ? colorOf(cereal.series.indexOf(activeSeries)) : null;

    const moveFocus = (series, index, delta) => {
        const target = series.points[index + delta];
        if (!target) return;
        pointRefs.current.get(`${series.key}|${target.date}`)?.focus();
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 sm:p-4" aria-label={`Évolution des prix — ${cereal.name}`}>
            <h3 className="text-base font-black uppercase tracking-[0.06em] sm:text-lg">{cereal.name}</h3>

            {!hasPoints ? (
                <p className="mt-2 text-sm text-[var(--app-muted)]">Aucune cotation sur cette période.</p>
            ) : (
                <div className="relative mt-2" onMouseLeave={() => setActive((current) => (current?.pinned ? current : null))}>
                    <svg viewBox={`0 0 ${W} ${H}`} className="block h-auto w-full" role="group" aria-label={`Courbes des prix finaux — ${cereal.name}`}>
                        {ticks.map((tick) => (
                            <g key={tick}>
                                <line x1={M.left} x2={W - M.right} y1={y(tick)} y2={y(tick)} stroke="var(--app-border)" strokeWidth="1" strokeDasharray="3 4" />
                                <text x={M.left - 8} y={y(tick) + 4} textAnchor="end" fontSize="11" fill="var(--app-muted)">{PRICE.format(Math.round(tick))}</text>
                            </g>
                        ))}
                        <text x={14} y={M.top + 4} fontSize="10" fill="var(--app-muted)">€/t</text>
                        <line x1={M.left} x2={W - M.right} y1={H - M.bottom} y2={H - M.bottom} stroke="var(--app-border)" />
                        {xLabels.map((day) => (
                            <text key={day} x={x(day)} y={H - M.bottom + 18} textAnchor="middle" fontSize="11" fill="var(--app-muted)">{formatFrDate(day).slice(0, 5)}</text>
                        ))}

                        {cereal.series.map((series, seriesIndex) => (
                            <g key={series.key} data-series={series.key}>
                                {buildSegments(series.points, days).filter((segment) => segment.length > 1).map((segment) => (
                                    <polyline
                                        key={segment[0].date}
                                        fill="none"
                                        stroke={colorOf(seriesIndex)}
                                        strokeWidth="2"
                                        strokeLinejoin="round"
                                        points={segment.map((point) => `${x(point.date)},${y(point.price)}`).join(' ')}
                                    />
                                ))}
                                {series.points.map((point, pointIndex) => {
                                    const isActive = active?.seriesKey === series.key && active?.date === point.date;

                                    return (
                                        <g
                                            key={point.date}
                                            ref={(node) => {
                                                const refKey = `${series.key}|${point.date}`;
                                                if (node) pointRefs.current.set(refKey, node); else pointRefs.current.delete(refKey);
                                            }}
                                            data-point={`${series.key}|${point.date}`}
                                            tabIndex={0}
                                            role="button"
                                            aria-label={`${cereal.name}, ${seriesLabel(series)}, ${formatFrDate(point.date)} : ${formatChartPrice(point.price)}`}
                                            className="cursor-pointer outline-none"
                                            onMouseEnter={() => setActive((current) => (current?.pinned ? current : { seriesKey: series.key, date: point.date, pinned: false }))}
                                            onFocus={() => setActive({ seriesKey: series.key, date: point.date, pinned: false })}
                                            onBlur={() => setActive((current) => (current?.pinned ? current : null))}
                                            onClick={() => setActive((current) => (current?.pinned && current.seriesKey === series.key && current.date === point.date
                                                ? null
                                                : { seriesKey: series.key, date: point.date, pinned: true }))}
                                            onKeyDown={(event) => {
                                                if (event.key === 'ArrowRight') { event.preventDefault(); moveFocus(series, pointIndex, 1); }
                                                if (event.key === 'ArrowLeft') { event.preventDefault(); moveFocus(series, pointIndex, -1); }
                                                if (event.key === 'Enter' || event.key === ' ') {
                                                    event.preventDefault();
                                                    setActive({ seriesKey: series.key, date: point.date, pinned: true });
                                                }
                                                if (event.key === 'Escape') setActive(null);
                                            }}
                                        >
                                            <circle cx={x(point.date)} cy={y(point.price)} r="11" fill="transparent" />
                                            <circle cx={x(point.date)} cy={y(point.price)} r={isActive ? 6 : 4} fill="var(--app-surface)" stroke={colorOf(seriesIndex)} strokeWidth="2.5" />
                                        </g>
                                    );
                                })}
                            </g>
                        ))}
                    </svg>

                    {activePoint ? (
                        <div
                            role="status"
                            data-testid="chart-tooltip"
                            className="pointer-events-none absolute z-10 w-56 max-w-[70%] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 text-xs shadow-lg shadow-black/15"
                            style={{
                                left: `${(x(activePoint.date) / W) * 100}%`,
                                top: `${(y(activePoint.price) / H) * 100}%`,
                                transform: `translate(${x(activePoint.date) > W * 0.6 ? 'calc(-100% - 12px)' : '12px'}, -50%)`,
                            }}
                        >
                            <p className="font-black" style={{ color: activeColor }}>{cereal.name}</p>
                            <p className="font-semibold">{seriesLabel(activeSeries)}</p>
                            <p className="text-[var(--app-muted)]">{formatFrDate(activePoint.date)}</p>
                            <p className="mt-0.5 text-sm font-black">{formatChartPrice(activePoint.price)}</p>
                            {activePoint.carried_quote_at ? (
                                <p className="text-[var(--app-muted)]">Échéance échue : dernier cours du {formatFrDate(activePoint.carried_quote_at)}</p>
                            ) : null}
                        </div>
                    ) : null}
                </div>
            )}

            {hasPoints ? (
                <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label={`Légende — ${cereal.name}`}>
                    {cereal.series.map((series, index) => (
                        <li key={series.key} className="inline-flex items-center gap-1.5">
                            <span aria-hidden="true" className="inline-block h-0.5 w-5" style={{ backgroundColor: colorOf(index) }} />
                            <span className="inline-block h-2 w-2 rounded-full border-2" style={{ borderColor: colorOf(index) }} aria-hidden="true" />
                            <span>{seriesLabel(series)}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}
