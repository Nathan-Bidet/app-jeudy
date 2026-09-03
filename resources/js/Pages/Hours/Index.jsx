import HoursValidationQueue from '@/Components/Hours/HoursValidationQueue';
import AppLayout from '@/Layouts/AppLayout';
import TitleCaps from '@/Layouts/AppShell/TitleCaps';
import Modal from '@/Components/Modal';
import { Head, router, usePage } from '@inertiajs/react';
import {
    computeDayTotals,
    dayOvertimeLabel,
    defaultDayState,
    formatWorkedDuration,
    leaveCoverage,
    nonWorkedDayState,
    normalizeTimeForSelect,
} from '@/Support/hoursWorkTime';
import { useMemo, useState } from 'react';

const DAY_NAMES = [
    'Lundi',
    'Mardi',
    'Mercredi',
    'Jeudi',
    'Vendredi',
    'Samedi',
    'Dimanche',
];

const MONTH_NAMES = [
    'janvier',
    'fevrier',
    'mars',
    'avril',
    'mai',
    'juin',
    'juillet',
    'aout',
    'septembre',
    'octobre',
    'novembre',
    'decembre',
];

const TIME_FIELDS = [
    { key: 'morning_start', label: 'Début matin' },
    { key: 'morning_end', label: 'Fin matin' },
    { key: 'afternoon_start', label: 'Début soir' },
    { key: 'afternoon_end', label: 'Fin soir' },
];
const MORNING_TIME_FIELDS = TIME_FIELDS.slice(0, 2);
const AFTERNOON_TIME_FIELDS = TIME_FIELDS.slice(2);
// Mode "journée continue / demi-journée" : une seule plage, réutilisant les
// mêmes clés que morning_start/afternoon_end pour rester compatible avec la
// sauvegarde (morning_end et afternoon_start restent vides côté serveur).
const CONTINUOUS_TIME_FIELDS = [
    { key: 'morning_start', label: 'Début' },
    { key: 'afternoon_end', label: 'Fin' },
];

const CHECKBOX_FIELDS = [
    { key: 'has_breakfast_before_5', label: 'Casse-croûte (Avant 5h)' },
    { key: 'has_lunch', label: 'Déjeuner' },
    { key: 'has_dinner_after_21', label: 'Dîner (Après 21h)' },
    { key: 'has_long_night', label: 'Nuit (Déplacement long)' },
];

function toMonday(date) {
    const copy = new Date(date);
    const jsDay = copy.getDay();
    const delta = jsDay === 0 ? -6 : 1 - jsDay;
    copy.setDate(copy.getDate() + delta);
    copy.setHours(0, 0, 0, 0);
    return copy;
}

function isoDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatDayLabel(date) {
    const dayIndex = (date.getDay() + 6) % 7;
    return `${DAY_NAMES[dayIndex]} ${date.getDate()} ${MONTH_NAMES[date.getMonth()]}`;
}

function formatHistoryDate(workDate) {
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

function formatHistoryTime(timeValue) {
    const source = String(timeValue || '').trim();
    if (!source) {
        return '--:--';
    }

    const [hours, minutes] = source.split(':');
    if (hours === undefined || minutes === undefined) {
        return '--:--';
    }

    const safeHours = String(hours).padStart(2, '0').slice(-2);
    const safeMinutes = String(minutes).padStart(2, '0').slice(-2);
    return `${safeHours}:${safeMinutes}`;
}

/**
 * Badge d'état d'une journée, destiné au salarié.
 *
 * Il résume le circuit sans dire lequel des deux valideurs manque : « En
 * validation » tant que les deux accords ne sont pas réunis. Une journée
 * saisie avant la mise en place de la validation n'a pas d'état de circuit et
 * le dit telle quelle.
 */
/**
 * Badge des heures supplémentaires d'une journée.
 *
 * Rendu seulement quand il y a un dépassement : une journée conforme ou plus
 * courte que la normale n'affiche rien. Le libellé vient de
 * `dayOvertimeLabel()`, unique source du calcul.
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

function HourSheetStatusBadge({ sheet }) {
    const status = String(sheet?.status || '').toLowerCase();

    const { label, dot, className } = (() => {
        if (status === 'approved') {
            return {
                label: 'Validée',
                dot: '#22c55e',
                className: 'border-[#22c55e] text-[#15803d]',
            };
        }

        if (status === 'refused') {
            return {
                label: 'Refusée',
                dot: '#ef4444',
                className: 'border-[#ef4444] text-[#b91c1c]',
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
        <span className={`inline-flex items-center gap-1.5 rounded-full border bg-white px-2.5 py-0.5 text-xs font-semibold ${className}`}>
            <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: dot }} aria-hidden="true" />
            {label}
        </span>
    );
}

export default function HoursIndex({
    hourSheets = [],
    approvedLeaveDays = {},
    minVisibleDate = '2026-04-26',
    canCreate = false,
    canExport = false,
    hourSheetsToValidate = [],
    pendingValidationCount = 0,
}) {
    const { flash = {}, errors = {} } = usePage().props;
    const flashError = flash?.error || null;
    const shouldHideUnauthorizedFlash = !canCreate
        && String(flashError || '').toLowerCase().includes('action non autorisée');

    const timeOptions = useMemo(() => {
        const options = [];

        for (let hour = 0; hour < 24; hour += 1) {
            for (const minute of [0, 15, 30, 45]) {
                options.push(`${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`);
            }
        }

        return options;
    }, []);

    const weekDays = useMemo(() => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const minDate = new Date(`${minVisibleDate}T00:00:00`);
        const days = [];
        let cursor = new Date(minDate);

        while (cursor <= today) {
            const dayIndex = (cursor.getDay() + 6) % 7; // lundi=0 ... dimanche=6
            const date = new Date(cursor);

            days.push({
                id: `day-${isoDate(date)}`,
                dayIndex,
                work_date: isoDate(date),
                label: formatDayLabel(date),
            });

            cursor.setDate(cursor.getDate() + 1);
        }

        return days;
    }, [minVisibleDate]);
    const todayIso = useMemo(() => isoDate(new Date()), []);
    const lastWeekendDates = useMemo(() => {
        const minDate = new Date(`${minVisibleDate}T00:00:00`);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        let weekendAnchor = null;
        const cursor = new Date(today);

        while (cursor >= minDate) {
            const day = cursor.getDay(); // 0=sunday, 6=saturday
            if (day === 0 || day === 6) {
                weekendAnchor = new Date(cursor);
                break;
            }
            cursor.setDate(cursor.getDate() - 1);
        }

        const allowed = new Set();
        if (!weekendAnchor) {
            return allowed;
        }

        const saturday = new Date(weekendAnchor);
        const sunday = new Date(weekendAnchor);
        if (weekendAnchor.getDay() === 0) {
            saturday.setDate(weekendAnchor.getDate() - 1);
        } else {
            sunday.setDate(weekendAnchor.getDate() + 1);
        }

        const saturdayIso = isoDate(saturday);
        const sundayIso = isoDate(sunday);

        if (saturdayIso >= minVisibleDate && saturdayIso <= todayIso) {
            allowed.add(saturdayIso);
        }
        if (sundayIso >= minVisibleDate && sundayIso <= todayIso) {
            allowed.add(sundayIso);
        }

        return allowed;
    }, [todayIso, minVisibleDate]);

    const sheetsByDate = useMemo(() => {
        const map = {};
        for (const sheet of hourSheets) {
            if (sheet?.work_date) {
                map[sheet.work_date] = sheet;
            }
        }

        return map;
    }, [hourSheets]);
    const approvedLeavesByDate = useMemo(() => {
        const map = {};
        Object.entries(approvedLeaveDays || {}).forEach(([dateKey, leaveInfo]) => {
            if (dateKey) {
                map[dateKey] = leaveInfo || {};
            }
        });

        return map;
    }, [approvedLeaveDays]);

    const [formState, setFormState] = useState(() => {
        const initial = {};

        for (const day of weekDays) {
            const existing = sheetsByDate[day.work_date];
            initial[day.id] = existing ? {
                morning_start: normalizeTimeForSelect(existing.morning_start),
                morning_end: normalizeTimeForSelect(existing.morning_end),
                afternoon_start: normalizeTimeForSelect(existing.afternoon_start),
                afternoon_end: normalizeTimeForSelect(existing.afternoon_end),
                description: existing.description || '',
                is_not_worked: Boolean(existing.is_not_worked),
                is_continuous_day: Boolean(existing.is_continuous_day),
                has_breakfast_before_5: Boolean(existing.has_breakfast_before_5),
                has_lunch: Boolean(existing.has_lunch),
                has_dinner_after_21: Boolean(existing.has_dinner_after_21),
                has_long_night: Boolean(existing.has_long_night),
            } : defaultDayState({ isFriday: day.dayIndex === 4 });
        }

        return initial;
    });

    const [historyOpen, setHistoryOpen] = useState(false);
    const [showWeekend, setShowWeekend] = useState(false);
    const [exportModalOpen, setExportModalOpen] = useState(false);
    const [exportError, setExportError] = useState('');
    const [savingDates, setSavingDates] = useState(() => new Set());
    const [inlineEditingByDate, setInlineEditingByDate] = useState({});
    const [exportRange, setExportRange] = useState(() => {
        const now = new Date();
        const today = isoDate(now);
        const firstDay = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;

        return {
            start_date: firstDay,
            end_date: today,
        };
    });

    const onExport = () => {
        const startDate = String(exportRange.start_date || '').trim();
        const endDate = String(exportRange.end_date || '').trim();

        if (!startDate) {
            setExportError('La date de début est requise.');
            return;
        }

        if (!endDate) {
            setExportError('La date de fin est requise.');
            return;
        }

        if (endDate < startDate) {
            setExportError('La date de fin doit être postérieure ou égale à la date de début.');
            return;
        }

        setExportError('');

        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
        });

        window.location.href = `/activities/hours/export?${params.toString()}`;
    };

    const visibleWeekDays = weekDays.filter((day) => {
        if (day.work_date < minVisibleDate || day.work_date > todayIso) {
            return false;
        }

        const isWeekend = day.dayIndex >= 5;
        if (isWeekend && !showWeekend) {
            return false;
        }
        if (isWeekend && showWeekend && !lastWeekendDates.has(day.work_date)) {
            return false;
        }

        const hasHourSheet = Boolean(sheetsByDate[day.work_date]);
        const approvedLeave = approvedLeavesByDate[day.work_date];
        const hasFullDayLeave = leaveCoverage(approvedLeave).fullDay;
        const isPastDay = day.work_date < todayIso;

        if (hasFullDayLeave && isPastDay) {
            return false;
        }

        return !hasHourSheet;
    });

    const onTimeChange = (dayId, field, value) => {
        setFormState((prev) => ({
            ...prev,
            [dayId]: {
                ...prev[dayId],
                [field]: value,
            },
        }));
    };

    const onToggleCheck = (dayId, field) => {
        setFormState((prev) => ({
            ...prev,
            [dayId]: {
                ...prev[dayId],
                [field]: !prev[dayId][field],
            },
        }));
    };

    const startInlineEdit = (workDate) => {
        const existing = sheetsByDate[workDate];
        if (!existing) {
            return;
        }

        setInlineEditingByDate((prev) => ({
            ...prev,
            [workDate]: {
                morning_start: normalizeTimeForSelect(existing.morning_start),
                morning_end: normalizeTimeForSelect(existing.morning_end),
                afternoon_start: normalizeTimeForSelect(existing.afternoon_start),
                afternoon_end: normalizeTimeForSelect(existing.afternoon_end),
                description: existing.description || '',
                is_not_worked: Boolean(existing.is_not_worked),
                is_continuous_day: Boolean(existing.is_continuous_day),
                has_breakfast_before_5: Boolean(existing.has_breakfast_before_5),
                has_lunch: Boolean(existing.has_lunch),
                has_dinner_after_21: Boolean(existing.has_dinner_after_21),
                has_long_night: Boolean(existing.has_long_night),
            },
        }));
    };

    const cancelInlineEdit = (workDate) => {
        setInlineEditingByDate((prev) => {
            const next = { ...prev };
            delete next[workDate];
            return next;
        });
    };

    const onInlineTimeChange = (workDate, field, value) => {
        setInlineEditingByDate((prev) => ({
            ...prev,
            [workDate]: {
                ...prev[workDate],
                [field]: value,
            },
        }));
    };

    const onInlineDescriptionChange = (workDate, value) => {
        setInlineEditingByDate((prev) => ({
            ...prev,
            [workDate]: {
                ...prev[workDate],
                description: value,
            },
        }));
    };

    const onInlineToggleCheck = (workDate, field) => {
        setInlineEditingByDate((prev) => ({
            ...prev,
            [workDate]: {
                ...prev[workDate],
                [field]: !prev[workDate][field],
            },
        }));
    };

    const saveDay = (day) => {
        const dayState = formState[day.id];
        if (!dayState) {
            return;
        }
        const coverage = leaveCoverage(approvedLeavesByDate[day.work_date]);
        if (!dayState.is_not_worked && !String(dayState.description || '').trim()) {
            return;
        }

        setSavingDates((prev) => {
            const next = new Set(prev);
            next.add(day.work_date);
            return next;
        });

        const isContinuousDay = Boolean(dayState.is_continuous_day) && !coverage.morning && !coverage.afternoon;

        router.post(route('hours.store'), {
            work_date: day.work_date,
            is_not_worked: Boolean(dayState.is_not_worked),
            is_continuous_day: isContinuousDay,
            morning_start: coverage.morning ? null : (dayState.morning_start || null),
            morning_end: (coverage.morning || isContinuousDay) ? null : (dayState.morning_end || null),
            afternoon_start: (coverage.afternoon || isContinuousDay) ? null : (dayState.afternoon_start || null),
            afternoon_end: coverage.afternoon ? null : (dayState.afternoon_end || null),
            description: dayState.description || null,
            has_breakfast_before_5: Boolean(dayState.has_breakfast_before_5),
            has_lunch: Boolean(dayState.has_lunch),
            has_dinner_after_21: Boolean(dayState.has_dinner_after_21),
            has_long_night: Boolean(dayState.has_long_night),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setHistoryOpen(true);
            },
            onFinish: () => {
                setSavingDates((prev) => {
                    const next = new Set(prev);
                    next.delete(day.work_date);
                    return next;
                });
            },
        });
    };

    const markDayAsNotWorked = (day) => {
        setFormState((prev) => ({
            ...prev,
            [day.id]: nonWorkedDayState(),
        }));

        setSavingDates((prev) => {
            const next = new Set(prev);
            next.add(day.work_date);
            return next;
        });

        router.post(route('hours.store'), {
            work_date: day.work_date,
            is_not_worked: true,
            is_continuous_day: false,
            morning_start: null,
            morning_end: null,
            afternoon_start: null,
            afternoon_end: null,
            description: null,
            has_breakfast_before_5: false,
            has_lunch: false,
            has_dinner_after_21: false,
            has_long_night: false,
        }, {
            preserveScroll: true,
            onFinish: () => {
                setSavingDates((prev) => {
                    const next = new Set(prev);
                    next.delete(day.work_date);
                    return next;
                });
            },
        });
    };

    const saveInlineDay = (workDate) => {
        const dayState = inlineEditingByDate[workDate];
        if (!dayState) {
            return;
        }
        const coverage = leaveCoverage(approvedLeavesByDate[workDate]);
        if (!dayState.is_not_worked && !String(dayState.description || '').trim()) {
            return;
        }

        const { errors } = computeDayTotals(dayState, coverage);
        if (errors.length > 0) {
            return;
        }

        setSavingDates((prev) => {
            const next = new Set(prev);
            next.add(workDate);
            return next;
        });

        const isContinuousDay = Boolean(dayState.is_continuous_day) && !coverage.morning && !coverage.afternoon;

        router.post(route('hours.store'), {
            work_date: workDate,
            is_not_worked: Boolean(dayState.is_not_worked),
            is_continuous_day: isContinuousDay,
            morning_start: coverage.morning ? null : (dayState.morning_start || null),
            morning_end: (coverage.morning || isContinuousDay) ? null : (dayState.morning_end || null),
            afternoon_start: (coverage.afternoon || isContinuousDay) ? null : (dayState.afternoon_start || null),
            afternoon_end: coverage.afternoon ? null : (dayState.afternoon_end || null),
            description: dayState.description || null,
            has_breakfast_before_5: Boolean(dayState.has_breakfast_before_5),
            has_lunch: Boolean(dayState.has_lunch),
            has_dinner_after_21: Boolean(dayState.has_dinner_after_21),
            has_long_night: Boolean(dayState.has_long_night),
        }, {
            preserveScroll: true,
            onFinish: () => {
                setSavingDates((prev) => {
                    const next = new Set(prev);
                    next.delete(workDate);
                    return next;
                });
                cancelInlineEdit(workDate);
            },
        });
    };

    const historyEntries = useMemo(() => {
        const dates = new Set([
            ...Object.keys(sheetsByDate),
            ...Object.keys(approvedLeavesByDate),
        ]);
        const entries = Array.from(dates).map((date) => {
            const sheet = sheetsByDate[date] || null;
            const leave = approvedLeavesByDate[date] || null;

            return {
                type: sheet && !leaveCoverage(leave).fullDay ? 'sheet' : 'leave',
                date,
                key: `history-${date}`,
                sheet,
                leave,
            };
        });

        entries.sort((a, b) => String(b.date).localeCompare(String(a.date)));
        return entries;
    }, [sheetsByDate, approvedLeavesByDate]);

    /**
     * Une journée est « en validation » tant que son circuit n'est pas clos.
     *
     * `pending_validator_2` est l'ancien état du circuit séquentiel : la
     * migration l'a ramené sur `pending`, il n'est testé ici que par prudence.
     * Une journée sans statut est antérieure au circuit de validation : elle
     * n'a rien à faire dans les demandes en cours et rejoint l'historique.
     */
    const isAwaitingValidation = (sheet) => (
        ['pending', 'pending_validator_2'].includes(String(sheet?.status || '').toLowerCase())
    );

    // Les deux sections partagent le tri de `historyEntries` : du plus récent
    // au plus ancien.
    const pendingEntries = useMemo(
        () => historyEntries.filter((entry) => entry.type === 'sheet' && isAwaitingValidation(entry.sheet)),
        [historyEntries],
    );
    const settledEntries = useMemo(
        () => historyEntries.filter((entry) => !(entry.type === 'sheet' && isAwaitingValidation(entry.sheet))),
        [historyEntries],
    );

    /**
     * Rendu d'une journée : carte en lecture, formulaire quand la journée est
     * en cours d'édition, ou mention de congé.
     *
     * Extrait du bloc « Historique » pour être partagé avec la section
     * « Mes heures en validation » : les deux sections affichent exactement la
     * même carte, seule la liste qui les alimente diffère.
     */
    const renderDayEntry = (entry) => {
        const sheet = entry.sheet;
        return (
        <article key={entry.key} className="rounded-xl border border-[var(--app-border)] bg-white p-3 text-sm text-black">
            {entry.type === 'leave' ? (
                <div>
                    <p className="font-semibold">Date : {formatHistoryDate(entry.date)}</p>
                    <p>Statut : En congé</p>
                    <p>{entry.leave?.message || 'Congé validé'}</p>
                </div>
            ) : (canCreate && inlineEditingByDate[sheet.work_date] ? (() => {
                const dayState = inlineEditingByDate[sheet.work_date];
                const isNotWorked = Boolean(dayState.is_not_worked);
                const coverage = leaveCoverage(approvedLeavesByDate[sheet.work_date]);
                const canUseContinuousDay = !coverage.morning && !coverage.afternoon;
                const isContinuousDay = canUseContinuousDay && Boolean(dayState.is_continuous_day);
                const { totalMinutes: totalWorkedMinutes, errors: inlineRangeErrors } = computeDayTotals(
                    { ...dayState, is_continuous_day: isContinuousDay },
                    coverage,
                );
                const errorMessages = isNotWorked ? [] : inlineRangeErrors;
                const descriptionMissing = !isNotWorked && !String(dayState.description || '').trim();
                const isSaving = savingDates.has(sheet.work_date);

                        return (
                    <div>
                        <p className="text-center font-semibold uppercase">{formatHistoryDate(sheet.work_date)}</p>

                        <div className="mt-3">
                            <button
                                type="button"
                                onClick={() => setInlineEditingByDate((prev) => ({
                                    ...prev,
                                    [sheet.work_date]: prev[sheet.work_date]?.is_not_worked
                                        ? defaultDayState()
                                        : nonWorkedDayState(),
                                }))}
                                className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-xs font-semibold"
                            >
                                {isNotWorked ? 'Revenir en mode normal' : 'Non travaillé'}
                            </button>
                        </div>

                        {canUseContinuousDay && (
                            <button
                                type="button"
                                onClick={() => onInlineToggleCheck(sheet.work_date, 'is_continuous_day')}
                                disabled={isNotWorked}
                                className="mt-3 flex items-center justify-center gap-3 text-left"
                            >
                                <span
                                    className="flex h-5 w-5 items-center justify-center rounded-sm border border-[var(--app-border)] bg-white text-xs"
                                    aria-hidden="true"
                                >
                                    {dayState.is_continuous_day ? '✔️' : ''}
                                </span>
                                <span className="text-sm">Journée continue / Demi-journée</span>
                            </button>
                        )}

                        {isNotWorked && (
                            <p className="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-center text-sm font-semibold text-gray-700">
                                Journée marquée comme non travaillée
                            </p>
                        )}

                        <div className="mt-4 grid gap-3">
                            {isContinuousDay ? (
                                CONTINUOUS_TIME_FIELDS.map((field) => (
                                    <label key={field.key} className="grid gap-1 text-sm">
                                        <span className="font-medium">{field.label}</span>
                                        <select
                                            value={dayState[field.key]}
                                            onChange={(event) => onInlineTimeChange(sheet.work_date, field.key, event.target.value)}
                                            disabled={isNotWorked}
                                            className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                                        >
                                            <option value="">--:--</option>
                                            {timeOptions.map((time) => (
                                                <option key={time} value={time}>
                                                    {time}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                ))
                            ) : (
                            <>
                            {coverage.morning ? (
                                <p className="rounded-lg bg-gray-100 px-3 py-2 text-center text-sm font-semibold text-gray-700">
                                    Vous êtes en congé ce matin.
                                </p>
                            ) : MORNING_TIME_FIELDS.map((field) => (
                                <label key={field.key} className="grid gap-1 text-sm">
                                    <span className="font-medium">{field.label}</span>
                                    <select
                                        value={dayState[field.key]}
                                        onChange={(event) => onInlineTimeChange(sheet.work_date, field.key, event.target.value)}
                                        disabled={isNotWorked}
                                        className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                                    >
                                        <option value="">--:--</option>
                                        {timeOptions.map((time) => (
                                            <option key={time} value={time}>
                                                {time}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ))}
                            {coverage.afternoon ? (
                                <p className="rounded-lg bg-gray-100 px-3 py-2 text-center text-sm font-semibold text-gray-700">
                                    Vous êtes en congé cet après-midi.
                                </p>
                            ) : AFTERNOON_TIME_FIELDS.map((field) => (
                                <label key={field.key} className="grid gap-1 text-sm">
                                    <span className="font-medium">{field.label}</span>
                                    <select
                                        value={dayState[field.key]}
                                        onChange={(event) => onInlineTimeChange(sheet.work_date, field.key, event.target.value)}
                                        disabled={isNotWorked}
                                        className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                                    >
                                        <option value="">--:--</option>
                                        {timeOptions.map((time) => (
                                            <option key={time} value={time}>
                                                {time}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ))}
                            </>
                            )}
                        </div>

                        <div className="mt-4 text-center">
                            <p className="text-sm">Total heures travaillées</p>
                            <div className="flex flex-wrap items-center justify-center gap-2">
                                <p className="text-lg font-semibold">{formatWorkedDuration(totalWorkedMinutes)}</p>
                                <OvertimeBadge label={dayOvertimeLabel({
                                    dayState,
                                    coverage,
                                    totalMinutes: totalWorkedMinutes,
                                    workDate: sheet.work_date,
                                })} />
                            </div>
                        </div>

                        {errorMessages.length > 0 && (
                            <div className="mt-2 space-y-1">
                                {errorMessages.map((message) => (
                                    <p key={message} className="text-xs text-red-600">
                                        {message}
                                    </p>
                                ))}
                            </div>
                        )}

                        <div className="mt-4 grid gap-2">
                            {CHECKBOX_FIELDS.map((field) => {
                                const checked = dayState[field.key];

                                return (
                                    <button
                                        key={field.key}
                                        type="button"
                                        onClick={() => onInlineToggleCheck(sheet.work_date, field.key)}
                                        disabled={isNotWorked}
                                        className="flex items-center gap-3 text-left"
                                    >
                                        <span
                                            className="flex h-5 w-5 items-center justify-center rounded-sm border border-[var(--app-border)] bg-white text-xs"
                                            aria-hidden="true"
                                        >
                                            {checked ? '✔️' : ''}
                                        </span>
                                        <span className="text-sm">{field.label}</span>
                                    </button>
                                );
                            })}
                        </div>

                        <label className="mt-4 grid gap-1 text-sm">
                            <span className="font-medium">Description des travaux réalisés</span>
                            <textarea
                                rows={4}
                                maxLength={5000}
                                value={dayState.description || ''}
                                onChange={(event) => onInlineDescriptionChange(sheet.work_date, event.target.value)}
                                disabled={isNotWorked}
                                placeholder="Décrivez les travaux effectués durant la journée..."
                                className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)] disabled:bg-gray-100"
                            />
                            {descriptionMissing && (
                                <span className="text-xs text-red-600">
                                    La description des travaux réalisés est obligatoire.
                                </span>
                            )}
                            {errors.description && (
                                <span className="text-xs text-red-600">{errors.description}</span>
                            )}
                        </label>

                        <div className="mt-4 flex gap-2">
                            <button
                                type="button"
                                onClick={() => saveInlineDay(sheet.work_date)}
                                disabled={isSaving || errorMessages.length > 0 || descriptionMissing}
                                className="rounded-xl bg-[var(--brand-yellow)] px-4 py-2 text-sm font-semibold text-black disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {isSaving ? 'Enregistrement...' : 'Enregistrer'}
                            </button>
                            <button
                                type="button"
                                onClick={() => cancelInlineEdit(sheet.work_date)}
                                disabled={isSaving}
                                className="rounded-xl border border-[var(--app-border)] px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Annuler
                            </button>
                        </div>
                    </div>
                );
            })() : (
                <div>
                    {/* En-tête : la date à gauche, le badge d'état à droite.
                        L'état est global — le salarié n'a pas à savoir lequel
                        des deux valideurs manque. `status` à null signale une
                        saisie antérieure à la mise en place du circuit. */}
                    <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                        <p className="min-w-0 basis-full font-semibold sm:flex-1 sm:basis-0">
                            Date : {formatHistoryDate(sheet.work_date)}
                        </p>
                        <span className="shrink-0">
                            <HourSheetStatusBadge sheet={sheet} />
                        </span>
                    </div>
                    {sheet.status === 'refused' && sheet.refusal_reason ? (
                        <p className="text-red-700">Motif du refus : {sheet.refusal_reason}</p>
                    ) : null}
                    {sheet.is_not_worked ? (
                        <>
                            <p>Statut : Non travaillé</p>
                            <p>
                                Description : {String(sheet.description || '').trim() || 'Non renseignée'}
                            </p>
                        </>
                    ) : (
                        (() => {
                            const coverage = leaveCoverage(entry.leave);
                            const isContinuousDay = Boolean(sheet.is_continuous_day) && !coverage.morning && !coverage.afternoon;
                            const { totalMinutes: totalWorkedMinutes } = computeDayTotals(
                                { ...sheet, is_continuous_day: isContinuousDay },
                                coverage,
                            );
                            const morningStart = coverage.morning ? 'Congé' : formatHistoryTime(sheet.morning_start);
                            const morningEnd = coverage.morning ? 'Congé' : formatHistoryTime(sheet.morning_end);
                            const afternoonStart = coverage.afternoon ? 'Congé' : formatHistoryTime(sheet.afternoon_start);
                            const afternoonEnd = coverage.afternoon ? 'Congé' : formatHistoryTime(sheet.afternoon_end);
                            const hoursLabel = isContinuousDay
                                ? `${formatHistoryTime(sheet.morning_start)} - ${formatHistoryTime(sheet.afternoon_end)} (journée continue)`
                                : coverage.morning
                                    ? `Congé / Congé / ${afternoonStart} - ${afternoonEnd}`
                                    : coverage.afternoon
                                        ? `${morningStart} - ${morningEnd} / Congé / Congé`
                                        : `${morningStart} - ${morningEnd} / ${afternoonStart} - ${afternoonEnd}`;

                            return (
                        <>
                    <p>Heures : {hoursLabel}</p>
                    <p className="flex flex-wrap items-center gap-2">
                        <span>Total heures travaillées : {formatWorkedDuration(totalWorkedMinutes)}</span>
                        <OvertimeBadge label={dayOvertimeLabel({
                            dayState: sheet,
                            coverage,
                            totalMinutes: totalWorkedMinutes,
                            workDate: sheet.work_date,
                        })} />
                    </p>
                    <p>
                        Description : {String(sheet.description || '').trim() || 'Non renseignée'}
                    </p>
                    <p>
                        Cases cochées :
                        {' '}
                        {[
                            sheet.has_breakfast_before_5 ? 'Casse-croûte' : null,
                            sheet.has_lunch ? 'Déjeuner' : null,
                            sheet.has_dinner_after_21 ? 'Dîner' : null,
                            sheet.has_long_night ? 'Nuit' : null,
                        ].filter(Boolean).join(', ') || 'Aucune'}
                    </p>
                        </>
                            );
                        })()
                    )}
                    {canCreate && !leaveCoverage(approvedLeavesByDate[sheet.work_date]).fullDay && (
                        <button
                            type="button"
                            onClick={() => startInlineEdit(sheet.work_date)}
                            className="mt-2 rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-xs font-medium"
                        >
                            Modifier
                        </button>
                    )}
                </div>
            ))}
        </article>
        );
    };

    return (
        <AppLayout
            title="Heures"
            header={(
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h1 className="text-center text-[22px] leading-none sm:text-left">
                        <TitleCaps text="Heures" />
                    </h1>

                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                        {canExport && (
                            <button
                                type="button"
                                onClick={() => setExportModalOpen(true)}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-2 text-sm font-medium sm:w-auto"
                            >
                                Exporter
                            </button>
                        )}
                        {canCreate && (
                            <button
                                type="button"
                                onClick={() => setShowWeekend((prev) => !prev)}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-2 text-sm font-medium sm:w-auto"
                            >
                                {showWeekend ? 'Masquer le week-end' : 'Afficher le week-end'}
                            </button>
                        )}
                        {canCreate && (
                            <button
                                type="button"
                                onClick={() => setHistoryOpen((prev) => !prev)}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-2 text-sm font-medium sm:w-auto"
                            >
                                {historyOpen ? 'Masquer l\'historique' : 'Afficher l\'historique'}
                            </button>
                        )}
                    </div>
                </div>
            )}
        >
            <Head title="Heures" />

            <div className="space-y-6">
                {flashError && !shouldHideUnauthorizedFlash && (
                    <div className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {flashError}
                    </div>
                )}

                <HoursValidationQueue
                    rows={hourSheetsToValidate}
                    pendingCount={pendingValidationCount}
                />

                {canCreate ? (
                    visibleWeekDays.length === 0 ? (
                        <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 text-sm text-[var(--app-text-soft)]">
                            Toutes les journées de la semaine sont enregistrées.
                        </div>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {visibleWeekDays.map((day) => {
                                const leaveInfo = approvedLeavesByDate[day.work_date];
                                const coverage = leaveCoverage(leaveInfo);
                                if (coverage.fullDay) {
                                    return (
                                        <section
                                            key={day.id}
                                            className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm"
                                        >
                                            <h2 className="text-center text-lg font-semibold uppercase">{day.label}</h2>
                                            <p className="mt-4 text-sm text-[var(--app-text-soft)]">
                                                Vous êtes en congé ce jour-là, aucune heure n’est à saisir.
                                            </p>
                                        </section>
                                    );
                                }

                                const dayState = formState[day.id];
                                const isNotWorked = Boolean(dayState.is_not_worked);
                                const canUseContinuousDay = !coverage.morning && !coverage.afternoon;
                                const isContinuousDay = canUseContinuousDay && Boolean(dayState.is_continuous_day);
                                const { totalMinutes: totalWorkedMinutes, errors: rangeErrors } = computeDayTotals(
                                    { ...dayState, is_continuous_day: isContinuousDay },
                                    coverage,
                                );
                                const errorMessages = isNotWorked ? [] : rangeErrors;
                                const descriptionMissing = !isNotWorked && !String(dayState.description || '').trim();
                                const isSaving = savingDates.has(day.work_date);

                                return (
                                    <section
                                        key={day.id}
                                        className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm"
                                    >
                                        <h2 className="text-center text-lg font-semibold uppercase">{day.label}</h2>

                                        {canUseContinuousDay && (
                                            <button
                                                type="button"
                                                onClick={() => onToggleCheck(day.id, 'is_continuous_day')}
                                                disabled={isNotWorked}
                                                className="mt-3 flex items-center justify-center gap-3 text-left"
                                            >
                                                <span
                                                    className="flex h-5 w-5 items-center justify-center rounded-sm border border-[var(--app-border)] bg-white text-xs"
                                                    aria-hidden="true"
                                                >
                                                    {dayState.is_continuous_day ? '✔️' : ''}
                                                </span>
                                                <span className="text-sm">Journée continue / Demi-journée</span>
                                            </button>
                                        )}

                                        {isNotWorked && (
                                            <p className="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-center text-sm font-semibold text-gray-700">
                                                Journée marquée comme non travaillée
                                            </p>
                                        )}

                                        <div className="mt-4 grid gap-3">
                                            {isContinuousDay ? (
                                                CONTINUOUS_TIME_FIELDS.map((field) => (
                                                    <label key={field.key} className="grid gap-1 text-sm">
                                                        <span className="font-medium">{field.label}</span>
                                                        <select
                                                            value={dayState[field.key]}
                                                            onChange={(event) => onTimeChange(day.id, field.key, event.target.value)}
                                                            disabled={isNotWorked}
                                                            className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                                                        >
                                                            <option value="">--:--</option>
                                                            {timeOptions.map((time) => (
                                                                <option key={time} value={time}>
                                                                    {time}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </label>
                                                ))
                                            ) : (
                                                <>
                                                    {coverage.morning ? (
                                                        <p className="rounded-lg bg-gray-100 px-3 py-2 text-center text-sm font-semibold text-gray-700">
                                                            Vous êtes en congé ce matin.
                                                        </p>
                                                    ) : MORNING_TIME_FIELDS.map((field) => (
                                                        <label key={field.key} className="grid gap-1 text-sm">
                                                            <span className="font-medium">{field.label}</span>
                                                            <select
                                                                value={dayState[field.key]}
                                                                onChange={(event) => onTimeChange(day.id, field.key, event.target.value)}
                                                                disabled={isNotWorked}
                                                                className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                                                            >
                                                                <option value="">--:--</option>
                                                                {timeOptions.map((time) => (
                                                                    <option key={time} value={time}>
                                                                        {time}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </label>
                                                    ))}
                                                    {coverage.afternoon ? (
                                                        <p className="rounded-lg bg-gray-100 px-3 py-2 text-center text-sm font-semibold text-gray-700">
                                                            Vous êtes en congé cet après-midi.
                                                        </p>
                                                    ) : AFTERNOON_TIME_FIELDS.map((field) => (
                                                        <label key={field.key} className="grid gap-1 text-sm">
                                                            <span className="font-medium">{field.label}</span>
                                                            <select
                                                                value={dayState[field.key]}
                                                                onChange={(event) => onTimeChange(day.id, field.key, event.target.value)}
                                                                disabled={isNotWorked}
                                                                className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                                                            >
                                                                <option value="">--:--</option>
                                                                {timeOptions.map((time) => (
                                                                    <option key={time} value={time}>
                                                                        {time}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </label>
                                                    ))}
                                                </>
                                            )}
                                        </div>

                                        <div className="mt-4 text-center">
                                            <p className="text-sm">Total heures travaillées</p>
                                            <div className="flex flex-wrap items-center justify-center gap-2">
                                                <p className="text-lg font-semibold">{formatWorkedDuration(totalWorkedMinutes)}</p>
                                                <OvertimeBadge label={dayOvertimeLabel({
                                                    dayState,
                                                    coverage,
                                                    totalMinutes: totalWorkedMinutes,
                                                    dayIndex: day.dayIndex,
                                                })} />
                                            </div>
                                        </div>

                                        {errorMessages.length > 0 && (
                                            <div className="mt-2 space-y-1">
                                                {errorMessages.map((message) => (
                                                    <p key={message} className="text-xs text-red-600">
                                                        {message}
                                                    </p>
                                                ))}
                                            </div>
                                        )}

                                        <div className="mt-4 grid gap-2">
                                            {CHECKBOX_FIELDS.map((field) => {
                                                const checked = dayState[field.key];

                                                return (
                                                    <button
                                                        key={field.key}
                                                        type="button"
                                                        onClick={() => onToggleCheck(day.id, field.key)}
                                                        disabled={isNotWorked}
                                                        className="flex items-center gap-3 text-left"
                                                    >
                                                        <span
                                                            className="flex h-5 w-5 items-center justify-center rounded-sm border border-[var(--app-border)] bg-white text-xs"
                                                            aria-hidden="true"
                                                        >
                                                            {checked ? '✔️' : ''}
                                                        </span>
                                                        <span className="text-sm">{field.label}</span>
                                                    </button>
                                                );
                                            })}
                                        </div>

                                        <label className="mt-4 grid gap-1 text-sm">
                                            <span className="font-medium">Description des travaux réalisés</span>
                                            <textarea
                                                rows={4}
                                                maxLength={5000}
                                                value={dayState.description || ''}
                                                onChange={(event) => onTimeChange(day.id, 'description', event.target.value)}
                                                disabled={isNotWorked}
                                                placeholder="Décrivez les travaux effectués durant la journée..."
                                                className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)] disabled:bg-gray-100"
                                            />
                                            {descriptionMissing && (
                                                <span className="text-xs text-red-600">
                                                    La description des travaux réalisés est obligatoire.
                                                </span>
                                            )}
                                            {errors.description && (
                                                <span className="text-xs text-red-600">{errors.description}</span>
                                            )}
                                        </label>

                                        <div className="mt-4 space-y-2">
                                            <button
                                                type="button"
                                                onClick={() => saveDay(day)}
                                                disabled={isSaving || errorMessages.length > 0 || descriptionMissing}
                                                className="w-full rounded-xl bg-[#F1BF0C] px-4 py-3 text-sm font-semibold text-black transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {isSaving ? 'Enregistrement...' : 'Enregistrer'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => markDayAsNotWorked(day)}
                                                disabled={isSaving}
                                                className="w-full rounded-xl border border-[var(--app-border)] bg-white px-4 py-3 text-sm font-semibold text-black transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Non travaillé
                                            </button>
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    )
                ) : (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 text-sm text-[var(--app-text-soft)]">
                        Vous n’êtes pas autorisé à saisir vos heures.
                    </div>
                )}

                {/* Les journées encore en validation ont leur propre section, au-dessus
                    de l'historique et toujours dépliée : elles étaient auparavant
                    noyées dans un historique replié par défaut. */}
                {canCreate && pendingEntries.length > 0 && (
                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                        <h2 className="text-lg font-semibold">Mes heures en validation</h2>

                        <div className="mt-4 space-y-3">
                            {pendingEntries.map(renderDayEntry)}
                        </div>
                    </section>
                )}

                {canCreate && historyOpen && (
                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                        <h2 className="text-lg font-semibold">Historique</h2>

                        {settledEntries.length === 0 ? (
                            <p className="mt-3 text-sm text-[var(--app-text-soft)]">Aucune journée terminée.</p>
                        ) : (
                            <div className="mt-4 space-y-3">
                                {settledEntries.map(renderDayEntry)}
                            </div>
                        )}
                    </section>
                )}
            </div>

            <Modal show={canExport && exportModalOpen} onClose={() => setExportModalOpen(false)}>
                <div className="space-y-4 p-5 sm:p-6">
                    <h2 className="text-lg font-semibold">Exporter les heures</h2>

                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">Date de début</span>
                        <input
                            type="date"
                            value={exportRange.start_date}
                            onChange={(event) => setExportRange((prev) => ({ ...prev, start_date: event.target.value }))}
                            className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                        />
                    </label>

                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">Date de fin</span>
                        <input
                            type="date"
                            value={exportRange.end_date}
                            onChange={(event) => setExportRange((prev) => ({ ...prev, end_date: event.target.value }))}
                            className="rounded-xl border border-[var(--app-border)] bg-white px-3 py-2 text-sm text-black outline-none focus:ring-2 focus:ring-[var(--brand-yellow)]"
                        />
                    </label>

                    {exportError && (
                        <p className="text-sm text-red-600">{exportError}</p>
                    )}

                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={() => setExportModalOpen(false)}
                            className="w-full rounded-xl border border-[var(--app-border)] px-4 py-2 text-sm font-medium sm:w-auto"
                        >
                            Fermer
                        </button>
                        <button
                            type="button"
                            onClick={onExport}
                            className="w-full rounded-xl bg-[#F1BF0C] px-4 py-2 text-sm font-semibold text-black hover:brightness-95 sm:w-auto"
                        >
                            Exporter
                        </button>
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}
