import Modal from '@/Components/Modal';
import {
    HourSheetStatusBadge,
    OvertimeBadge,
    formatHourSheetDate,
} from '@/Components/Hours/HourSheetBadges';
import {
    checkedExtraLabels,
    computeDayTotals,
    dayHoursLabel,
    dayOvertimeLabel,
    formatWorkedDuration,
    leaveCoverage,
} from '@/Support/hoursWorkTime';

/**
 * Détail d'une journée d'heures, ouvert depuis la notification de refus.
 *
 * Le motif du refus est mis en tête : c'est la raison d'être de cet écran, et
 * la seule information que le salarié ne peut pas déduire de sa propre saisie.
 * Le reste rappelle la journée telle qu'il l'a déclarée, avec les mêmes
 * helpers que la carte de l'historique et que la file du valideur — les trois
 * vues doivent dire exactement la même chose.
 *
 * La modale ne propose aucune action : corriger une journée refusée se fait
 * depuis sa carte, avec le bouton « Modifier » déjà en place.
 */
export default function HourSheetDetailModal({ sheet = null, leave = null, onClose = () => {} }) {
    const isRefused = String(sheet?.status || '').toLowerCase() === 'refused';
    const coverage = leaveCoverage(leave);
    const isContinuousDay = Boolean(sheet?.is_continuous_day) && !coverage.morning && !coverage.afternoon;
    const normalizedSheet = sheet ? { ...sheet, is_continuous_day: isContinuousDay } : null;

    const { totalMinutes } = normalizedSheet
        ? computeDayTotals(normalizedSheet, coverage)
        : { totalMinutes: 0 };

    const extras = normalizedSheet ? checkedExtraLabels(normalizedSheet) : [];
    const description = String(sheet?.description || '').trim();

    return (
        <Modal show={sheet !== null} onClose={onClose} maxWidth="lg">
            <div className="space-y-4 p-5 text-sm text-black sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                    <h2 className="min-w-0 basis-full text-lg font-semibold sm:flex-1 sm:basis-0">
                        {formatHourSheetDate(sheet?.work_date)}
                    </h2>
                    <span className="shrink-0">
                        <HourSheetStatusBadge sheet={sheet} />
                    </span>
                </div>

                {isRefused && (
                    <div className="rounded-xl border border-[#ef4444] bg-[#fef2f2] p-3">
                        <p className="font-semibold text-[#b91c1c]">Motif du refus</p>
                        <p className="mt-1 whitespace-pre-line text-[#7f1d1d]">
                            {String(sheet?.refusal_reason || '').trim() || 'Aucun motif n’a été indiqué.'}
                        </p>
                    </div>
                )}

                {sheet?.is_not_worked ? (
                    <dl className="grid gap-2">
                        <DetailRow label="Statut" value="Journée non travaillée" />
                        <DetailRow label="Description" value={description || 'Non renseignée'} />
                    </dl>
                ) : (
                    <dl className="grid gap-2">
                        <DetailRow label="Horaires" value={dayHoursLabel(normalizedSheet, { coverage }) || 'Non renseignés'} />
                        <div className="grid gap-1">
                            <dt className="font-medium text-[var(--app-muted)]">Total heures travaillées</dt>
                            <dd className="flex flex-wrap items-center gap-2">
                                <span>{formatWorkedDuration(totalMinutes)}</span>
                                <OvertimeBadge label={dayOvertimeLabel({
                                    dayState: normalizedSheet,
                                    coverage,
                                    totalMinutes,
                                    workDate: sheet?.work_date,
                                })} />
                            </dd>
                        </div>
                        <DetailRow label="Description" value={description || 'Non renseignée'} />
                        <DetailRow label="Cases cochées" value={extras.join(', ') || 'Aucune'} />
                    </dl>
                )}

                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-[var(--app-border)] px-4 py-2 text-sm font-medium"
                    >
                        Fermer
                    </button>
                </div>
            </div>
        </Modal>
    );
}

function DetailRow({ label, value }) {
    return (
        <div className="grid gap-1">
            <dt className="font-medium text-[var(--app-muted)]">{label}</dt>
            <dd className="whitespace-pre-line">{value}</dd>
        </div>
    );
}
