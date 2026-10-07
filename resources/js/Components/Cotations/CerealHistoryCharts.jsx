import CerealPriceChart from '@/Components/Cotations/CerealPriceChart';
import { CHART_PERIODS, computeRange, formatFrDate, validateCustomRange } from '@/Support/cerealHistory';
import { useEffect, useMemo, useState } from 'react';

const PERIOD_BUTTON = 'rounded-xl border px-2.5 py-1.5 text-[11px] font-black uppercase tracking-[0.06em] sm:text-xs';

/**
 * Section « Graphiques » de l'historique : évolution des prix finaux par céréale.
 * Période par défaut : 30 derniers jours se terminant à la date consultée. Les
 * périodes prédéfinies suivent la date consultée ; la période personnalisée garde
 * sa plage (validée avant l'appel). Les données viennent du serveur (historique
 * existant) : rien n'est calculé ni copié ici.
 */
export default function CerealHistoryCharts({ seriesUrl, endDate }) {
    const [period, setPeriod] = useState('30d');
    const [customFrom, setCustomFrom] = useState('');
    const [customTo, setCustomTo] = useState('');
    const [appliedCustom, setAppliedCustom] = useState(null);
    const [customError, setCustomError] = useState('');
    const [state, setState] = useState({ loading: false, error: '', data: null });

    const range = useMemo(
        () => (period === 'custom' ? appliedCustom : computeRange(period, endDate)),
        [period, appliedCustom, endDate],
    );

    useEffect(() => {
        if (!range || !seriesUrl) return undefined;

        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true, error: '' }));

        fetch(`${seriesUrl}?${new URLSearchParams({ from: range.from, to: range.to })}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                const body = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const first = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
                    throw new Error(first || body?.message || 'Impossible de charger les graphiques.');
                }

                return body;
            })
            .then((data) => setState({ loading: false, error: '', data }))
            .catch((error) => {
                if (error?.name === 'AbortError') return;
                setState({ loading: false, error: error?.message || 'Impossible de charger les graphiques.', data: null });
            });

        return () => controller.abort();
    }, [range?.from, range?.to, seriesUrl]);

    const applyCustom = (event) => {
        event.preventDefault();
        const message = validateCustomRange(customFrom, customTo);
        setCustomError(message);
        if (!message) setAppliedCustom({ from: customFrom, to: customTo });
    };

    const data = state.data;

    return (
        <section aria-label="Graphiques d'évolution des prix" data-testid="cereal-history-charts" className="space-y-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 sm:p-4">
            <div role="group" aria-label="Période" className="flex flex-wrap items-center gap-1.5 sm:gap-2">
                {CHART_PERIODS.map((item) => (
                    <button
                        key={item.id}
                        type="button"
                        aria-pressed={period === item.id}
                        onClick={() => setPeriod(item.id)}
                        className={`${PERIOD_BUTTON} ${period === item.id
                            ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                            : 'border-[var(--app-border)] bg-[var(--app-surface)]'}`}
                    >
                        {item.label}
                    </button>
                ))}
            </div>

            {period === 'custom' ? (
                <form onSubmit={applyCustom} className="flex flex-wrap items-end gap-2" aria-label="Période personnalisée">
                    <label className="text-xs font-semibold">
                        Du
                        <input type="date" value={customFrom} onChange={(event) => setCustomFrom(event.target.value)} className="mt-1 block rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-sm" />
                    </label>
                    <label className="text-xs font-semibold">
                        Au
                        <input type="date" value={customTo} onChange={(event) => setCustomTo(event.target.value)} className="mt-1 block rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-sm" />
                    </label>
                    <button type="submit" className={`${PERIOD_BUTTON} border-[var(--app-border)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]`}>
                        Valider
                    </button>
                    {customError ? <p role="alert" className="basis-full text-xs font-semibold text-red-700">{customError}</p> : null}
                </form>
            ) : null}

            {period === 'custom' && !range ? (
                <p className="text-sm text-[var(--app-muted)]">Choisissez une date de début et une date de fin, puis validez.</p>
            ) : null}

            {state.error ? <p role="alert" className="text-sm font-semibold text-red-700">{state.error}</p> : null}
            {state.loading ? <p role="status" className="text-sm text-[var(--app-muted)]">Chargement des graphiques...</p> : null}

            {range && data && !state.loading ? (
                <p className="text-xs text-[var(--app-muted)]" data-testid="chart-range">
                    Du {formatFrDate(data.from)} au {formatFrDate(data.to)} — {data.dates.length} journée(s) avec cotation (relevé de 15 h).
                    Une courbe s'interrompt lorsqu'aucune cotation n'existe pour une journée.
                </p>
            ) : null}

            {range && data && !state.loading ? (
                data.cereals.length === 0 ? (
                    <p className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 text-sm text-[var(--app-muted)]">
                        Aucune cotation sur cette période.
                    </p>
                ) : (
                    <div className="grid gap-3 xl:grid-cols-2">
                        {data.cereals.map((cereal) => (
                            <CerealPriceChart key={cereal.code} cereal={cereal} from={data.from} to={data.to} />
                        ))}
                    </div>
                )
            ) : null}
        </section>
    );
}
