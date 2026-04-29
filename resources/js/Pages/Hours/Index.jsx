import AppLayout from '@/Layouts/AppLayout';
import TitleCaps from '@/Layouts/AppShell/TitleCaps';
import Modal from '@/Components/Modal';
import { Head, router, usePage } from '@inertiajs/react';
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

const CHECKBOX_FIELDS = [
    { key: 'has_breakfast_before_5', label: 'Casse-croûte (Avant 5h)' },
    { key: 'has_lunch', label: 'Déjeuner' },
    { key: 'has_dinner_after_21', label: 'Dîner (Après 21h)' },
    { key: 'has_long_night', label: 'Nuit (Déplacement long)' },
];
const MIN_VISIBLE_DATE = '2026-04-25';

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

function normalizeTimeForSelect(value) {
    const source = String(value || '').trim();
    if (!source) {
        return '';
    }

    const [hours, minutes] = source.split(':');
    if (hours === undefined || minutes === undefined) {
        return '';
    }

    return `${String(hours).padStart(2, '0').slice(-2)}:${String(minutes).padStart(2, '0').slice(-2)}`;
}

function timeToMinutes(timeValue) {
    if (!timeValue || !timeValue.includes(':')) {
        return null;
    }

    const [hours, minutes] = timeValue.split(':').map((value) => Number(value));
    if (Number.isNaN(hours) || Number.isNaN(minutes)) {
        return null;
    }

    return (hours * 60) + minutes;
}

function computeRangeDuration(start, end, label) {
    if (!start || !end) {
        return { minutes: 0, error: null };
    }

    const startMinutes = timeToMinutes(start);
    const endMinutes = timeToMinutes(end);

    if (startMinutes === null || endMinutes === null) {
        return { minutes: 0, error: null };
    }

    if (endMinutes < startMinutes) {
        return {
            minutes: 0,
            error: `Plage ${label} invalide: arrivée avant départ.`,
        };
    }

    return { minutes: endMinutes - startMinutes, error: null };
}

function formatWorkedDuration(totalMinutes) {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${String(hours).padStart(2, '0')}h${String(minutes).padStart(2, '0')}`;
}

function defaultDayState({ isFriday = false } = {}) {
    return {
        morning_start: '08:00',
        morning_end: '12:00',
        afternoon_start: '14:00',
        afternoon_end: isFriday ? '17:00' : '18:00',
        has_breakfast_before_5: false,
        has_lunch: false,
        has_dinner_after_21: false,
        has_long_night: false,
    };
}

export default function HoursIndex({ hourSheets = [], canCreate = false, canExport = false }) {
    const { flash = {} } = usePage().props;
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
        const minDate = new Date(`${MIN_VISIBLE_DATE}T00:00:00`);
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
    }, []);
    const todayIso = useMemo(() => isoDate(new Date()), []);
    const lastWeekendDates = useMemo(() => {
        const minDate = new Date(`${MIN_VISIBLE_DATE}T00:00:00`);
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

        if (saturdayIso >= MIN_VISIBLE_DATE && saturdayIso <= todayIso) {
            allowed.add(saturdayIso);
        }
        if (sundayIso >= MIN_VISIBLE_DATE && sundayIso <= todayIso) {
            allowed.add(sundayIso);
        }

        return allowed;
    }, [todayIso]);

    const sheetsByDate = useMemo(() => {
        const map = {};
        for (const sheet of hourSheets) {
            if (sheet?.work_date) {
                map[sheet.work_date] = sheet;
            }
        }

        return map;
    }, [hourSheets]);

    const [formState, setFormState] = useState(() => {
        const initial = {};

        for (const day of weekDays) {
            const existing = sheetsByDate[day.work_date];
            initial[day.id] = existing ? {
                morning_start: normalizeTimeForSelect(existing.morning_start),
                morning_end: normalizeTimeForSelect(existing.morning_end),
                afternoon_start: normalizeTimeForSelect(existing.afternoon_start),
                afternoon_end: normalizeTimeForSelect(existing.afternoon_end),
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
        if (day.work_date < MIN_VISIBLE_DATE || day.work_date > todayIso) {
            return false;
        }

        const isWeekend = day.dayIndex >= 5;
        if (isWeekend && !showWeekend) {
            return false;
        }
        if (isWeekend && showWeekend && !lastWeekendDates.has(day.work_date)) {
            return false;
        }

        return !sheetsByDate[day.work_date];
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

        setSavingDates((prev) => {
            const next = new Set(prev);
            next.add(day.work_date);
            return next;
        });

        router.post(route('hours.store'), {
            work_date: day.work_date,
            morning_start: dayState.morning_start || null,
            morning_end: dayState.morning_end || null,
            afternoon_start: dayState.afternoon_start || null,
            afternoon_end: dayState.afternoon_end || null,
            has_breakfast_before_5: Boolean(dayState.has_breakfast_before_5),
            has_lunch: Boolean(dayState.has_lunch),
            has_dinner_after_21: Boolean(dayState.has_dinner_after_21),
            has_long_night: Boolean(dayState.has_long_night),
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

        const morningRange = computeRangeDuration(dayState.morning_start, dayState.morning_end, 'matin');
        const eveningRange = computeRangeDuration(dayState.afternoon_start, dayState.afternoon_end, 'soir');
        const hasErrors = [morningRange.error, eveningRange.error].some(Boolean);
        if (hasErrors) {
            return;
        }

        setSavingDates((prev) => {
            const next = new Set(prev);
            next.add(workDate);
            return next;
        });

        router.post(route('hours.store'), {
            work_date: workDate,
            morning_start: dayState.morning_start || null,
            morning_end: dayState.morning_end || null,
            afternoon_start: dayState.afternoon_start || null,
            afternoon_end: dayState.afternoon_end || null,
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

                {canCreate ? (
                    visibleWeekDays.length === 0 ? (
                        <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 text-sm text-[var(--app-text-soft)]">
                            Toutes les journées de la semaine sont enregistrées.
                        </div>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {visibleWeekDays.map((day) => {
                                const dayState = formState[day.id];
                                const morningRange = computeRangeDuration(dayState.morning_start, dayState.morning_end, 'matin');
                                const eveningRange = computeRangeDuration(dayState.afternoon_start, dayState.afternoon_end, 'soir');
                                const totalWorkedMinutes = morningRange.minutes + eveningRange.minutes;
                                const errorMessages = [morningRange.error, eveningRange.error].filter(Boolean);
                                const isSaving = savingDates.has(day.work_date);

                                return (
                                    <section
                                        key={day.id}
                                        className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm"
                                    >
                                        <h2 className="text-center text-lg font-semibold uppercase">{day.label}</h2>

                                        <div className="mt-4 grid gap-3">
                                            {TIME_FIELDS.map((field) => (
                                                <label key={field.key} className="grid gap-1 text-sm">
                                                    <span className="font-medium">{field.label}</span>
                                                    <select
                                                        value={dayState[field.key]}
                                                        onChange={(event) => onTimeChange(day.id, field.key, event.target.value)}
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
                                        </div>

                                        <div className="mt-4 text-center">
                                            <p className="text-sm">Total heures travaillées</p>
                                            <p className="text-lg font-semibold">{formatWorkedDuration(totalWorkedMinutes)}</p>
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

                                        <div className="mt-4">
                                            <button
                                                type="button"
                                                onClick={() => saveDay(day)}
                                                disabled={isSaving || errorMessages.length > 0}
                                                className="w-full rounded-xl bg-[#F1BF0C] px-4 py-3 text-sm font-semibold text-black transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {isSaving ? 'Enregistrement...' : 'Enregistrer'}
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

                {canCreate && historyOpen && (
                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                        <h2 className="text-lg font-semibold">Historique</h2>

                        {hourSheets.length === 0 ? (
                            <p className="mt-3 text-sm text-[var(--app-text-soft)]">Aucune journée enregistrée.</p>
                        ) : (
                            <div className="mt-4 space-y-3">
                                {hourSheets.map((sheet) => (
                                    <article key={sheet.id} className="rounded-xl border border-[var(--app-border)] bg-white p-3 text-sm text-black">
                                        {canCreate && inlineEditingByDate[sheet.work_date] ? (() => {
                                            const dayState = inlineEditingByDate[sheet.work_date];
                                            const morningRange = computeRangeDuration(dayState.morning_start, dayState.morning_end, 'matin');
                                            const eveningRange = computeRangeDuration(dayState.afternoon_start, dayState.afternoon_end, 'soir');
                                            const totalWorkedMinutes = morningRange.minutes + eveningRange.minutes;
                                            const errorMessages = [morningRange.error, eveningRange.error].filter(Boolean);
                                            const isSaving = savingDates.has(sheet.work_date);

                                                    return (
                                                <div>
                                                    <p className="text-center font-semibold uppercase">{formatHistoryDate(sheet.work_date)}</p>

                                                    <div className="mt-4 grid gap-3">
                                                        {TIME_FIELDS.map((field) => (
                                                            <label key={field.key} className="grid gap-1 text-sm">
                                                                <span className="font-medium">{field.label}</span>
                                                                <select
                                                                    value={dayState[field.key]}
                                                                    onChange={(event) => onInlineTimeChange(sheet.work_date, field.key, event.target.value)}
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
                                                    </div>

                                                    <div className="mt-4 text-center">
                                                        <p className="text-sm">Total heures travaillées</p>
                                                        <p className="text-lg font-semibold">{formatWorkedDuration(totalWorkedMinutes)}</p>
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

                                                    <div className="mt-4 flex gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => saveInlineDay(sheet.work_date)}
                                                            disabled={isSaving || errorMessages.length > 0}
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
                                                <p className="font-semibold">Date : {formatHistoryDate(sheet.work_date)}</p>
                                                <p>
                                                    Heures : {formatHistoryTime(sheet.morning_start)} - {formatHistoryTime(sheet.morning_end)}
                                                    {' / '}
                                                    {formatHistoryTime(sheet.afternoon_start)} - {formatHistoryTime(sheet.afternoon_end)}
                                                </p>
                                                <p>Total heures travaillées : {formatWorkedDuration(Number(sheet.total_minutes || 0))}</p>
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
                                                {canCreate && (
                                                    <button
                                                        type="button"
                                                        onClick={() => startInlineEdit(sheet.work_date)}
                                                        className="mt-2 rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-xs font-medium"
                                                    >
                                                        Modifier
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </article>
                                ))}
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
