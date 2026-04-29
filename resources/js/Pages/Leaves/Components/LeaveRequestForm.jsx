import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';

const PORTION_OPTIONS = [
    { value: 'full_day', label: 'Journée entière' },
    { value: 'morning', label: 'Matin' },
    { value: 'afternoon', label: 'Après-midi' },
    { value: 'custom', label: 'Personnaliser' },
];
const SINGLE_DAY_OPTIONS = [
    { value: 'full_day', label: 'Journée complète' },
    { value: 'morning', label: 'Matin' },
    { value: 'afternoon', label: 'Après-midi' },
];
const MULTI_DAY_OPTIONS = [
    { value: 'morning', label: 'Matin' },
    { value: 'afternoon', label: 'Après-midi' },
];

function toDateTime(dateValue, timeValue) {
    if (!dateValue) {
        return '';
    }

    const normalizedTime = timeValue || '00:00';
    return `${dateValue} ${normalizedTime}:00`;
}

function defaultTimesForPortion(portion) {
    if (portion === 'morning') {
        return { start: '08:00', end: '12:00' };
    }

    if (portion === 'afternoon') {
        return { start: '14:00', end: '18:00' };
    }

    return { start: '00:00', end: '18:00' };
}

function addDaysToIsoDate(isoDate, daysToAdd) {
    if (!isoDate) {
        return '';
    }

    const [year, month, day] = String(isoDate).split('-').map(Number);
    if (!year || !month || !day) {
        return '';
    }

    const date = new Date(year, month - 1, day);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    date.setDate(date.getDate() + daysToAdd);

    const nextYear = date.getFullYear();
    const nextMonth = String(date.getMonth() + 1).padStart(2, '0');
    const nextDay = String(date.getDate()).padStart(2, '0');

    return `${nextYear}-${nextMonth}-${nextDay}`;
}

export default function LeaveRequestForm({
    users = [],
    leaveTypes = [],
    defaultTargetUserId,
    canRequestForOthers = false,
    allowMultiplePeriods = false,
    onSuccess,
}) {
    const buildEmptyPeriod = () => ({
        start_date: '',
        end_date: '',
        start_portion: 'full_day',
        end_portion: 'full_day',
        custom_start_time: '08:00',
        custom_end_time: '18:00',
        description: '',
    });

    const form = useForm({
        target_user_id: defaultTargetUserId || users[0]?.id || '',
        leave_type_id: '',
        start_date: '',
        end_date: '',
        start_portion: 'full_day',
        end_portion: 'full_day',
        custom_start_time: '08:00',
        custom_end_time: '18:00',
        message: '',
        periods: [buildEmptyPeriod()],
    });

    const isStartCustom = form.data.start_portion === 'custom';
    const isEndCustom = form.data.end_portion === 'custom';
    const isSingleDayRequest = Boolean(
        form.data.start_date
        && form.data.end_date
        && form.data.start_date === form.data.end_date,
    );
    const selectedLeaveType = useMemo(
        () => leaveTypes.find((typeOption) => String(typeOption.id) === String(form.data.leave_type_id)),
        [leaveTypes, form.data.leave_type_id],
    );
    const selectedMaxDays = useMemo(() => {
        const rawValue = selectedLeaveType?.max_days;
        if (rawValue === null || rawValue === undefined || rawValue === '') {
            return null;
        }

        const parsed = Number(rawValue);
        if (!Number.isFinite(parsed) || parsed < 1) {
            return null;
        }

        return Math.floor(parsed);
    }, [selectedLeaveType]);
    const endDateMin = form.data.start_date || undefined;
    const endDateMax = useMemo(() => {
        if (!form.data.start_date || selectedMaxDays === null) {
            return undefined;
        }

        return addDaysToIsoDate(form.data.start_date, selectedMaxDays - 1) || undefined;
    }, [form.data.start_date, selectedMaxDays]);
    const startDateMax = form.data.end_date || undefined;
    const startDateMin = useMemo(() => {
        if (!form.data.end_date || selectedMaxDays === null) {
            return undefined;
        }

        return addDaysToIsoDate(form.data.end_date, -(selectedMaxDays - 1)) || undefined;
    }, [form.data.end_date, selectedMaxDays]);
    const periods = useMemo(() => (
        Array.isArray(form.data.periods) && form.data.periods.length > 0
            ? form.data.periods
            : [buildEmptyPeriod()]
    ), [form.data.periods]);
    const singleDayPortionSelection = useMemo(() => {
        const startPortion = String(form.data.start_portion || '');
        const endPortion = String(form.data.end_portion || '');
        const allowed = new Set(SINGLE_DAY_OPTIONS.map((option) => option.value));

        if (startPortion === endPortion && allowed.has(startPortion)) {
            return startPortion;
        }

        if (allowed.has(startPortion)) {
            return startPortion;
        }

        if (allowed.has(endPortion)) {
            return endPortion;
        }

        return 'full_day';
    }, [form.data.start_portion, form.data.end_portion]);

    const normalizePeriod = (periodInput) => {
        const period = {
            ...buildEmptyPeriod(),
            ...(periodInput || {}),
        };

        if (period.start_date && !period.end_date && selectedMaxDays === 1) {
            period.end_date = period.start_date;
        }
        if (period.end_date && !period.start_date && selectedMaxDays === 1) {
            period.start_date = period.end_date;
        }

        if (period.start_date && period.end_date && period.end_date < period.start_date) {
            period.end_date = period.start_date;
        }

        if (selectedMaxDays !== null) {
            if (period.start_date) {
                const maxEnd = addDaysToIsoDate(period.start_date, selectedMaxDays - 1);
                if (period.end_date && maxEnd && period.end_date > maxEnd) {
                    period.end_date = maxEnd;
                }
            }

            if (period.end_date) {
                const minStart = addDaysToIsoDate(period.end_date, -(selectedMaxDays - 1));
                if (period.start_date && minStart && period.start_date < minStart) {
                    period.start_date = minStart;
                }
            }
        }

        const isSingleDay = Boolean(
            period.start_date
            && period.end_date
            && period.start_date === period.end_date,
        );

        if (isSingleDay) {
            const allowed = new Set(SINGLE_DAY_OPTIONS.map((option) => option.value));
            let selected = 'full_day';
            if (allowed.has(period.start_portion) && period.start_portion === period.end_portion) {
                selected = period.start_portion;
            } else if (allowed.has(period.start_portion)) {
                selected = period.start_portion;
            } else if (allowed.has(period.end_portion)) {
                selected = period.end_portion;
            }
            period.start_portion = selected;
            period.end_portion = selected;
        } else {
            if (!MULTI_DAY_OPTIONS.some((option) => option.value === period.start_portion)) {
                period.start_portion = 'morning';
            }
            if (!MULTI_DAY_OPTIONS.some((option) => option.value === period.end_portion)) {
                period.end_portion = 'afternoon';
            }
        }

        return period;
    };

    const updatePeriod = (index, patch) => {
        const nextPeriods = periods.map((period, periodIndex) => (
            periodIndex === index
                ? normalizePeriod({ ...period, ...patch })
                : period
        ));
        form.setData('periods', nextPeriods);
    };

    const addPeriod = () => {
        form.setData('periods', [...periods, buildEmptyPeriod()]);
    };

    const removePeriod = (index) => {
        if (index === 0 || periods.length <= 1) {
            return;
        }

        form.setData('periods', periods.filter((_, periodIndex) => periodIndex !== index));
    };
    const multiDayStartSelection = useMemo(() => (
        MULTI_DAY_OPTIONS.some((option) => option.value === form.data.start_portion)
            ? form.data.start_portion
            : 'morning'
    ), [form.data.start_portion]);
    const multiDayEndSelection = useMemo(() => (
        MULTI_DAY_OPTIONS.some((option) => option.value === form.data.end_portion)
            ? form.data.end_portion
            : 'afternoon'
    ), [form.data.end_portion]);
    const lockedUserLabel = useMemo(() => {
        const selected = users.find(
            (userOption) => String(userOption.id) === String(form.data.target_user_id),
        );

        return selected?.label || users[0]?.label || '';
    }, [users, form.data.target_user_id]);

    useEffect(() => {
        if (canRequestForOthers) {
            return;
        }

        const selfUserId = users[0]?.id || defaultTargetUserId || '';
        if (selfUserId && String(form.data.target_user_id) !== String(selfUserId)) {
            form.setData('target_user_id', selfUserId);
        }
    }, [canRequestForOthers, users, defaultTargetUserId, form.data.target_user_id, form]);

    useEffect(() => {
        const startDate = form.data.start_date;
        const endDate = form.data.end_date;

        if (!startDate) {
            return;
        }

        if (!endDate) {
            if (selectedMaxDays === 1) {
                form.setData('end_date', startDate);
            }
            return;
        }

        if (endDate < startDate) {
            form.setData('end_date', startDate);
            return;
        }

        if (endDateMax && endDate > endDateMax) {
            form.setData('end_date', endDateMax);
        }
    }, [form.data.start_date, form.data.end_date, selectedMaxDays, endDateMax]);

    useEffect(() => {
        const startDate = form.data.start_date;
        const endDate = form.data.end_date;

        if (!endDate) {
            return;
        }

        if (!startDate) {
            if (selectedMaxDays === 1) {
                form.setData('start_date', endDate);
            }
            return;
        }

        if (startDate > endDate) {
            form.setData('start_date', endDate);
            return;
        }

        if (startDateMin && startDate < startDateMin) {
            form.setData('start_date', startDateMin);
        }
    }, [form.data.start_date, form.data.end_date, selectedMaxDays, startDateMin]);

    useEffect(() => {
        if (!isSingleDayRequest) {
            return;
        }

        if (
            form.data.start_portion !== singleDayPortionSelection
            || form.data.end_portion !== singleDayPortionSelection
        ) {
            form.setData({
                ...form.data,
                start_portion: singleDayPortionSelection,
                end_portion: singleDayPortionSelection,
            });
        }
    }, [isSingleDayRequest, singleDayPortionSelection, form.data, form]);

    useEffect(() => {
        if (isSingleDayRequest) {
            return;
        }

        if (
            form.data.start_portion !== multiDayStartSelection
            || form.data.end_portion !== multiDayEndSelection
        ) {
            form.setData({
                ...form.data,
                start_portion: multiDayStartSelection,
                end_portion: multiDayEndSelection,
            });
        }
    }, [isSingleDayRequest, multiDayStartSelection, multiDayEndSelection, form.data, form]);

    const submit = (event) => {
        event.preventDefault();

        if (allowMultiplePeriods) {
            form.transform((data) => ({
                target_user_id: data.target_user_id,
                leave_type_id: data.leave_type_id || null,
                message: data.message || null,
                periods: (Array.isArray(data.periods) ? data.periods : []).map((period) => {
                    const normalizedPeriod = normalizePeriod(period);
                    return {
                        start_date: normalizedPeriod.start_date || null,
                        end_date: normalizedPeriod.end_date || null,
                        start_portion: normalizedPeriod.start_portion,
                        end_portion: normalizedPeriod.end_portion,
                        description: String(normalizedPeriod.description || '').trim() || null,
                        custom_start_time: normalizedPeriod.start_portion === 'custom'
                            ? (normalizedPeriod.custom_start_time || null)
                            : null,
                        custom_end_time: normalizedPeriod.end_portion === 'custom'
                            ? (normalizedPeriod.custom_end_time || null)
                            : null,
                    };
                }),
            }));

            form.post(route('leaves.store'), {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset('periods', 'message');
                    form.setData('target_user_id', defaultTargetUserId || users[0]?.id || '');
                    form.setData('leave_type_id', '');
                    form.setData('message', '');
                    form.setData('periods', [buildEmptyPeriod()]);
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                },
            });
            return;
        }

        const startTime = isStartCustom
            ? (form.data.custom_start_time || '08:00')
            : defaultTimesForPortion(form.data.start_portion).start;
        const endTime = isEndCustom
            ? (form.data.custom_end_time || '18:00')
            : defaultTimesForPortion(form.data.end_portion).end;

        form.transform((data) => ({
            target_user_id: data.target_user_id,
            leave_type_id: data.leave_type_id || null,
            start_at: toDateTime(data.start_date, startTime),
            end_at: toDateTime(data.end_date || data.start_date, endTime),
            start_portion: data.start_portion,
            end_portion: data.end_portion,
            is_all_day: data.start_portion === 'full_day' && data.end_portion === 'full_day',
            custom_start_time: data.start_portion === 'custom' ? data.custom_start_time : null,
            custom_end_time: data.end_portion === 'custom' ? data.custom_end_time : null,
            message: data.message || null,
        }));

        form.post(route('leaves.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('start_date', 'end_date', 'start_portion', 'end_portion', 'custom_start_time', 'custom_end_time', 'message');
                form.setData('target_user_id', defaultTargetUserId || users[0]?.id || '');
                form.setData('leave_type_id', '');
                form.setData('start_portion', 'full_day');
                form.setData('end_portion', 'full_day');
                form.setData('custom_start_time', '08:00');
                form.setData('custom_end_time', '18:00');
                if (typeof onSuccess === 'function') {
                    onSuccess();
                }
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-1.5">
                    <span className="text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">Utilisateur</span>
                    {canRequestForOthers ? (
                        <select
                            value={form.data.target_user_id}
                            onChange={(event) => form.setData('target_user_id', event.target.value)}
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)]"
                        >
                            {users.map((userOption) => (
                                <option key={userOption.id} value={userOption.id}>
                                    {userOption.label}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <div className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)]">
                            {lockedUserLabel}
                        </div>
                    )}
                    {form.errors.target_user_id ? (
                        <p className="text-xs text-red-600">{form.errors.target_user_id}</p>
                    ) : null}
                </label>

                <label className="space-y-1.5">
                    <span className="text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">Type de congé</span>
                    <select
                        value={form.data.leave_type_id}
                        onChange={(event) => form.setData('leave_type_id', event.target.value)}
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)]"
                    >
                        <option value="">Sélectionner</option>
                        {leaveTypes.map((typeOption) => (
                            <option key={typeOption.id} value={typeOption.id}>
                                {(() => {
                                    const days = Number(typeOption.max_days);
                                    return Number.isFinite(days) && days > 0
                                        ? `${typeOption.label} (${days}J)`
                                        : typeOption.label;
                                })()}
                            </option>
                        ))}
                    </select>
                    {form.errors.leave_type_id ? (
                        <p className="text-xs text-red-600">{form.errors.leave_type_id}</p>
                    ) : null}
                </label>
            </div>

            {allowMultiplePeriods ? (
                <div className="space-y-4">
                    {periods.map((period, index) => {
                        const isSingleDayPeriod = Boolean(
                            period.start_date
                            && period.end_date
                            && period.start_date === period.end_date,
                        );
                        const periodEndMin = period.start_date || undefined;
                        const periodEndMax = period.start_date && selectedMaxDays !== null
                            ? addDaysToIsoDate(period.start_date, selectedMaxDays - 1) || undefined
                            : undefined;
                        const periodStartMax = period.end_date || undefined;
                        const periodStartMin = period.end_date && selectedMaxDays !== null
                            ? addDaysToIsoDate(period.end_date, -(selectedMaxDays - 1)) || undefined
                            : undefined;

                        return (
                            <div key={`period-${index}`} className="space-y-4 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                                {index > 0 ? (
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => removePeriod(index)}
                                            className="text-xs font-semibold text-[var(--app-muted)] underline underline-offset-2"
                                        >
                                            Supprimer cette période
                                        </button>
                                    </div>
                                ) : null}
                                <div className="grid gap-4 md:grid-cols-2">
                                    <label className="space-y-1.5">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">À partir du</span>
                                        <input
                                            type="date"
                                            value={period.start_date}
                                            onChange={(event) => updatePeriod(index, { start_date: event.target.value })}
                                            min={periodStartMin}
                                            max={periodStartMax}
                                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                                        />
                                    </label>

                                    <label className="space-y-1.5">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">Jusqu'au</span>
                                        <input
                                            type="date"
                                            value={period.end_date}
                                            onChange={(event) => updatePeriod(index, { end_date: event.target.value })}
                                            min={periodEndMin}
                                            max={periodEndMax}
                                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                                        />
                                    </label>
                                </div>

                                {isSingleDayPeriod ? (
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        {SINGLE_DAY_OPTIONS.map((option) => {
                                            const isSelected = period.start_portion === option.value && period.end_portion === option.value;
                                            return (
                                                <button
                                                    key={`single-period-${index}-${option.value}`}
                                                    type="button"
                                                    onClick={() => updatePeriod(index, { start_portion: option.value, end_portion: option.value })}
                                                    className="flex flex-col items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-3 text-center"
                                                >
                                                    <span
                                                        className={`flex h-5 w-5 items-center justify-center rounded-[4px] border text-[12px] leading-none transition-all duration-200 ${
                                                            isSelected
                                                                ? 'border-[var(--brand-brown)] bg-[var(--app-surface)] text-[var(--app-text)]'
                                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-transparent'
                                                        }`}
                                                    >
                                                        {isSelected ? '✔' : ''}
                                                    </span>
                                                    <span className="text-sm text-[var(--app-text)]">{option.label}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {MULTI_DAY_OPTIONS.map((option) => {
                                                const isSelected = period.start_portion === option.value;
                                                return (
                                                    <button
                                                        key={`multi-period-start-${index}-${option.value}`}
                                                        type="button"
                                                        onClick={() => updatePeriod(index, { start_portion: option.value })}
                                                        className="flex flex-col items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-3 text-center"
                                                    >
                                                        <span
                                                            className={`flex h-5 w-5 items-center justify-center rounded-[4px] border text-[12px] leading-none transition-all duration-200 ${
                                                                isSelected
                                                                    ? 'border-[var(--brand-brown)] bg-[var(--app-surface)] text-[var(--app-text)]'
                                                                    : 'border-[var(--app-border)] bg-[var(--app-surface)] text-transparent'
                                                            }`}
                                                        >
                                                            {isSelected ? '✔' : ''}
                                                        </span>
                                                        <span className="text-sm text-[var(--app-text)]">{option.label}</span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {MULTI_DAY_OPTIONS.map((option) => {
                                                const isSelected = period.end_portion === option.value;
                                                return (
                                                    <button
                                                        key={`multi-period-end-${index}-${option.value}`}
                                                        type="button"
                                                        onClick={() => updatePeriod(index, { end_portion: option.value })}
                                                        className="flex flex-col items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-3 text-center"
                                                    >
                                                        <span
                                                            className={`flex h-5 w-5 items-center justify-center rounded-[4px] border text-[12px] leading-none transition-all duration-200 ${
                                                                isSelected
                                                                    ? 'border-[var(--brand-brown)] bg-[var(--app-surface)] text-[var(--app-text)]'
                                                                    : 'border-[var(--app-border)] bg-[var(--app-surface)] text-transparent'
                                                            }`}
                                                        >
                                                            {isSelected ? '✔' : ''}
                                                        </span>
                                                        <span className="text-sm text-[var(--app-text)]">{option.label}</span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                <label className="space-y-1.5">
                                    <span className="text-[11px] font-semibold uppercase tracking-wide text-[var(--app-muted)]">Infos supplémentaires</span>
                                    <textarea
                                        value={period.description || ''}
                                        onChange={(event) => updatePeriod(index, { description: event.target.value })}
                                        onInput={(event) => {
                                            const element = event.currentTarget;
                                            element.style.height = 'auto';
                                            element.style.height = `${Math.max(30, element.scrollHeight)}px`;
                                        }}
                                        rows={1}
                                        className="w-full resize-y rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                                    />
                                </label>
                            </div>
                        );
                    })}
                    <button
                        type="button"
                        onClick={addPeriod}
                        className="inline-flex items-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-sm font-semibold text-[var(--app-text)]"
                    >
                        Ajouter une période
                    </button>
                </div>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    <label className="space-y-1.5">
                        <span className="text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">À partir du</span>
                        <input
                        type="date"
                        value={form.data.start_date}
                        onChange={(event) => form.setData('start_date', event.target.value)}
                        min={startDateMin}
                        max={startDateMax}
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)]"
                    />
                    {form.errors.start_at ? (
                        <p className="text-xs text-red-600">{form.errors.start_at}</p>
                    ) : null}
                </label>

                <label className="space-y-1.5">
                    <span className="text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">Jusqu'au</span>
                    <input
                        type="date"
                        value={form.data.end_date}
                        onChange={(event) => form.setData('end_date', event.target.value)}
                        min={endDateMin}
                        max={endDateMax}
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)]"
                    />
                    {form.errors.end_at ? (
                        <p className="text-xs text-red-600">{form.errors.end_at}</p>
                    ) : null}
                </label>
                </div>
            )}

            {!allowMultiplePeriods && isSingleDayRequest ? (
                <div className="grid gap-3 sm:grid-cols-3">
                    {SINGLE_DAY_OPTIONS.map((option) => {
                        const isSelected = singleDayPortionSelection === option.value;
                        return (
                            <button
                                key={`single-day-${option.value}`}
                                type="button"
                                onClick={() => form.setData({
                                    ...form.data,
                                    start_portion: option.value,
                                    end_portion: option.value,
                                })}
                                className="flex flex-col items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3 text-center"
                            >
                                <span
                                    className={`flex h-5 w-5 items-center justify-center rounded-[4px] border text-[12px] leading-none transition-all duration-200 ${
                                        isSelected
                                            ? 'border-[var(--brand-brown)] bg-[var(--app-surface)] text-[var(--app-text)]'
                                            : 'border-[var(--app-border)] bg-[var(--app-surface)] text-transparent'
                                    }`}
                                >
                                    {isSelected ? '✔' : ''}
                                </span>
                                <span className="text-sm text-[var(--app-text)]">{option.label}</span>
                            </button>
                        );
                    })}
                </div>
            ) : !allowMultiplePeriods ? (
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {MULTI_DAY_OPTIONS.map((option) => {
                            const isSelected = multiDayStartSelection === option.value;
                            return (
                                <button
                                    key={`multi-day-start-${option.value}`}
                                    type="button"
                                    onClick={() => form.setData('start_portion', option.value)}
                                    className="flex flex-col items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3 text-center"
                                >
                                    <span
                                        className={`flex h-5 w-5 items-center justify-center rounded-[4px] border text-[12px] leading-none transition-all duration-200 ${
                                            isSelected
                                                ? 'border-[var(--brand-brown)] bg-[var(--app-surface)] text-[var(--app-text)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-transparent'
                                        }`}
                                    >
                                        {isSelected ? '✔' : ''}
                                    </span>
                                    <span className="text-sm text-[var(--app-text)]">{option.label}</span>
                                </button>
                            );
                        })}
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        {MULTI_DAY_OPTIONS.map((option) => {
                            const isSelected = multiDayEndSelection === option.value;
                            return (
                                <button
                                    key={`multi-day-end-${option.value}`}
                                    type="button"
                                    onClick={() => form.setData('end_portion', option.value)}
                                    className="flex flex-col items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3 text-center"
                                >
                                    <span
                                        className={`flex h-5 w-5 items-center justify-center rounded-[4px] border text-[12px] leading-none transition-all duration-200 ${
                                            isSelected
                                                ? 'border-[var(--brand-brown)] bg-[var(--app-surface)] text-[var(--app-text)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-transparent'
                                        }`}
                                    >
                                        {isSelected ? '✔' : ''}
                                    </span>
                                    <span className="text-sm text-[var(--app-text)]">{option.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            ) : null}

            {!allowMultiplePeriods ? (
                <label className="space-y-1.5">
                <span className="text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">Infos supplémentaires</span>
                <textarea
                    value={form.data.message}
                    onChange={(event) => form.setData('message', event.target.value)}
                    rows={2}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)]"
                    placeholder="Préciser un contexte si nécessaire…"
                />
                {form.errors.message ? (
                    <p className="text-xs text-red-600">{form.errors.message}</p>
                ) : null}
                </label>
            ) : null}

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={form.processing}
                    className="inline-flex items-center rounded-xl border border-[var(--app-border)] bg-[var(--app-accent)] px-4 py-2 text-sm font-semibold text-[var(--app-accent-contrast)] disabled:opacity-60"
                >
                    {form.processing ? 'Envoi…' : 'Envoyer la demande'}
                </button>
            </div>
        </form>
    );
}
