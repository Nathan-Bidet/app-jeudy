import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const formatDateFr = (isoDate) => {
    if (!isoDate || typeof isoDate !== 'string') {
        return '-';
    }

    const match = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return match ? `${match[3]}-${match[2]}-${match[1]}` : isoDate;
};

const formatDuration = (totalMinutes) => {
    const minutes = Number(totalMinutes || 0);

    if (minutes <= 0) {
        return '—';
    }

    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`;
};

const formatDecisionDate = (isoDate) => {
    if (!isoDate) {
        return null;
    }

    const parsed = new Date(isoDate);

    return Number.isNaN(parsed.getTime()) ? null : parsed.toLocaleDateString('fr-FR');
};

/**
 * File de validation des heures.
 *
 * N'affiche que les journées qui attendent une décision du lecteur, au niveau
 * qui est le sien : le serveur a déjà fait ce tri, une journée ne peut donc pas
 * apparaître simultanément chez les deux valideurs.
 */
export default function HoursValidationQueue({ rows = [], pendingCount = 0 }) {
    const [openUser, setOpenUser] = useState(null);
    const [refusing, setRefusing] = useState(null);
    const [refusalReason, setRefusalReason] = useState('');
    const [processingId, setProcessingId] = useState(null);

    // Regroupement par personne : un valideur traite « les heures de X », pas
    // une liste de journées mélangées.
    const groups = useMemo(() => {
        const byUser = new Map();

        rows.forEach((row) => {
            const key = row.user_label || '—';

            if (!byUser.has(key)) {
                byUser.set(key, []);
            }

            byUser.get(key).push(row);
        });

        return Array.from(byUser.entries())
            .map(([label, days]) => ({ label, days }))
            .sort((left, right) => left.label.localeCompare(right.label, 'fr', { sensitivity: 'base' }));
    }, [rows]);

    if (rows.length === 0 && pendingCount === 0) {
        return null;
    }

    const approve = (id) => {
        setProcessingId(id);
        router.post(route('hours.approve', id), {}, {
            preserveScroll: true,
            onFinish: () => setProcessingId(null),
        });
    };

    const confirmRefusal = () => {
        if (!refusing) {
            return;
        }

        setProcessingId(refusing.id);
        router.post(route('hours.refuse', refusing.id), { refusal_reason: refusalReason }, {
            preserveScroll: true,
            onFinish: () => {
                setProcessingId(null);
                setRefusing(null);
                setRefusalReason('');
            },
        });
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-5 sm:px-6 sm:py-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-lg font-bold text-[var(--app-text)]">
                    Heures à valider
                    {pendingCount > 0 ? (
                        <span
                            title="Journées en attente de VOTRE validation"
                            className="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-0.5 text-xs font-semibold"
                        >
                            {pendingCount}
                        </span>
                    ) : null}
                </h2>
            </div>

            {groups.length === 0 ? (
                <p className="mt-3 text-sm text-[var(--app-muted)]">Aucune journée à valider.</p>
            ) : (
                <div className="mt-3 space-y-3">
                    {groups.map((group) => {
                        const isOpen = openUser === group.label;

                        return (
                            <div key={group.label} className="rounded-xl border border-[var(--app-border)] p-3">
                                <button
                                    type="button"
                                    onClick={() => setOpenUser(isOpen ? null : group.label)}
                                    className="flex w-full flex-wrap items-center justify-between gap-2 text-left"
                                >
                                    <span className="text-sm font-semibold text-[var(--app-text)]">{group.label}</span>
                                    <span className="text-xs text-[var(--app-muted)]">
                                        {group.days.length} journée{group.days.length > 1 ? 's' : ''} · {isOpen ? 'Masquer' : 'Afficher'}
                                    </span>
                                </button>

                                {isOpen ? (
                                    <div className="mt-3 space-y-2">
                                        {group.days.map((day) => {
                                            const firstDecidedAt = formatDecisionDate(day.validator_1_decided_at);

                                            return (
                                                <div key={day.id} className="rounded-lg border border-[var(--app-border)] p-3">
                                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                        <p className="text-sm font-medium text-[var(--app-text)]">
                                                            {formatDateFr(day.work_date)}
                                                        </p>
                                                        <span className="text-xs text-[var(--app-muted)]">{day.status_label}</span>
                                                    </div>

                                                    <p className="mt-1 text-sm text-[var(--app-text)]">
                                                        {day.is_not_worked
                                                            ? 'Journée non travaillée'
                                                            : `Total : ${formatDuration(day.total_minutes)}`}
                                                    </p>

                                                    {day.description ? (
                                                        <p className="mt-1 text-sm text-[var(--app-muted)]">{day.description}</p>
                                                    ) : null}

                                                    {/* Le second valideur doit constater l'accord du premier. */}
                                                    {day.validation_level === 2 && day.validator_1_label ? (
                                                        <p className="mt-2 border-t border-[var(--app-border)] pt-2 text-xs text-[var(--app-muted)]">
                                                            Validé par {day.validator_1_label}
                                                            {firstDecidedAt ? ` le ${firstDecidedAt}` : ''}
                                                        </p>
                                                    ) : null}

                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            disabled={processingId === day.id}
                                                            onClick={() => approve(day.id)}
                                                            className="w-full rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)] disabled:opacity-60 sm:w-auto"
                                                        >
                                                            {day.validation_level === 2 ? 'Valider (2/2)' : 'Valider (1/2)'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={processingId === day.id}
                                                            onClick={() => {
                                                                setRefusing(day);
                                                                setRefusalReason('');
                                                            }}
                                                            className="w-full rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-red-600 disabled:opacity-60 sm:w-auto"
                                                        >
                                                            Refuser
                                                        </button>
                                                    </div>

                                                    {refusing?.id === day.id ? (
                                                        <div className="mt-3 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                                                            <label
                                                                className="block text-sm font-medium text-[var(--app-text)]"
                                                                htmlFor={`refusal-${day.id}`}
                                                            >
                                                                Motif du refus (facultatif)
                                                            </label>
                                                            <textarea
                                                                id={`refusal-${day.id}`}
                                                                rows={2}
                                                                value={refusalReason}
                                                                onChange={(event) => setRefusalReason(event.target.value)}
                                                                placeholder="Ce qui doit être corrigé…"
                                                                className="mt-1 w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                                                            />
                                                            <div className="mt-2 flex flex-wrap gap-2">
                                                                <button
                                                                    type="button"
                                                                    disabled={processingId === day.id}
                                                                    onClick={confirmRefusal}
                                                                    className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-semibold text-red-600 disabled:opacity-60"
                                                                >
                                                                    Confirmer le refus
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setRefusing(null)}
                                                                    className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                                >
                                                                    Annuler
                                                                </button>
                                                            </div>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
