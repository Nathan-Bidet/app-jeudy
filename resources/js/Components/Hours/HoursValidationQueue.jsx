import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/Components/InputError';
import ValidatorDecisions from '@/Components/Validation/ValidatorDecisions';

// Mêmes règle et message que RefuseHourSheetRequest côté serveur.
const REFUSAL_REASON_REQUIRED = 'Le motif du refus est obligatoire.';
const REFUSAL_REASON_MAX_LENGTH = 2000;

import {
    checkedExtraLabels,
    dayHoursLabel,
    dayOvertimeLabel,
} from '@/Support/hoursWorkTime';

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

/**
 * Badge des heures supplémentaires, identique à celui des cartes de saisie.
 *
 * Le libellé vient de `dayOvertimeLabel()` : la règle des 8 h / 7 h n'est pas
 * réécrite ici, elle est lue au même endroit que côté salarié.
 */
function OvertimeBadge({ label }) {
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
 * File de validation des heures.
 *
 * N'affiche que les journées sur lesquelles le lecteur peut encore se
 * prononcer. Une journée y entre dès sa saisie pour ses deux valideurs, et n'en
 * sort, pour chacun, qu'une fois qu'il a lui-même tranché — aucun des deux
 * n'attend l'autre.
 */
export default function HoursValidationQueue({ rows = [], pendingCount = 0 }) {
    const [openUser, setOpenUser] = useState(null);
    const [refusing, setRefusing] = useState(null);
    const [refusalReason, setRefusalReason] = useState('');
    const [refusalError, setRefusalError] = useState(null);
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

    const openRefusal = (day) => {
        setRefusing(day);
        setRefusalReason('');
        setRefusalError(null);
    };

    const closeRefusal = () => {
        setRefusing(null);
        setRefusalReason('');
        setRefusalError(null);
    };

    // Le motif est obligatoire : une valeur faite uniquement d'espaces, de
    // tabulations ou de retours à la ligne compte comme vide. Le serveur
    // applique la même règle (RefuseHourSheetRequest) ; ce contrôle ne fait
    // qu'éviter un aller-retour.
    const confirmRefusal = () => {
        if (!refusing) {
            return;
        }

        if (refusalReason.trim() === '') {
            setRefusalError(REFUSAL_REASON_REQUIRED);
            return;
        }

        setRefusalError(null);
        setProcessingId(refusing.id);
        router.post(route('hours.refuse', refusing.id), { refusal_reason: refusalReason }, {
            preserveScroll: true,
            // Refus enregistré (ou journée déjà traitée entre-temps) : le
            // formulaire se referme.
            onSuccess: closeRefusal,
            // Erreur de validation : le formulaire reste ouvert, texte compris.
            onError: (errors) => setRefusalError(errors?.refusal_reason || REFUSAL_REASON_REQUIRED),
            onFinish: () => setProcessingId(null),
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
                                            const summary = Array.isArray(day.validation_summary)
                                                ? day.validation_summary
                                                : [];
                                            // La file ne connaît pas les congés des autres salariés ;
                                            // elle n'en a pas besoin : store() enregistre déjà à null les
                                            // demi-journées couvertes, et `omitEmpty` les passe sous
                                            // silence au lieu d'afficher « --:-- ».
                                            const hoursLabel = dayHoursLabel(day, { omitEmpty: true });
                                            const extras = checkedExtraLabels(day);
                                            const overtimeLabel = dayOvertimeLabel({
                                                dayState: day,
                                                totalMinutes: day.total_minutes,
                                                workDate: day.work_date,
                                            });

                                            return (
                                                <div key={day.id} className="rounded-lg border border-[var(--app-border)] p-3">
                                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                        <p className="text-sm font-medium text-[var(--app-text)]">
                                                            {formatDateFr(day.work_date)}
                                                        </p>
                                                        <span className="text-xs text-[var(--app-muted)]">{day.status_label}</span>
                                                    </div>

                                                    {day.is_not_worked ? (
                                                        <p className="mt-1 text-sm text-[var(--app-text)]">Journée non travaillée</p>
                                                    ) : (
                                                        <>
                                                            {hoursLabel ? (
                                                                <p className="mt-1 text-sm text-[var(--app-text)]">
                                                                    <span className="font-semibold">Horaires :</span> {hoursLabel}
                                                                </p>
                                                            ) : null}

                                                            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-[var(--app-text)]">
                                                                <span><span className="font-semibold">Total :</span> {formatDuration(day.total_minutes)}</span>
                                                                <OvertimeBadge label={overtimeLabel} />
                                                            </p>
                                                        </>
                                                    )}

                                                    {day.description ? (
                                                        <p className="mt-1 text-sm text-[var(--app-muted)]">
                                                            <span className="font-semibold">Description :</span> {day.description}
                                                        </p>
                                                    ) : null}

                                                    {day.is_not_worked ? null : (
                                                        <p className="mt-1 text-sm text-[var(--app-muted)]">
                                                            <span className="font-semibold">Cases cochées :</span>{' '}
                                                            {extras.length > 0 ? extras.join(' · ') : 'Aucune'}
                                                        </p>
                                                    )}

                                                    {/* Chaque valideur voit où en est l'autre ; le nom
                                                        n'apparaît qu'avec la permission dédiée. */}
                                                    <ValidatorDecisions
                                                        summary={summary}
                                                        className="mt-2 border-t border-[var(--app-border)] pt-2"
                                                    />

                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            disabled={processingId === day.id}
                                                            onClick={() => approve(day.id)}
                                                            className="w-full rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)] disabled:opacity-60 sm:w-auto"
                                                        >
                                                            Valider
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={processingId === day.id}
                                                            onClick={() => openRefusal(day)}
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
                                                                Motif du refus
                                                                <span className="ml-1 text-[var(--brand-yellow-dark)]" aria-hidden="true">*</span>
                                                            </label>
                                                            <textarea
                                                                id={`refusal-${day.id}`}
                                                                rows={2}
                                                                value={refusalReason}
                                                                onChange={(event) => {
                                                                    setRefusalReason(event.target.value);
                                                                    if (refusalError && event.target.value.trim() !== '') {
                                                                        setRefusalError(null);
                                                                    }
                                                                }}
                                                                placeholder="Indiquez la raison du refus…"
                                                                required
                                                                aria-required="true"
                                                                aria-invalid={refusalError ? 'true' : 'false'}
                                                                aria-describedby={refusalError ? `refusal-error-${day.id}` : undefined}
                                                                maxLength={REFUSAL_REASON_MAX_LENGTH}
                                                                autoFocus
                                                                className={`mt-1 w-full rounded-lg border bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] ${
                                                                    refusalError ? 'border-red-600' : 'border-[var(--app-border)]'
                                                                }`}
                                                            />
                                                            <InputError
                                                                id={`refusal-error-${day.id}`}
                                                                role="alert"
                                                                message={refusalError}
                                                                className="mt-1"
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
                                                                    disabled={processingId === day.id}
                                                                    onClick={closeRefusal}
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
