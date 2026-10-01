import { formatFrDate } from '@/Support/cerealHistory';

const PRICE = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function formatTime(value) {
    if (!value) return '';
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(date);
}

/**
 * Cotations d'une journée reconstituées depuis l'historique de marché existant
 * (dernier relevé de la journée pour chaque contrat). Lecture seule.
 * Les données absentes de l'historique en base sont listées explicitement :
 * jamais de valeur recomposée à partir des réglages actuels.
 */
export default function CerealHistoryDay({ day }) {
    const groups = day?.groups || [];

    return (
        <div className="space-y-4" data-testid="cereal-history-day">
            {groups.length === 0 ? (
                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)]">
                    Aucune cotation enregistrée pour le {formatFrDate(day?.business_date)}.
                </div>
            ) : groups.map((group) => (
                <section key={group.code} className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                    <h2 className="text-lg font-black uppercase tracking-[0.06em]">{group.name}</h2>
                    <div className="mt-3 grid gap-4 md:grid-cols-2">
                        {group.harvests.map((harvest) => (
                            <div key={harvest.year} className="min-w-0">
                                <p className="mb-1 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Récolte {harvest.year}</p>
                                <table className="w-full table-fixed text-[12px] sm:text-sm">
                                    <thead>
                                        <tr className="text-left text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                            <th className="py-1 pr-2">Échéance</th>
                                            <th className="py-1 pr-2 text-right">MATIF</th>
                                            <th className="py-1 text-right">Relevé</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {harvest.rows.map((row) => (
                                            <tr key={`${row.label}-${row.maturity_year}`} className="border-t border-[var(--app-border)]">
                                                <td className="py-1.5 pr-2 font-semibold">{row.label} {row.maturity_year}</td>
                                                <td className="py-1.5 pr-2 text-right font-black">{PRICE.format(row.price)}</td>
                                                <td className="py-1.5 text-right text-[var(--app-muted)]">{formatTime(row.recorded_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}
                    </div>
                </section>
            ))}

            {(day?.not_historized || []).length > 0 ? (
                <div role="note" className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-3 text-xs text-[var(--app-muted)]">
                    <p className="font-black uppercase tracking-[0.08em]">Non conservé dans l'historique</p>
                    <p className="mt-1">Cette consultation restitue les cours MATIF enregistrés. Ne sont pas historisés :</p>
                    <ul className="mt-1 list-disc pl-5">
                        {day.not_historized.map((item) => <li key={item}>{item}</li>)}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
