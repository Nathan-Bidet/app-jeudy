import AppLayout from '@/Layouts/AppLayout';
import LeaveRequestForm from '@/Pages/Leaves/Components/LeaveRequestForm';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PlaceActionsLink from '@/Components/PlaceActionsLink';
import TextInput from '@/Components/TextInput';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Pencil, Plus, Trash2, Globe, X, Play, Square, Clock3 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const WEEK_LABELS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

function toDate(value) {
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return new Date();
    }
    return parsed;
}

function toIsoDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function toDateOnly(value) {
    if (!value) return null;
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return null;
    return new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
}

function toDateTime(value) {
    if (!value) return null;
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return null;
    return parsed;
}

function addDays(date, days) {
    const next = new Date(date);
    next.setDate(next.getDate() + days);
    return next;
}

function diffDays(start, end) {
    const ms = end.getTime() - start.getTime();
    return Math.floor(ms / 86400000);
}

function maxDate(left, right) {
    return left.getTime() >= right.getTime() ? left : right;
}

function minDate(left, right) {
    return left.getTime() <= right.getTime() ? left : right;
}

function normalizeEventDateRange(event) {
    const startRaw = event?.start_at_local || event?.start_at;
    const endRaw = event?.end_at_local || event?.end_at || startRaw;
    const startDate = toDateOnly(startRaw);
    let endDate = toDateOnly(endRaw);

    if (!startDate) return null;
    if (!endDate) return { startDate, endDate: startDate };

    // Defensive normalization: some all-day sources provide an exclusive end day.
    if (event?.all_day && (event?.end_at_local || event?.end_at)) {
        const endDateTime = toDate(event?.end_at_local || event?.end_at);
        const isExclusiveMidnightEnd = (
            !Number.isNaN(endDateTime.getTime())
            && endDateTime.getHours() === 0
            && endDateTime.getMinutes() === 0
            && endDateTime.getSeconds() === 0
            && endDate.getTime() > startDate.getTime()
        );

        if (isExclusiveMidnightEnd) {
            endDate = addDays(endDate, -1);
        }
    }

    if (endDate.getTime() < startDate.getTime()) {
        return { startDate, endDate: startDate };
    }

    return { startDate, endDate };
}

function normalizeCalendarEvent(event) {
    const range = normalizeEventDateRange(event);
    if (!range) return null;

    const startRaw = event?.start_at_local || event?.start_at;
    const endRaw = event?.end_at_local || event?.end_at || startRaw;
    const startDateTime = toDateTime(startRaw) || new Date(range.startDate.getTime());
    const fallbackEnd = event?.all_day ? addDays(range.endDate, 1) : startDateTime;
    let endDateTime = toDateTime(endRaw) || fallbackEnd;
    if (endDateTime.getTime() <= startDateTime.getTime()) {
        endDateTime = new Date(startDateTime.getTime() + (event?.all_day ? 86400000 : 30 * 60000));
    }

    const sourceType = event?.source === 'feed' || event?.is_external ? 'public' : 'internal';
    const isAllDay = Boolean(event?.all_day);
    const isMultiDay = range.startDate.getTime() !== range.endDate.getTime();
    // Week multi-day layer must only contain true multi-day spans.
    // Single-day all-day events belong to the day simple stack.
    const isWeekLayerEvent = isMultiDay;

    const eventId = String(event?.id || '');
    const isLeaveEvent = eventId.startsWith('leave-');
    const leaveUserLabel = (
        event?.target_label
        || event?.requester_label
        || event?.user_label
        || event?.target_user_label
        || event?.requester_user_label
        || event?.target_user?.name
        || event?.requester_user?.name
        || event?.user?.name
        || ''
    );
    const normalizedTitle = isLeaveEvent
        ? (leaveUserLabel ? `Congés ${leaveUserLabel}` : (event?.title || 'Congés'))
        : event?.title;

    return {
        ...event,
        title: normalizedTitle,
        start: range.startDate,
        end: range.endDate,
        startDateTime,
        endDateTime,
        startKey: toIsoDate(range.startDate),
        endKey: toIsoDate(range.endDate),
        isAllDay,
        isMultiDay,
        isWeekLayerEvent,
        sourceType,
    };
}

function sortDayEvents(left, right) {
    if (left.isWeekLayerEvent !== right.isWeekLayerEvent) {
        return left.isWeekLayerEvent ? -1 : 1;
    }

    const startDiff = left.start.getTime() - right.start.getTime();
    if (startDiff !== 0) return startDiff;

    return String(left.title || '').localeCompare(String(right.title || ''), 'fr');
}

function buildEventsByDay(normalizedEvents) {
    const map = new Map();

    normalizedEvents.forEach((eventItem) => {
        let cursor = eventItem.start;
        while (cursor.getTime() <= eventItem.end.getTime()) {
            const dayKey = toIsoDate(cursor);
            if (!map.has(dayKey)) {
                map.set(dayKey, []);
            }
            map.get(dayKey).push(eventItem);
            cursor = addDays(cursor, 1);
        }
    });

    map.forEach((items) => {
        items.sort(sortDayEvents);
    });

    return map;
}

function buildWeekMultiDayLanes(weekDates, normalizedEvents) {
    const weekStart = weekDates[0];
    const weekEnd = weekDates[6];
    const rawSegments = [];

    normalizedEvents.forEach((eventItem) => {
        if (!eventItem.isWeekLayerEvent) return;
        if (eventItem.end.getTime() < weekStart.getTime() || eventItem.start.getTime() > weekEnd.getTime()) return;

        const segmentStart = maxDate(eventItem.start, weekStart);
        const segmentEnd = minDate(eventItem.end, weekEnd);
        const colStart = diffDays(weekStart, segmentStart) + 1;
        const colEnd = diffDays(weekStart, segmentEnd) + 2;

        rawSegments.push({
            event: eventItem,
            colStart,
            colEnd,
            startsBeforeWeek: eventItem.start.getTime() < weekStart.getTime(),
            endsAfterWeek: eventItem.end.getTime() > weekEnd.getTime(),
        });
    });

    rawSegments.sort((left, right) => {
        if (left.colStart !== right.colStart) return left.colStart - right.colStart;
        const leftWidth = left.colEnd - left.colStart;
        const rightWidth = right.colEnd - right.colStart;
        return rightWidth - leftWidth;
    });

    const lanes = [];
    rawSegments.forEach((segment) => {
        let laneIndex = -1;
        for (let index = 0; index < lanes.length; index += 1) {
            const hasOverlap = lanes[index].some((existing) => (
                segment.colStart < existing.colEnd && segment.colEnd > existing.colStart
            ));
            if (!hasOverlap) {
                laneIndex = index;
                break;
            }
        }

        if (laneIndex === -1) {
            lanes.push([segment]);
            return;
        }

        lanes[laneIndex].push(segment);
    });

    return lanes;
}

function isRenderableSimpleEvent(eventItem) {
    return String(eventItem?.title || '').trim() !== '';
}

function buildDayCombinedRows({ multiDayLaneSlots, singleDayEvents, totalDayEvents, maxPerDay = 4 }) {
    const rows = [];
    let shownRealEvents = 0;
    let simpleCursor = 0;

    // Preserve weekly lane index for multi-day events, but let single-day events fill empty lane slots.
    for (const segment of multiDayLaneSlots) {
        if (rows.length >= maxPerDay) break;
        if (segment) {
            rows.push({ type: 'multi-event', segment, event: segment.event });
            shownRealEvents += 1;
        } else {
            const nextSimple = singleDayEvents[simpleCursor];
            if (nextSimple) {
                rows.push({ type: 'event', event: nextSimple });
                simpleCursor += 1;
                shownRealEvents += 1;
            } else {
                rows.push({ type: 'multi-placeholder' });
            }
        }
    }

    for (let index = simpleCursor; index < singleDayEvents.length; index += 1) {
        const eventItem = singleDayEvents[index];
        if (rows.length >= maxPerDay) break;
        const hiddenIfRendered = totalDayEvents - (shownRealEvents + 1);
        const slotsAfterRender = maxPerDay - (rows.length + 1);
        if (hiddenIfRendered > 0 && slotsAfterRender === 0) break;
        rows.push({ type: 'event', event: eventItem });
        shownRealEvents += 1;
    }

    let hiddenCount = Math.max(0, totalDayEvents - shownRealEvents);
    if (hiddenCount > 0) {
        if (rows.length < maxPerDay) {
            rows.push({ type: 'overflow', overflowCount: hiddenCount });
        } else {
            let replaceIndex = -1;

            // 1) Replace an empty reserved multi-day slot first.
            for (let index = rows.length - 1; index >= 0; index -= 1) {
                if (rows[index]?.type === 'multi-placeholder') {
                    replaceIndex = index;
                    break;
                }
            }

            // 2) Then replace a single-day row to keep multi-day continuity visible.
            if (replaceIndex === -1) {
                for (let index = rows.length - 1; index >= 0; index -= 1) {
                    if (rows[index]?.type === 'event') {
                        replaceIndex = index;
                        hiddenCount += 1;
                        break;
                    }
                }
            }

            // 3) Fallback: replace last row.
            if (replaceIndex === -1) {
                replaceIndex = rows.length - 1;
                hiddenCount += 1;
            }

            rows[replaceIndex] = { type: 'overflow', overflowCount: hiddenCount };
        }
    }

    return rows.slice(0, maxPerDay);
}

function buildMonthWeekRow(weekDates, normalizedEvents, eventsByDayMap, maxPerDay = 4) {
    const lanes = buildWeekMultiDayLanes(weekDates, normalizedEvents);
    const multiDayLaneSlotsByDay = Array.from(
        { length: 7 },
        () => Array.from({ length: lanes.length }, () => null),
    );
    lanes.forEach((lane, laneIndex) => {
        lane.forEach((segment) => {
            for (let col = segment.colStart; col < segment.colEnd; col += 1) {
                const dayIndex = col - 1;
                if (dayIndex >= 0 && dayIndex < 7) {
                    multiDayLaneSlotsByDay[dayIndex][laneIndex] = segment;
                }
            }
        });
    });

    const dayCells = weekDates.map((date, dayIndex) => {
        const dayKey = toIsoDate(date);
        const dayEvents = eventsByDayMap.get(dayKey) || [];
        const renderableDayEvents = dayEvents.filter((eventItem) => isRenderableSimpleEvent(eventItem));
        const singleDayEvents = renderableDayEvents.filter((eventItem) => (
            !eventItem.isWeekLayerEvent
            && eventItem.startKey === dayKey
        ));
        const combinedRows = buildDayCombinedRows({
            multiDayLaneSlots: multiDayLaneSlotsByDay[dayIndex] || [],
            singleDayEvents,
            totalDayEvents: renderableDayEvents.length,
            maxPerDay,
        });

        return {
            date,
            dayKey,
            combinedRows,
        };
    });

    const simpleRowCount = maxPerDay;

    return {
        weekKey: toIsoDate(weekDates[0]),
        dayCells,
        simpleRowCount,
    };
}

function startOfWeek(date) {
    const base = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const jsDay = base.getDay();
    const mondayOffset = jsDay === 0 ? -6 : 1 - jsDay;
    base.setDate(base.getDate() + mondayOffset);
    return base;
}

function isSameDay(a, b) {
    return (
        a.getFullYear() === b.getFullYear()
        && a.getMonth() === b.getMonth()
        && a.getDate() === b.getDate()
    );
}

function buildMonthGrid(anchorDate) {
    const monthStart = new Date(anchorDate.getFullYear(), anchorDate.getMonth(), 1);
    const gridStart = startOfWeek(monthStart);
    const cells = [];

    for (let index = 0; index < 42; index += 1) {
        const date = new Date(gridStart);
        date.setDate(gridStart.getDate() + index);
        cells.push(date);
    }

    return cells;
}

function buildWeekDays(anchorDate) {
    const weekStart = startOfWeek(anchorDate);
    return Array.from({ length: 7 }, (_, index) => {
        const date = new Date(weekStart);
        date.setDate(weekStart.getDate() + index);
        return date;
    });
}

function getIsoWeekNumber(date) {
    const temp = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNumber = temp.getUTCDay() || 7;
    temp.setUTCDate(temp.getUTCDate() + 4 - dayNumber);
    const yearStart = new Date(Date.UTC(temp.getUTCFullYear(), 0, 1));
    return Math.ceil((((temp - yearStart) / 86400000) + 1) / 7);
}

function minutesSinceMidnight(date) {
    return (date.getHours() * 60) + date.getMinutes() + (date.getSeconds() / 60);
}

function formatHourMinute(date) {
    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function rangesOverlap(startA, endA, startB, endB) {
    return startA < endB && endA > startB;
}

function buildWeekAllDayLanes(weekDays, normalizedEvents) {
    const weekStart = weekDays[0];
    const weekEnd = weekDays[6];
    const segments = [];

    normalizedEvents.forEach((eventItem) => {
        if (!eventItem.isAllDay) return;
        if (eventItem.end.getTime() < weekStart.getTime() || eventItem.start.getTime() > weekEnd.getTime()) return;

        const segmentStart = maxDate(eventItem.start, weekStart);
        const segmentEnd = minDate(eventItem.end, weekEnd);
        segments.push({
            event: eventItem,
            colStart: diffDays(weekStart, segmentStart),
            colEnd: diffDays(weekStart, segmentEnd) + 1,
        });
    });

    segments.sort((left, right) => {
        if (left.colStart !== right.colStart) return left.colStart - right.colStart;
        return (right.colEnd - right.colStart) - (left.colEnd - left.colStart);
    });

    const lanes = [];
    segments.forEach((segment) => {
        let laneIndex = -1;
        for (let index = 0; index < lanes.length; index += 1) {
            const overlaps = lanes[index].some((existing) => (
                rangesOverlap(segment.colStart, segment.colEnd, existing.colStart, existing.colEnd)
            ));
            if (!overlaps) {
                laneIndex = index;
                break;
            }
        }

        if (laneIndex === -1) {
            lanes.push([segment]);
        } else {
            lanes[laneIndex].push(segment);
        }
    });

    return lanes;
}

function buildWeekTimedByDay(weekDays, normalizedEvents) {
    const byDay = weekDays.map((day) => {
        const dayStart = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 0, 0, 0, 0);
        const dayEnd = addDays(dayStart, 1);
        const dayKey = toIsoDate(dayStart);
        const items = [];

        normalizedEvents.forEach((eventItem) => {
            if (eventItem.isAllDay) return;
            const start = eventItem.startDateTime;
            const end = eventItem.endDateTime;
            if (!start || !end) return;
            if (!rangesOverlap(start.getTime(), end.getTime(), dayStart.getTime(), dayEnd.getTime())) return;

            const segmentStart = new Date(Math.max(start.getTime(), dayStart.getTime()));
            const segmentEnd = new Date(Math.min(end.getTime(), dayEnd.getTime()));
            const startMinutes = segmentStart.getTime() <= dayStart.getTime()
                ? 0
                : Math.max(0, Math.min(1440, minutesSinceMidnight(segmentStart)));
            const rawEndMinutes = segmentEnd.getTime() >= dayEnd.getTime()
                ? 1440
                : Math.max(0, Math.min(1440, minutesSinceMidnight(segmentEnd)));
            const endMinutes = Math.max(startMinutes + 1, rawEndMinutes);
            const startDay = new Date(start.getFullYear(), start.getMonth(), start.getDate());
            const endEffective = new Date(Math.max(start.getTime(), end.getTime() - 1));
            const endDay = new Date(endEffective.getFullYear(), endEffective.getMonth(), endEffective.getDate());
            const startDayKey = toIsoDate(startDay);
            const endDayKey = toIsoDate(endDay);
            const totalTouchedDays = Math.max(1, diffDays(startDay, endDay) + 1);
            const startsHere = dayKey === startDayKey;
            const endsHere = dayKey === endDayKey;

            let timeLabel = '';
            let timeLabelType = null;
            if (totalTouchedDays === 1) {
                timeLabel = `${formatHourMinute(start)} - ${formatHourMinute(end)}`;
                timeLabelType = 'range';
            } else if (totalTouchedDays === 2) {
                if (startsHere) {
                    timeLabel = formatHourMinute(start);
                    timeLabelType = 'start';
                }
                if (endsHere) {
                    timeLabel = formatHourMinute(end);
                    timeLabelType = 'end';
                }
            } else {
                if (startsHere) {
                    timeLabel = formatHourMinute(start);
                    timeLabelType = 'start';
                }
                if (endsHere) {
                    timeLabel = formatHourMinute(end);
                    timeLabelType = 'end';
                }
            }

            items.push({
                event: eventItem,
                startMinutes,
                endMinutes,
                startsHere,
                endsHere,
                totalTouchedDays,
                timeLabel,
                timeLabelType,
                lane: 0,
                lanesInDay: 1,
            });
        });

        items.sort((left, right) => (
            left.startMinutes - right.startMinutes || right.endMinutes - left.endMinutes
        ));

        const laneEnds = [];
        items.forEach((item) => {
            let laneIndex = laneEnds.findIndex((endMinute) => item.startMinutes >= endMinute);
            if (laneIndex === -1) {
                laneIndex = laneEnds.length;
                laneEnds.push(item.endMinutes);
            } else {
                laneEnds[laneIndex] = item.endMinutes;
            }
            item.lane = laneIndex;
        });

        const lanesCount = Math.max(1, laneEnds.length);
        const itemsByLane = Array.from({ length: lanesCount }, () => []);
        items.forEach((item) => {
            itemsByLane[item.lane].push(item);
        });

        items.forEach((item) => {
            item.lanesInDay = lanesCount;
            let columnSpan = 1;

            for (let lane = item.lane + 1; lane < lanesCount; lane += 1) {
                const overlapsInLane = itemsByLane[lane].some((other) => (
                    rangesOverlap(item.startMinutes, item.endMinutes, other.startMinutes, other.endMinutes)
                ));
                if (overlapsInLane) break;
                columnSpan += 1;
            }

            item.columnSpan = columnSpan;
        });

        return items;
    });

    return byDay;
}

function toLocalDateTimeInput(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function normalizeDateOnlyToLocalDateTime(value) {
    if (!value) return '';

    return `${value}T09:00`;
}

function buildDefaultEventBounds(baseDate = new Date()) {
    const day = new Date(baseDate);
    day.setSeconds(0, 0);

    const start = new Date(day);
    start.setHours(8, 0, 0, 0);

    const end = new Date(day);
    end.setHours(9, 0, 0, 0);

    return {
        startAt: toLocalDateTimeInput(start),
        endAt: toLocalDateTimeInput(end),
    };
}

function hexToRgb(color) {
    if (!color || typeof color !== 'string') return null;
    const normalized = color.trim().replace('#', '');
    const full = normalized.length === 3
        ? normalized.split('').map((segment) => segment + segment).join('')
        : normalized;

    if (!/^[0-9a-fA-F]{6}$/.test(full)) return null;

    return {
        r: Number.parseInt(full.slice(0, 2), 16),
        g: Number.parseInt(full.slice(2, 4), 16),
        b: Number.parseInt(full.slice(4, 6), 16),
    };
}

function categoryPillStyle(color, selected = false) {
    const rgb = hexToRgb(color);
    if (!rgb) {
        return selected
            ? { backgroundColor: 'rgba(15,105,48,0.18)', borderColor: '#0F6930', color: '#0F6930' }
            : { backgroundColor: 'rgba(143,128,108,0.10)', borderColor: 'rgba(143,128,108,0.45)', color: '#4D3F31' };
    }

    const bgAlpha = selected ? 0.22 : 0.14;
    const text = (rgb.r * 0.299 + rgb.g * 0.587 + rgb.b * 0.114) > 160 ? '#111111' : '#ffffff';

    return {
        backgroundColor: `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${bgAlpha})`,
        borderColor: color,
        color: selected ? text : color,
    };
}

function eventPillStyle(eventItem, selected = false) {
    const eventId = String(eventItem?.id || '');
    const isLeaveEvent = eventId.startsWith('leave-');

    if (isLeaveEvent) {
        const backgroundColorRaw = String(eventItem?.backgroundColor || '').trim();
        const borderColorRaw = String(eventItem?.borderColor || '').trim();
        const textColorRaw = String(eventItem?.textColor || '').trim();
        const baseColor = borderColorRaw || textColorRaw || backgroundColorRaw;
        const rgb = hexToRgb(baseColor);

        if (backgroundColorRaw || borderColorRaw || textColorRaw) {
            return {
                backgroundColor: rgb
                    ? `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.10)`
                    : (backgroundColorRaw || 'rgba(143,128,108,0.10)'),
                borderColor: baseColor || 'rgba(143,128,108,0.45)',
                color: baseColor || '#4D3F31',
            };
        }
    }

    return categoryPillStyle(eventItem?.category?.color, selected);
}

function getDesktopDayRender(dayEvents) {
    const maxVisible = 4;

    if (dayEvents.length <= maxVisible) {
        return {
            visible: dayEvents,
            overflowCount: 0,
        };
    }

    return {
        visible: dayEvents.slice(0, 3),
        overflowCount: dayEvents.length - 3,
    };
}

function MonthDesktopWeekTable({ weekRow, today, anchorDate, openDayEventsModal, openEditModal, eventTextFontStyle }) {
    const getCellBg = (date) => (date.getMonth() === anchorDate.getMonth() ? 'bg-[var(--app-surface)]' : 'bg-[#f0ece7]');

    return (
        <div className="border-t border-[var(--app-border)]">
            <table className="w-full table-fixed border-collapse">
                <tbody>
                    <tr className="h-[40px]">
                        {weekRow.dayCells.map((cell) => {
                            const isToday = isSameDay(cell.date, today);
                            return (
                                <td
                                    key={`day-head-${cell.dayKey}`}
                                    className={`cursor-pointer border-r border-[var(--app-border)] px-2 pt-2 align-top last:border-r-0 ${getCellBg(cell.date)}`}
                                    onClick={() => openDayEventsModal(cell.dayKey)}
                                >
                                    <span
                                        className={`inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold ${
                                            isToday
                                                ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : cell.date.getMonth() === anchorDate.getMonth()
                                                    ? 'text-[var(--app-text)]'
                                                    : 'text-[var(--app-muted)]'
                                        }`}
                                    >
                                        {cell.date.getDate()}
                                    </span>
                                </td>
                            );
                        })}
                    </tr>

                    {Array.from({ length: weekRow.simpleRowCount }).map((_, rowIndex) => (
                        <tr key={`simple-row-${weekRow.weekKey}-${rowIndex}`} className="h-[22px]">
                            {weekRow.dayCells.map((cell, dayIndex) => {
                                const row = cell.combinedRows[rowIndex] || null;
                                return (
                                    <td
                                        key={`simple-cell-${cell.dayKey}-${rowIndex}`}
                                        className={`cursor-pointer overflow-visible border-r border-[var(--app-border)] align-middle last:border-r-0 ${getCellBg(cell.date)}`}
                                        onClick={() => openDayEventsModal(cell.dayKey)}
                                    >
                                        {row?.type === 'multi-event' ? (
                                            (() => {
                                                const dayCol = dayIndex + 1;
                                                const isSegmentStart = dayCol === row.segment.colStart;
                                                const continuesLeft = dayCol > row.segment.colStart;
                                                const continuesRight = dayCol < (row.segment.colEnd - 1);
                                                const spanDays = row.segment.colEnd - row.segment.colStart;
                                                // Keep week-edge breathing room even for continued multi-week events.
                                                // Internal day separators are still covered by the overlap term in width.
                                                const leftInsetPx = 2;
                                                const rightInsetPx = 2;
                                                const categoryColor = row.segment.event?.category?.color || null;
                                                const rgb = hexToRgb(categoryColor);
                                                const opaqueMultiBg = rgb
                                                    ? `rgb(${Math.round(255 - (255 - rgb.r) * 0.14)}, ${Math.round(255 - (255 - rgb.g) * 0.14)}, ${Math.round(255 - (255 - rgb.b) * 0.14)})`
                                                    : 'rgb(243, 238, 232)';
                                                const isLeaveSegment = String(row.segment.event?.id || '').startsWith('leave-');

                                                if (!isSegmentStart) return null;

                                                return (
                                                    <button
                                                        type="button"
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            openEditModal(event, row.segment.event.id);
                                                        }}
                                                        className={`relative z-[2] block h-[20px] overflow-hidden whitespace-nowrap border px-[5px] py-0 text-left text-[10px] font-normal leading-[18px] hover:brightness-95 md:text-[12px] ${
                                                            (row.segment.startsBeforeWeek || continuesLeft) ? 'rounded-l-none' : 'rounded-l-md'
                                                        } ${
                                                            row.segment.endsAfterWeek ? 'rounded-r-none' : 'rounded-r-md'
                                                        }`}
                                                        style={{
                                                            ...eventPillStyle(row.segment.event, false),
                                                            ...eventTextFontStyle,
                                                            ...(isLeaveSegment ? {} : { backgroundColor: opaqueMultiBg }),
                                                            marginLeft: `${leftInsetPx}px`,
                                                            width: `calc(${spanDays * 100}% - ${leftInsetPx + rightInsetPx}px + ${(spanDays - 1) * 2}px)`,
                                                            borderLeftWidth: row.segment.startsBeforeWeek ? '0px' : '1px',
                                                            borderRightWidth: row.segment.endsAfterWeek ? '0px' : '1px',
                                                        }}
                                                        title={row.segment.event?.feed_name ? `${row.segment.event.title} · ${row.segment.event.feed_name}` : row.segment.event.title}
                                                    >
                                                        {row.segment.event.title}
                                                    </button>
                                                );
                                            })()
                                        ) : null}
                                        {row?.type === 'event' ? (
                                            <button
                                                type="button"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    openEditModal(event, row.event.id);
                                                }}
                                                className="mx-[2px] block h-[20px] w-[calc(100%-4px)] overflow-hidden whitespace-nowrap text-clip rounded-md border px-[3px] py-0 text-left text-[10px] font-normal leading-[18px] hover:brightness-95 md:text-[12px]"
                                                style={{ ...eventPillStyle(row.event, false), ...eventTextFontStyle }}
                                                title={row.event?.feed_name ? `${row.event.title} · ${row.event.feed_name}` : row.event.title}
                                            >
                                                {row.event.title}
                                            </button>
                                        ) : null}
                                        {row?.type === 'overflow' ? (
                                            <button
                                                type="button"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    openDayEventsModal(cell.dayKey);
                                                }}
                                                className="mx-[2px] block h-[20px] w-[calc(100%-4px)] overflow-hidden whitespace-nowrap rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-[4px] py-0 text-left text-[9px] font-normal leading-[16px] text-[var(--app-muted)] hover:brightness-95 md:px-[5px] md:text-[12px] md:leading-[18px]"
                                            >
                                                +{row.overflowCount} autres
                                            </button>
                                        ) : null}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}

                </tbody>
            </table>
        </div>
    );
}

function WeekDesktopTimeGrid({ weekDays, today, normalizedEvents, openDayEventsModal, openEditModal, eventTextFontStyle, toolbarHeight = 72 }) {
    const weekStart = weekDays[0];
    const weekNumber = getIsoWeekNumber(weekStart);
    const allDayLanes = buildWeekAllDayLanes(weekDays, normalizedEvents);
    const timedByDay = buildWeekTimedByDay(weekDays, normalizedEvents);
    const allDayRows = Math.max(1, allDayLanes.length);
    const allDayRowHeight = 24;
    const hourHeight = 56;
    const minuteHeight = hourHeight / 60;
    const dayBodyHeight = 24 * hourHeight;
    const targetMinuteOnLoad = (7 * 60) + 25;
    const hours = Array.from({ length: 24 }, (_, index) => index);
    const todayKey = toIsoDate(today);
    const currentDayIndex = weekDays.findIndex((day) => toIsoDate(day) === todayKey);
    const nowMinutes = minutesSinceMidnight(today);
    const initialScrollMarkerRef = useRef(null);
    const didAutoScrollRef = useRef(false);

    useEffect(() => {
        if (didAutoScrollRef.current) return;
        if (typeof window === 'undefined') return;
        if (window.innerWidth < 768) return;
        const marker = initialScrollMarkerRef.current;
        if (!marker) return;

        const rafId = window.requestAnimationFrame(() => {
            marker.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
            didAutoScrollRef.current = true;
        });

        return () => window.cancelAnimationFrame(rafId);
    }, []);

    return (
        <div className="hidden border-t border-[var(--app-border)] md:block">
            <div
                className="sticky z-20 bg-[var(--app-surface)]"
                style={{ top: `calc(var(--app-navbar-height,72px) + ${toolbarHeight}px)` }}
            >
                <div className="grid border-b border-[var(--app-border)] bg-[var(--app-surface-soft)]" style={{ gridTemplateColumns: '72px repeat(7, minmax(0, 1fr))' }}>
                    <div className="border-r border-[var(--app-border)] px-2 py-3 text-center text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        S{weekNumber}
                    </div>
                    {weekDays.map((day, dayIndex) => {
                        const dayKey = toIsoDate(day);
                        const isToday = dayKey === todayKey;
                        const dayNumber = day.getDate();
                        return (
                            <button
                                key={`week-head-${dayKey}`}
                                type="button"
                                onClick={() => openDayEventsModal(dayKey)}
                                className="border-r border-[var(--app-border)] px-2 py-2 text-center last:border-r-0"
                            >
                                <span className="inline-flex items-center justify-center gap-2 text-sm font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                    <span>{WEEK_LABELS[dayIndex]}</span>
                                    {isToday ? (
                                        <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--brand-yellow-dark)] text-sm font-black leading-none tracking-normal text-[var(--app-muted)]">
                                            {dayNumber}
                                        </span>
                                    ) : (
                                        <span>{dayNumber}</span>
                                    )}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="grid border-b border-[var(--app-border)] bg-[var(--app-surface)]" style={{ gridTemplateColumns: '72px repeat(7, minmax(0, 1fr))' }}>
                    <div className="border-r border-[var(--app-border)] px-2 py-1 text-[11px] font-bold text-[var(--app-muted)]">
                        Toute la journée
                    </div>
                    <div className="relative col-span-7" style={{ height: `${allDayRows * allDayRowHeight}px` }}>
                        <div className="absolute inset-0 grid" style={{ gridTemplateColumns: 'repeat(7, minmax(0, 1fr))' }}>
                            {weekDays.map((day) => (
                                <button
                                    key={`all-day-bg-${toIsoDate(day)}`}
                                    type="button"
                                    onClick={() => openDayEventsModal(toIsoDate(day))}
                                    className="border-r border-[var(--app-border)] last:border-r-0"
                                    aria-label={`Voir ${toIsoDate(day)}`}
                                />
                            ))}
                        </div>
                        {allDayLanes.flatMap((lane, laneIndex) => lane.map((segment) => {
                            const leftPct = (segment.colStart / 7) * 100;
                            const widthPct = ((segment.colEnd - segment.colStart) / 7) * 100;
                            return (
                            <button
                                key={`all-day-segment-${segment.event.id}-${laneIndex}-${segment.colStart}`}
                                type="button"
                                onClick={(event) => openEditModal(event, segment.event.id)}
                                    className="absolute z-[2] h-[20px] overflow-hidden whitespace-nowrap rounded-md border px-1.5 py-0 text-left text-[12px] font-normal leading-[18px] hover:brightness-95"
                                    style={{
                                        ...eventPillStyle(segment.event, false),
                                        ...eventTextFontStyle,
                                        top: `${laneIndex * allDayRowHeight + 2}px`,
                                        left: `calc(${leftPct}% + 2px)`,
                                        width: `calc(${widthPct}% - 4px)`,
                                    }}
                                    title={segment.event?.feed_name ? `${segment.event.title} · ${segment.event.feed_name}` : segment.event.title}
                                >
                                    {segment.event.title}
                                </button>
                            );
                        }))}
                    </div>
                </div>
            </div>

            <div className="grid bg-[var(--app-surface)]" style={{ gridTemplateColumns: '72px repeat(7, minmax(0, 1fr))' }}>
                <div className="relative border-r border-[var(--app-border)] bg-[var(--app-surface-soft)]" style={{ height: `${dayBodyHeight}px` }}>
                    {hours.map((hour) => (
                        hour === 0 ? null : (
                            <div
                                key={`hour-label-${hour}`}
                                className="absolute right-2 -translate-y-1/2 text-[11px] font-semibold text-[var(--app-muted)]"
                                style={{ top: `${hour * hourHeight}px` }}
                            >
                                {String(hour).padStart(2, '0')}:00
                            </div>
                        )
                    ))}
                    {currentDayIndex >= 0 ? (
                        <div
                            className="absolute right-1 z-[4] -translate-y-1/2 rounded-full bg-red-500 px-1.5 py-[1px] text-[10px] font-bold text-white"
                            style={{ top: `${nowMinutes * minuteHeight}px` }}
                        >
                            {String(today.getHours()).padStart(2, '0')}:{String(today.getMinutes()).padStart(2, '0')}
                        </div>
                    ) : null}
                </div>

                <div className="relative col-span-7" style={{ height: `${dayBodyHeight}px` }}>
                    <div
                        ref={initialScrollMarkerRef}
                        className="absolute left-0 right-0 pointer-events-none"
                        style={{
                            top: `${targetMinuteOnLoad * minuteHeight}px`,
                            scrollMarginTop: `calc(var(--app-navbar-height,72px) + ${toolbarHeight + 48 + (allDayRows * allDayRowHeight) + 10}px)`,
                        }}
                    />
                    <div className="absolute inset-0 grid" style={{ gridTemplateColumns: 'repeat(7, minmax(0, 1fr))' }}>
                        {weekDays.map((day) => (
                            <button
                                key={`week-day-bg-${toIsoDate(day)}`}
                                type="button"
                                onClick={() => openDayEventsModal(toIsoDate(day))}
                                className="border-r border-[var(--app-border)] last:border-r-0"
                                aria-label={`Voir ${toIsoDate(day)}`}
                            />
                        ))}
                    </div>

                    {hours.map((hour) => (
                        <div
                            key={`hour-line-${hour}`}
                            className="absolute left-0 right-0 border-t border-[var(--app-border)]"
                            style={{ top: `${hour * hourHeight}px` }}
                        />
                    ))}

                    {currentDayIndex >= 0 ? (
                        <div
                            className="absolute left-0 right-0 z-[3] border-t border-red-500/70"
                            style={{ top: `${nowMinutes * minuteHeight}px` }}
                        />
                    ) : null}

                    {timedByDay.flatMap((items, dayIndex) => items.map((segment, itemIndex) => {
                        const columnLeft = (dayIndex / 7) * 100;
                        const columnWidth = (100 / 7);
                        const laneWidth = columnWidth / segment.lanesInDay;
                        const top = segment.startMinutes * minuteHeight;
                        const height = Math.max(18, (segment.endMinutes - segment.startMinutes) * minuteHeight);
                        const span = Math.max(1, segment.columnSpan || 1);
                        const leftInset = segment.lanesInDay > 1 ? 1 : 2;
                        const rightInset = segment.lanesInDay > 1 ? 1 : 2;
                        const textLeftInset = segment.lanesInDay > 1 ? 1 : 6;

                        return (
                            <button
                                key={`timed-${segment.event.id}-${dayIndex}-${itemIndex}`}
                                type="button"
                                onClick={(event) => openEditModal(event, segment.event.id)}
                                className="absolute z-[5] box-border overflow-hidden rounded-md border text-left text-[12px] font-normal leading-[14px] hover:brightness-95"
                                style={{
                                    ...eventPillStyle(segment.event, false),
                                    ...eventTextFontStyle,
                                    left: `calc(${columnLeft + (segment.lane * laneWidth)}% + ${leftInset}px)`,
                                    width: `calc(${laneWidth * span}% - ${leftInset + rightInset}px)`,
                                    top: `${top}px`,
                                    height: `${height}px`,
                                }}
                                title={segment.event?.feed_name ? `${segment.event.title} · ${segment.event.feed_name}` : segment.event.title}
                            >
                                <div
                                    className="absolute right-[1px] top-[4px] flex flex-col items-start justify-start"
                                    style={{ left: `${textLeftInset}px` }}
                                >
                                    <span className="block w-full overflow-hidden whitespace-nowrap text-clip text-left leading-[14px]">{segment.event.title}</span>
                                    {segment.timeLabel ? (
                                        <span className="inline-flex w-full items-center gap-1 overflow-hidden whitespace-nowrap text-clip text-left text-[10px] leading-[12px] opacity-80">
                                            {segment.timeLabelType === 'start' ? <Play className="h-[9px] w-[9px] shrink-0" /> : null}
                                            {segment.timeLabelType === 'end' ? <Square className="h-[9px] w-[9px] shrink-0" /> : null}
                                            {segment.timeLabelType === 'range' ? <Clock3 className="h-[9px] w-[9px] shrink-0" /> : null}
                                            <span className="overflow-hidden whitespace-nowrap text-clip">{segment.timeLabel}</span>
                                        </span>
                                    ) : null}
                                </div>
                            </button>
                        );
                    }))}
                </div>
            </div>
        </div>
    );
}

function WeekMobileDayGrid({ day, today, normalizedEvents, openDayEventsModal, openEditModal, eventTextFontStyle, toolbarHeight = 72 }) {
    const dayDate = useMemo(() => new Date(day.getFullYear(), day.getMonth(), day.getDate()), [day]);
    const dayKey = toIsoDate(dayDate);
    const weekNumber = getIsoWeekNumber(dayDate);
    const hourHeight = 56;
    const minuteHeight = hourHeight / 60;
    const dayBodyHeight = 24 * hourHeight;
    const targetMinuteOnLoad = (7 * 60) + 25;
    const hours = Array.from({ length: 24 }, (_, index) => index);
    const isToday = toIsoDate(today) === dayKey;
    const nowMinutes = minutesSinceMidnight(today);
    const initialScrollMarkerRef = useRef(null);
    const didAutoScrollRef = useRef(false);

    const allDayItems = useMemo(() => {
        return normalizedEvents
            .filter((eventItem) => (
                eventItem.isAllDay
                && eventItem.start.getTime() <= dayDate.getTime()
                && eventItem.end.getTime() >= dayDate.getTime()
            ))
            .sort((left, right) => left.start.getTime() - right.start.getTime());
    }, [normalizedEvents, dayDate]);

    const allDayRows = Math.max(1, allDayItems.length);
    const allDayRowHeight = 24;
    const timedItems = useMemo(() => buildWeekTimedByDay([dayDate], normalizedEvents)[0] || [], [dayDate, normalizedEvents]);

    useEffect(() => {
        if (didAutoScrollRef.current) return;
        const marker = initialScrollMarkerRef.current;
        if (!marker) return;

        const rafId = window.requestAnimationFrame(() => {
            marker.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
            didAutoScrollRef.current = true;
        });

        return () => window.cancelAnimationFrame(rafId);
    }, [dayKey]);

    return (
        <div className="border-t border-[var(--app-border)] md:hidden">
            <div
                className="sticky z-20 bg-[var(--app-surface)]"
                style={{ top: `calc(var(--app-navbar-height,72px) + ${toolbarHeight}px)` }}
            >
                <div className="grid border-b border-[var(--app-border)] bg-[var(--app-surface-soft)]" style={{ gridTemplateColumns: '62px minmax(0, 1fr)' }}>
                    <div className="border-r border-[var(--app-border)] px-2 py-2 text-center text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        S{weekNumber}
                    </div>
                <button
                    type="button"
                    onClick={() => openDayEventsModal(dayKey)}
                    className="px-2 py-2 text-center"
                >
                    <span className="inline-flex items-center justify-center gap-2 text-sm font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        <span>{WEEK_LABELS[(dayDate.getDay() + 6) % 7]}</span>
                        {isToday ? (
                            <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--brand-yellow-dark)] text-sm font-black leading-none tracking-normal text-[var(--app-muted)]">
                                {dayDate.getDate()}
                            </span>
                        ) : (
                            <span>{dayDate.getDate()}</span>
                        )}
                    </span>
                </button>
            </div>

                <div className="grid border-b border-[var(--app-border)] bg-[var(--app-surface)]" style={{ gridTemplateColumns: '62px minmax(0, 1fr)' }}>
                    <div className="border-r border-[var(--app-border)] px-2 py-1 text-[11px] font-bold text-[var(--app-muted)]">
                        Toute la journée
                    </div>
                    <div className="relative" style={{ height: `${allDayRows * allDayRowHeight}px` }}>
                        {allDayItems.map((eventItem, index) => (
                        <button
                            key={`mobile-day-all-day-${eventItem.id}-${index}`}
                            type="button"
                            onClick={(event) => openEditModal(event, eventItem.id)}
                                className="absolute z-[2] h-[20px] overflow-hidden whitespace-nowrap rounded-md border px-1.5 py-0 text-left text-[12px] font-normal leading-[18px] hover:brightness-95"
                                style={{
                                    ...eventPillStyle(eventItem, false),
                                    ...eventTextFontStyle,
                                    top: `${index * allDayRowHeight + 2}px`,
                                    left: '2px',
                                    width: 'calc(100% - 4px)',
                                }}
                                title={eventItem?.feed_name ? `${eventItem.title} · ${eventItem.feed_name}` : eventItem.title}
                            >
                                {eventItem.title}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            <div className="grid bg-[var(--app-surface)]" style={{ gridTemplateColumns: '62px minmax(0, 1fr)' }}>
                <div className="relative border-r border-[var(--app-border)] bg-[var(--app-surface-soft)]" style={{ height: `${dayBodyHeight}px` }}>
                    {hours.map((hour) => (
                        hour === 0 ? null : (
                            <div
                                key={`mobile-day-hour-label-${hour}`}
                                className="absolute right-2 -translate-y-1/2 text-[11px] font-semibold text-[var(--app-muted)]"
                                style={{ top: `${hour * hourHeight}px` }}
                            >
                                {String(hour).padStart(2, '0')}:00
                            </div>
                        )
                    ))}
                    {isToday ? (
                        <div
                            className="absolute right-1 z-[4] -translate-y-1/2 rounded-full bg-red-500 px-1.5 py-[1px] text-[10px] font-bold text-white"
                            style={{ top: `${nowMinutes * minuteHeight}px` }}
                        >
                            {String(today.getHours()).padStart(2, '0')}:{String(today.getMinutes()).padStart(2, '0')}
                        </div>
                    ) : null}
                </div>

                <div className="relative" style={{ height: `${dayBodyHeight}px` }}>
                    <div
                        ref={initialScrollMarkerRef}
                        className="absolute left-0 right-0 pointer-events-none"
                        style={{
                            top: `${targetMinuteOnLoad * minuteHeight}px`,
                            scrollMarginTop: 'calc(var(--app-navbar-height,72px) + 160px)',
                        }}
                    />

                    {hours.map((hour) => (
                        <div
                            key={`mobile-day-hour-line-${hour}`}
                            className="absolute left-0 right-0 border-t border-[var(--app-border)]"
                            style={{ top: `${hour * hourHeight}px` }}
                        />
                    ))}

                    {isToday ? (
                        <div
                            className="absolute left-0 right-0 z-[3] border-t border-red-500/70"
                            style={{ top: `${nowMinutes * minuteHeight}px` }}
                        />
                    ) : null}

                    {timedItems.map((segment, itemIndex) => {
                        const laneWidth = 100 / segment.lanesInDay;
                        const top = segment.startMinutes * minuteHeight;
                        const height = Math.max(18, (segment.endMinutes - segment.startMinutes) * minuteHeight);
                        const span = Math.max(1, segment.columnSpan || 1);
                        const leftInset = segment.lanesInDay > 1 ? 1 : 2;
                        const rightInset = segment.lanesInDay > 1 ? 1 : 2;
                        const textLeftInset = segment.lanesInDay > 1 ? 1 : 6;
                        const rightPct = Math.max(0, 100 - ((segment.lane + span) * laneWidth));
                        const touchesRightEdge = (segment.lane + span) >= segment.lanesInDay;
                        const effectiveRightInset = touchesRightEdge ? (rightInset + 1) : rightInset;

                        return (
                            <button
                                key={`mobile-day-timed-${segment.event.id}-${itemIndex}`}
                                type="button"
                                onClick={(event) => openEditModal(event, segment.event.id)}
                                className="absolute z-[5] box-border overflow-hidden rounded-md border text-left text-[12px] font-normal leading-[14px] hover:brightness-95"
                                style={{
                                    ...eventPillStyle(segment.event, false),
                                    ...eventTextFontStyle,
                                    left: `calc(${segment.lane * laneWidth}% + ${leftInset}px)`,
                                    right: `calc(${rightPct}% + ${effectiveRightInset}px)`,
                                    top: `${top}px`,
                                    height: `${height}px`,
                                }}
                                title={segment.event?.feed_name ? `${segment.event.title} · ${segment.event.feed_name}` : segment.event.title}
                            >
                                <div
                                    className="absolute right-[1px] top-[4px] flex flex-col items-start justify-start"
                                    style={{ left: `${textLeftInset}px` }}
                                >
                                    <span className="block w-full overflow-hidden whitespace-nowrap text-clip text-left leading-[14px]">{segment.event.title}</span>
                                    {segment.timeLabel ? (
                                        <span className="inline-flex w-full items-center gap-1 overflow-hidden whitespace-nowrap text-clip text-left text-[10px] leading-[12px] opacity-80">
                                            {segment.timeLabelType === 'start' ? <Play className="h-[9px] w-[9px] shrink-0" /> : null}
                                            {segment.timeLabelType === 'end' ? <Square className="h-[9px] w-[9px] shrink-0" /> : null}
                                            {segment.timeLabelType === 'range' ? <Clock3 className="h-[9px] w-[9px] shrink-0" /> : null}
                                            <span className="overflow-hidden whitespace-nowrap text-clip">{segment.timeLabel}</span>
                                        </span>
                                    ) : null}
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export default function CalendarIndex({
    calendar = {},
    events = [],
    categories = [],
    feeds = [],
    depots = [],
    permissions = {},
    users = [],
    leaveTypes = [],
    defaultTargetUserId = null,
    canRequestForOthers = false,
}) {
    const { auth } = usePage().props;
    const [today, setToday] = useState(() => new Date());
    const [view, setView] = useState(calendar?.view === 'week' ? 'week' : 'month');
    const [anchorDate, setAnchorDate] = useState(() => toDate(calendar?.date));
    const [modalOpen, setModalOpen] = useState(false);
    const [openLeavesModal, setOpenLeavesModal] = useState(false);
    const [editingEventId, setEditingEventId] = useState(null);
    const [categoryModalOpen, setCategoryModalOpen] = useState(false);
    const [editingCategoryId, setEditingCategoryId] = useState(null);
    const [feedModalOpen, setFeedModalOpen] = useState(false);
    const [editingFeedId, setEditingFeedId] = useState(null);
    const didInitialMonthScrollRef = useRef(false);
    const weekToolbarRef = useRef(null);
    const [weekToolbarHeight, setWeekToolbarHeight] = useState(72);
    const [isMobileViewport, setIsMobileViewport] = useState(() => (
        typeof window !== 'undefined' ? window.innerWidth < 768 : false
    ));
    const [dayOverflowModal, setDayOverflowModal] = useState({
        open: false,
        dayKey: null,
        events: [],
    });
    const [eventDetailPopover, setEventDetailPopover] = useState({
        open: false,
        eventId: null,
        anchorRect: null,
    });
    const [eventDetailPopoverHeight, setEventDetailPopoverHeight] = useState(260);
    const eventDetailPopoverRef = useRef(null);
    const canManageEvents = Boolean(permissions?.can_manage_events);
    const canManageCategories = Boolean(permissions?.can_manage_categories);
    const canManageFeeds = Boolean(permissions?.can_manage_feeds);
    const canExportLeavesCsv = Boolean(permissions?.can_export_leaves_csv);
    const eventTextFontStyle = {
        fontFamily: 'Inter, Manrope, "Segoe UI", "SF Pro Text", -apple-system, BlinkMacSystemFont, system-ui, sans-serif',
        fontKerning: 'normal',
        textRendering: 'optimizeLegibility',
    };
    const getCurrentDefaultBounds = () => buildDefaultEventBounds(new Date());
    const initialDefaultBounds = getCurrentDefaultBounds();

    const eventForm = useForm({
        title: '',
        description: '',
        start_at: initialDefaultBounds.startAt,
        end_at: initialDefaultBounds.endAt,
        all_day: false,
        category_id: '',
        depot_id: '',
    });
    const categoryForm = useForm({
        name: '',
        color: '#0F6930',
        is_active: true,
    });
    const feedForm = useForm({
        name: '',
        url: '',
        color: '#3A86FF',
        is_active: true,
    });

    const monthLabel = useMemo(
        () => anchorDate.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }),
        [anchorDate],
    );

    const weekLabel = useMemo(() => {
        const weekDays = buildWeekDays(anchorDate);
        const start = weekDays[0];
        const end = weekDays[6];
        const startLabel = start.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });
        const endLabel = end.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
        return `${startLabel} - ${endLabel}`;
    }, [anchorDate]);
    const dayLabel = useMemo(
        () => anchorDate.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        [anchorDate],
    );

    const monthCells = useMemo(() => buildMonthGrid(anchorDate), [anchorDate]);
    const monthWeeks = useMemo(
        () => Array.from({ length: 6 }, (_, index) => monthCells.slice(index * 7, index * 7 + 7)),
        [monthCells],
    );
    const weekDays = useMemo(() => buildWeekDays(anchorDate), [anchorDate]);
    const eventLookup = useMemo(
        () => Object.fromEntries(events.map((event) => [String(event.id), event])),
        [events],
    );
    const categoryLookup = useMemo(
        () => Object.fromEntries(categories.map((category) => [Number(category.id), category])),
        [categories],
    );
    const feedLookup = useMemo(
        () => Object.fromEntries(feeds.map((feed) => [Number(feed.id), feed])),
        [feeds],
    );
    const activeCategories = useMemo(
        () => categories.filter((category) => category?.is_active),
        [categories],
    );
    const normalizedEvents = useMemo(
        () => events.map((eventItem) => normalizeCalendarEvent(eventItem)).filter(Boolean),
        [events],
    );
    const eventsByDay = useMemo(() => {
        return buildEventsByDay(normalizedEvents);
    }, [normalizedEvents]);
    const monthWeekRowsMobile = useMemo(
        () => monthWeeks.map((weekDates) => buildMonthWeekRow(weekDates, normalizedEvents, eventsByDay, 3)),
        [monthWeeks, normalizedEvents, eventsByDay],
    );
    const monthWeekRowsDesktop = useMemo(
        () => monthWeeks.map((weekDates) => buildMonthWeekRow(weekDates, normalizedEvents, eventsByDay, 4)),
        [monthWeeks, normalizedEvents, eventsByDay],
    );
    const selectedEventForDetail = useMemo(() => {
        if (!eventDetailPopover.eventId) return null;
        return eventLookup[String(eventDetailPopover.eventId)] || null;
    }, [eventDetailPopover.eventId, eventLookup]);

    useEffect(() => {
        let minuteIntervalId = null;
        const alignToMinute = () => {
            setToday(new Date());
            minuteIntervalId = window.setInterval(() => {
                setToday(new Date());
            }, 60000);
        };

        const msToNextMinute = 60000 - (Date.now() % 60000);
        const alignTimeoutId = window.setTimeout(alignToMinute, msToNextMinute);

        return () => {
            window.clearTimeout(alignTimeoutId);
            if (minuteIntervalId) {
                window.clearInterval(minuteIntervalId);
            }
        };
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined') return undefined;
        const updateViewport = () => setIsMobileViewport(window.innerWidth < 768);
        updateViewport();
        window.addEventListener('resize', updateViewport);
        return () => window.removeEventListener('resize', updateViewport);
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined' || !weekToolbarRef.current) return undefined;

        const updateHeight = () => {
            const height = weekToolbarRef.current?.offsetHeight ?? 72;
            setWeekToolbarHeight(height);
        };

        updateHeight();
        const observer = typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(updateHeight)
            : null;

        if (observer && weekToolbarRef.current) observer.observe(weekToolbarRef.current);
        window.addEventListener('resize', updateHeight);

        return () => {
            window.removeEventListener('resize', updateHeight);
            if (observer) observer.disconnect();
        };
    }, [view]);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        if (view !== 'month') return;
        if (didInitialMonthScrollRef.current) return;

        const rafId = window.requestAnimationFrame(() => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            didInitialMonthScrollRef.current = true;
        });

        return () => window.cancelAnimationFrame(rafId);
    }, [view]);

    const syncUrl = (nextView, nextDate) => {
        const currentYear = anchorDate.getFullYear();
        const nextYear = nextDate.getFullYear();

        if (currentYear === nextYear) {
            if (typeof window !== 'undefined') {
                const url = new URL(window.location.href);
                url.searchParams.set('view', nextView);
                url.searchParams.set('date', toIsoDate(nextDate));
                window.history.replaceState(window.history.state, '', url.toString());
            }
            return;
        }

        router.get(
            route('calendar.index'),
            {
                view: nextView,
                date: toIsoDate(nextDate),
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['calendar', 'events'],
            },
        );
    };

    const updateView = (nextView) => {
        setView(nextView);
        syncUrl(nextView, anchorDate);
    };

    const goToCurrentPeriod = () => {
        const now = new Date();
        setAnchorDate(now);
        syncUrl(view, now);
    };

    const movePeriod = (direction) => {
        const next = new Date(anchorDate);
        if (view === 'week') {
            next.setDate(next.getDate() + (direction * (isMobileViewport ? 1 : 7)));
        } else {
            next.setMonth(next.getMonth() + direction);
        }
        setAnchorDate(next);
        syncUrl(view, next);
    };

    const exportLeavesCsv = () => {
        const exportDate = toIsoDate(anchorDate);
        window.location.assign(route('calendar.leaves.export', { date: exportDate }));
    };

    const resetEventForm = (startAt = null, endAt = null) => {
        const defaults = getCurrentDefaultBounds();
        setEditingEventId(null);
        eventForm.reset();
        eventForm.clearErrors();
        eventForm.setData({
            title: '',
            description: '',
            start_at: startAt || defaults.startAt,
            end_at: endAt || defaults.endAt,
            all_day: false,
            category_id: '',
            depot_id: '',
        });
    };

    const openCreateModal = (startAt = null, endAt = null) => {
        if (!canManageEvents) return;
        resetEventForm(startAt, endAt);
        setModalOpen(true);
    };

    const openEditModal = (eventId) => {
        if (!canManageEvents) return;
        const selected = eventLookup[String(eventId)];
        if (!selected) return;
        if (selected.is_external) return;

        setEditingEventId(Number(eventId));
        eventForm.clearErrors();
        eventForm.setData({
            title: selected.title || '',
            description: selected.description || '',
            start_at: selected.start_at_local || normalizeDateOnlyToLocalDateTime(String(selected.start_at || '').slice(0, 10)),
            end_at: selected.end_at_local || '',
            all_day: Boolean(selected.all_day),
            category_id: selected.category_id ? String(selected.category_id) : '',
            depot_id: selected.depot_id ? String(selected.depot_id) : '',
        });
        setModalOpen(true);
    };

    const openEventDetailModal = (clickEvent, eventId) => {
        const selected = eventLookup[String(eventId)];
        if (!selected) return;
        const rect = clickEvent?.currentTarget?.getBoundingClientRect
            ? clickEvent.currentTarget.getBoundingClientRect()
            : null;
        setEventDetailPopover({
            open: true,
            eventId: selected.id,
            anchorRect: rect
                ? {
                    top: rect.top,
                    left: rect.left,
                    right: rect.right,
                    bottom: rect.bottom,
                    width: rect.width,
                    height: rect.height,
                }
                : null,
        });
    };

    const closeEventDetailModal = () => {
        setEventDetailPopover({
            open: false,
            eventId: null,
            anchorRect: null,
        });
    };

    const closeEventDetailModalFromClick = (event) => {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        closeEventDetailModal();
    };

    const openEditFromDetailModal = () => {
        if (!selectedEventForDetail) return;
        closeEventDetailModal();
        openEditModal(selectedEventForDetail.id);
    };

    useEffect(() => {
        if (!eventDetailPopover.open) return undefined;

        const handleOutside = (event) => {
            const target = event.target;
            if (eventDetailPopoverRef.current?.contains(target)) return;
            closeEventDetailModal();
        };
        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                closeEventDetailModal();
            }
        };
        const handleScroll = () => {
            closeEventDetailModal();
        };

        document.addEventListener('mousedown', handleOutside);
        document.addEventListener('touchstart', handleOutside);
        document.addEventListener('keydown', handleEscape);
        window.addEventListener('scroll', handleScroll, true);

        return () => {
            document.removeEventListener('mousedown', handleOutside);
            document.removeEventListener('touchstart', handleOutside);
            document.removeEventListener('keydown', handleEscape);
            window.removeEventListener('scroll', handleScroll, true);
        };
    }, [eventDetailPopover.open]);

    useEffect(() => {
        if (!eventDetailPopover.open) return undefined;

        const measure = () => {
            const nextHeight = eventDetailPopoverRef.current?.offsetHeight || 260;
            setEventDetailPopoverHeight((previous) => (
                Math.abs(previous - nextHeight) > 1 ? nextHeight : previous
            ));
        };

        const rafId = window.requestAnimationFrame(measure);
        window.addEventListener('resize', measure);

        return () => {
            window.cancelAnimationFrame(rafId);
            window.removeEventListener('resize', measure);
        };
    }, [eventDetailPopover.open, selectedEventForDetail?.id]);

    const eventDetailPopoverLayout = useMemo(() => {
        if (typeof window === 'undefined') return { top: 120, left: 24 };
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const width = vw < 768 ? Math.min(vw - 24, 360) : 380;
        const margin = 12;
        const estimatedHeight = Math.max(220, eventDetailPopoverHeight);
        const minTop = 96;

        if (!eventDetailPopover.anchorRect) {
            return {
                top: Math.max(96, Math.round(vh * 0.25)),
                left: Math.max(margin, Math.round((vw - width) / 2)),
                width,
                placement: 'bottom',
                arrowLeft: Math.round(width / 2),
            };
        }

        const anchor = eventDetailPopover.anchorRect;
        let top = eventDetailPopover.anchorRect.bottom + 8;
        let left = eventDetailPopover.anchorRect.left;
        let placement = 'bottom';

        if (left + width > vw - margin) {
            left = vw - width - margin;
        }
        if (left < margin) {
            left = margin;
        }

        const gap = 8;
        const availableBelow = vh - anchor.bottom - margin;
        const availableAbove = anchor.top - minTop;
        const neededSpace = estimatedHeight + gap;
        const canFitBelow = availableBelow >= neededSpace;
        const canFitAbove = availableAbove >= neededSpace;

        if (canFitBelow) {
            top = Math.min(anchor.bottom + gap, vh - estimatedHeight - margin);
            top = Math.max(minTop, top);
        } else if (canFitAbove) {
            top = Math.max(minTop, anchor.top - estimatedHeight - gap);
            placement = 'top';
        } else {
            const preferBottom = availableBelow >= availableAbove;
            if (preferBottom) {
                top = Math.min(anchor.bottom + gap, vh - estimatedHeight - margin);
                top = Math.max(minTop, top);
            } else {
                top = Math.max(minTop, anchor.top - estimatedHeight - gap);
                placement = 'top';
            }
        }

        const anchorCenterX = anchor.left + (anchor.width / 2);
        const arrowLeft = Math.max(18, Math.min(width - 18, anchorCenterX - left));

        return { top, left, width, placement, arrowLeft };
    }, [eventDetailPopover.anchorRect, eventDetailPopoverHeight]);

    const closeModal = () => {
        setModalOpen(false);
        setEditingEventId(null);
        eventForm.clearErrors();
    };

    const submitEvent = (event) => {
        event.preventDefault();

        eventForm.transform((formData) => ({
            ...formData,
            title: String(formData.title || '').trim(),
            description: String(formData.description || '').trim(),
            start_at: formData.start_at || null,
            end_at: formData.end_at || null,
            all_day: Boolean(formData.all_day),
            category_id: formData.category_id ? Number(formData.category_id) : null,
            depot_id: formData.depot_id ? Number(formData.depot_id) : null,
        }));

        if (editingEventId) {
            eventForm.put(route('calendar.events.update', editingEventId), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
            return;
        }

        eventForm.post(route('calendar.events.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    };

    const deleteEvent = () => {
        if (!editingEventId) return;
        eventForm.delete(route('calendar.events.destroy', editingEventId), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    };

    const openCategoryModal = () => {
        if (!canManageCategories) return;
        setCategoryModalOpen(true);
        setEditingCategoryId(null);
        categoryForm.reset();
        categoryForm.clearErrors();
        categoryForm.setData({
            name: '',
            color: '#0F6930',
            is_active: true,
        });
    };

    const openFeedModal = () => {
        if (!canManageFeeds) return;
        setFeedModalOpen(true);
        setEditingFeedId(null);
        feedForm.reset();
        feedForm.clearErrors();
        feedForm.setData({
            name: '',
            url: '',
            color: '#3A86FF',
            is_active: true,
        });
    };

    const closeFeedModal = () => {
        setFeedModalOpen(false);
        setEditingFeedId(null);
        feedForm.clearErrors();
    };

    const openDayEventsModal = (dayKey) => {
        const dayEvents = eventsByDay.get(dayKey) || [];
        setDayOverflowModal({
            open: true,
            dayKey,
            events: dayEvents,
        });
    };

    const editFeed = (feedId) => {
        const selected = feedLookup[Number(feedId)];
        if (!selected) return;
        setEditingFeedId(Number(feedId));
        feedForm.clearErrors();
        feedForm.setData({
            name: selected.name || '',
            url: selected.url || '',
            color: selected.color || '#3A86FF',
            is_active: Boolean(selected.is_active),
        });
    };

    const submitFeed = (event) => {
        event.preventDefault();

        feedForm.transform((formData) => ({
            ...formData,
            name: String(formData.name || '').trim(),
            url: String(formData.url || '').trim(),
            color: String(formData.color || '').trim() || null,
            is_active: Boolean(formData.is_active),
        }));

        if (editingFeedId) {
            feedForm.put(route('calendar.feeds.update', editingFeedId), {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingFeedId(null);
                    feedForm.reset();
                    feedForm.setData({
                        name: '',
                        url: '',
                        color: '#3A86FF',
                        is_active: true,
                    });
                },
            });
            return;
        }

        feedForm.post(route('calendar.feeds.store'), {
            preserveScroll: true,
            onSuccess: () => {
                feedForm.reset();
                feedForm.setData({
                    name: '',
                    url: '',
                    color: '#3A86FF',
                    is_active: true,
                });
            },
        });
    };

    const deleteFeed = (feedId) => {
        feedForm.delete(route('calendar.feeds.destroy', feedId), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingFeedId === Number(feedId)) {
                    setEditingFeedId(null);
                    feedForm.reset();
                }
            },
        });
    };

    const closeCategoryModal = () => {
        setCategoryModalOpen(false);
        setEditingCategoryId(null);
        categoryForm.clearErrors();
    };

    const editCategory = (categoryId) => {
        const selected = categoryLookup[Number(categoryId)];
        if (!selected) return;
        setEditingCategoryId(Number(categoryId));
        categoryForm.clearErrors();
        categoryForm.setData({
            name: selected.name || '',
            color: selected.color || '#0F6930',
            is_active: Boolean(selected.is_active),
        });
    };

    const submitCategory = (event) => {
        event.preventDefault();

        categoryForm.transform((formData) => ({
            ...formData,
            name: String(formData.name || '').trim(),
            color: String(formData.color || '#0F6930').trim() || '#0F6930',
            is_active: Boolean(formData.is_active),
        }));

        if (editingCategoryId) {
            categoryForm.put(route('calendar.categories.update', editingCategoryId), {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingCategoryId(null);
                    categoryForm.reset();
                    categoryForm.setData({
                        name: '',
                        color: '#0F6930',
                        is_active: true,
                    });
                },
            });
            return;
        }

        categoryForm.post(route('calendar.categories.store'), {
            preserveScroll: true,
            onSuccess: () => {
                categoryForm.reset();
                categoryForm.setData({
                    name: '',
                    color: '#0F6930',
                    is_active: true,
                });
            },
        });
    };

    const deleteCategory = (categoryId) => {
        categoryForm.delete(route('calendar.categories.destroy', categoryId), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingCategoryId === Number(categoryId)) {
                    setEditingCategoryId(null);
                    categoryForm.reset();
                }
            },
        });
    };

    const formatDayKeyLabel = (dayKey) => {
        if (!dayKey || !/^\d{4}-\d{2}-\d{2}$/.test(dayKey)) {
            return '';
        }

        const [year, month, day] = dayKey.split('-').map((part) => Number(part));
        const safeDate = new Date(year, month - 1, day);
        return safeDate.toLocaleDateString('fr-FR', {
            weekday: 'long',
            day: '2-digit',
            month: 'long',
            year: 'numeric',
        });
    };

    const formatEventPeriodLabel = (eventItem, dayKey = null) => {
        if (!eventItem) return '';
        if (eventItem.all_day) return 'Toute la journée';

        const startDateTime = toDateTime(eventItem.start_at_local || eventItem.start_at);
        const endDateTime = toDateTime(eventItem.end_at_local || eventItem.end_at);

        if (startDateTime && endDateTime && !Number.isNaN(startDateTime.getTime()) && !Number.isNaN(endDateTime.getTime())) {
            const startDayKey = toIsoDate(startDateTime);
            const endDayKey = toIsoDate(endDateTime);
            const sameDayEvent = startDayKey === endDayKey;
            const isInOpenedDay = dayKey ? startDayKey === dayKey : true;

            if (sameDayEvent && isInOpenedDay) {
                return `${formatHourMinute(startDateTime)} → ${formatHourMinute(endDateTime)}`;
            }
        }

        const start = String(eventItem.start_at_label || '').trim();
        const end = String(eventItem.end_at_label || '').trim();

        if (start && end) return `${start} → ${end}`;
        return start || end || '';
    };

    const openCreateFromDayModal = () => {
        if (!canManageEvents || !dayOverflowModal.dayKey) return;
        const [year, month, day] = dayOverflowModal.dayKey.split('-').map((part) => Number(part));
        const baseDate = new Date(year, (month || 1) - 1, day || 1);
        const bounds = buildDefaultEventBounds(baseDate);
        setDayOverflowModal({ open: false, dayKey: null, events: [] });
        openCreateModal(bounds.startAt, bounds.endAt);
    };

    const header = (
        <div className="flex w-full min-h-[52px] flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div className="flex items-center gap-2">
                <h1 className="text-[22px] font-black uppercase tracking-[0.06em]">Calendrier</h1>
            </div>

            <div className="flex flex-nowrap items-center justify-center gap-1 md:gap-2 md:justify-end">
                {canExportLeavesCsv ? (
                    <button
                        type="button"
                        onClick={exportLeavesCsv}
                        className="inline-flex h-8 items-center justify-center rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 text-[10px] font-black uppercase tracking-[0.04em] text-[var(--app-text)] md:h-9 md:px-3 md:text-xs md:tracking-[0.08em]"
                    >
                        Exporter
                    </button>
                ) : null}
                <button
                    type="button"
                    onClick={() => setOpenLeavesModal(true)}
                    className="inline-flex h-8 items-center justify-center gap-1 rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 text-[10px] font-black uppercase tracking-[0.04em] text-[var(--app-text)] md:h-9 md:px-3 md:text-xs md:tracking-[0.08em]"
                >
                    <Plus className="h-3.5 w-3.5 md:h-4 md:w-4" strokeWidth={2.6} />
                    Congés
                </button>

                {canManageEvents ? (
                    <button
                        type="button"
                        onClick={() => openCreateModal()}
                        className="inline-flex h-8 items-center justify-center gap-1 rounded-xl border-2 border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2 text-[10px] font-black uppercase tracking-[0.04em] text-[var(--color-black)] md:h-9 md:gap-2 md:px-3 md:text-xs md:tracking-[0.08em]"
                    >
                        <Plus className="h-3.5 w-3.5 md:h-4 md:w-4" strokeWidth={2.6} />
                        Événement
                    </button>
                ) : null}

                {canManageCategories ? (
                    <button
                        type="button"
                        onClick={() => openCategoryModal()}
                        className="inline-flex h-8 items-center justify-center rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 text-[10px] font-black uppercase tracking-[0.04em] text-[var(--app-text)] md:h-9 md:px-3 md:text-xs md:tracking-[0.08em]"
                    >
                        Catégories
                    </button>
                ) : null}

                {canManageFeeds ? (
                    <button
                        type="button"
                        onClick={() => openFeedModal()}
                        className="inline-flex h-8 items-center justify-center gap-1 rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 text-[10px] font-black uppercase tracking-[0.04em] text-[var(--app-text)] md:h-9 md:gap-2 md:px-3 md:text-xs md:tracking-[0.08em]"
                    >
                        <Globe className="h-3.5 w-3.5 md:h-4 md:w-4" strokeWidth={2.2} />
                        Publics
                    </button>
                ) : null}
            </div>
        </div>
    );

    return (
        <AppLayout title="Calendrier" header={header}>
            <Head title="Calendrier" />

            <section className="relative overflow-visible rounded-2xl bg-[var(--app-surface)] shadow-sm [clip-path:inset(0_round_1rem)]">
                <div className="pointer-events-none absolute inset-0 z-30 rounded-2xl border-2 border-[var(--app-border)]" />
                <div
                    ref={weekToolbarRef}
                    className={`flex flex-col gap-2 border-b-2 border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:px-4 ${
                        view === 'week'
                            ? 'sticky top-[var(--app-navbar-height,72px)] z-30 rounded-t-2xl border-x-2 border-t-2'
                            : ''
                    }`}
                >
                    <p className={`${view === 'month' ? 'text-[1.75rem] leading-none' : 'text-base'} font-black uppercase tracking-[0.08em] text-[var(--app-muted)]`}>
                        {view === 'month' ? monthLabel : (isMobileViewport ? dayLabel : weekLabel)}
                    </p>

                    <div className="flex flex-nowrap items-center justify-center gap-1 sm:gap-2 sm:justify-end md:justify-end">
                        <div className="inline-flex rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] p-1">
                            <button
                                type="button"
                                onClick={() => updateView('month')}
                                className={`rounded-lg px-2 py-1 text-[10px] font-black uppercase tracking-[0.04em] md:px-3 md:py-1.5 md:text-xs md:tracking-[0.08em] ${
                                    view === 'month'
                                        ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                        : 'text-[var(--app-text)]'
                                }`}
                            >
                                Mois
                            </button>
                            <button
                                type="button"
                                onClick={() => updateView('week')}
                                className={`rounded-lg px-2 py-1 text-[10px] font-black uppercase tracking-[0.04em] md:hidden ${
                                    view === 'week'
                                        ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                        : 'text-[var(--app-text)]'
                                }`}
                            >
                                Jour
                            </button>
                            <button
                                type="button"
                                onClick={() => updateView('week')}
                                className={`hidden rounded-lg px-3 py-1.5 text-xs font-black uppercase tracking-[0.08em] md:inline-flex ${
                                    view === 'week'
                                        ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                        : 'text-[var(--app-text)]'
                                }`}
                            >
                                Semaine
                            </button>
                        </div>

                        <button
                            type="button"
                            onClick={() => movePeriod(-1)}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] md:h-9 md:w-9"
                            aria-label="Période précédente"
                        >
                            <ChevronLeft className="h-3.5 w-3.5 md:h-4 md:w-4" strokeWidth={2.4} />
                        </button>

                        <button
                            type="button"
                            onClick={goToCurrentPeriod}
                            className="inline-flex h-8 items-center justify-center rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 text-[10px] font-black uppercase tracking-[0.04em] md:h-9 md:px-3 md:text-xs md:tracking-[0.08em]"
                        >
                            Aujourd'hui
                        </button>

                        <button
                            type="button"
                            onClick={() => movePeriod(1)}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] md:h-9 md:w-9"
                            aria-label="Période suivante"
                        >
                            <ChevronRight className="h-3.5 w-3.5 md:h-4 md:w-4" strokeWidth={2.4} />
                        </button>
                    </div>
                </div>

                {view === 'month' ? (
                    <div className="sticky top-[var(--app-navbar-height,72px)] z-20 grid grid-cols-7 border-b-2 border-[var(--app-border)] bg-[var(--app-surface-soft)]">
                        {WEEK_LABELS.map((label) => (
                            <div key={label} className="px-2 py-3 text-center text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                {label}
                            </div>
                        ))}
                    </div>
                ) : null}

                {view === 'month' ? (
                    <>
                        <div className="md:hidden">
                            {monthWeekRowsMobile.map((weekRow) => (
                                <MonthDesktopWeekTable
                                    key={`mobile-week-${weekRow.weekKey}`}
                                    weekRow={weekRow}
                                    today={today}
                                    anchorDate={anchorDate}
                                    openDayEventsModal={openDayEventsModal}
                                    openEditModal={openEventDetailModal}
                                    eventTextFontStyle={eventTextFontStyle}
                                />
                            ))}
                        </div>

                        <div className="hidden md:block">
                            {monthWeekRowsDesktop.map((weekRow) => (
                                <MonthDesktopWeekTable
                                    key={`desktop-week-${weekRow.weekKey}`}
                                    weekRow={weekRow}
                                    today={today}
                                    anchorDate={anchorDate}
                                    openDayEventsModal={openDayEventsModal}
                                    openEditModal={openEventDetailModal}
                                    eventTextFontStyle={eventTextFontStyle}
                                />
                            ))}
                        </div>
                    </>
                ) : (
                    <>
                        <WeekMobileDayGrid
                            day={anchorDate}
                            today={today}
                            normalizedEvents={normalizedEvents}
                            openDayEventsModal={openDayEventsModal}
                            openEditModal={openEventDetailModal}
                            eventTextFontStyle={eventTextFontStyle}
                            toolbarHeight={weekToolbarHeight}
                        />

                        <WeekDesktopTimeGrid
                            weekDays={weekDays}
                            today={today}
                            normalizedEvents={normalizedEvents}
                            openDayEventsModal={openDayEventsModal}
                            openEditModal={openEventDetailModal}
                            eventTextFontStyle={eventTextFontStyle}
                            toolbarHeight={weekToolbarHeight}
                        />
                    </>
                )}
            </section>

            {eventDetailPopover.open ? (
                <div
                    ref={eventDetailPopoverRef}
                    className="fixed z-[60] overflow-visible rounded-2xl border border-[color:rgba(196,182,164,0.75)] bg-[color:rgba(255,255,255,0.86)] shadow-[0_24px_56px_-20px_rgba(0,0,0,0.45)] backdrop-blur-xl"
                    style={{
                        top: eventDetailPopoverLayout.top,
                        left: eventDetailPopoverLayout.left,
                        width: eventDetailPopoverLayout.width,
                    }}
                    onMouseDown={(event) => event.stopPropagation()}
                    onClick={(event) => event.stopPropagation()}
                    onTouchStart={(event) => event.stopPropagation()}
                >
                    {eventDetailPopover.anchorRect ? (
                        <span
                            aria-hidden="true"
                            className={`pointer-events-none absolute h-3 w-3 rotate-45 border-[color:rgba(196,182,164,0.75)] bg-[color:rgba(255,255,255,0.86)] ${
                                eventDetailPopoverLayout.placement === 'top'
                                    ? '-bottom-[7px] border-b border-r'
                                    : '-top-[7px] border-l border-t'
                            }`}
                            style={{ left: `${eventDetailPopoverLayout.arrowLeft - 6}px` }}
                        />
                    ) : null}

                    <div className="overflow-hidden rounded-2xl">
                    <div className="flex items-center justify-between border-b border-[var(--app-border)] px-5 py-4">
                        <h3 className="text-lg font-black tracking-[0.04em] text-[var(--app-text)]">Détail de l’événement</h3>
                        <button
                            type="button"
                            onMouseDown={(event) => event.stopPropagation()}
                            onClick={closeEventDetailModalFromClick}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                            aria-label="Fermer"
                        >
                            <X className="h-4 w-4" strokeWidth={2.2} />
                        </button>
                    </div>

                    {selectedEventForDetail ? (
                        <div className="space-y-4 px-5 py-4">
                            <div>
                                <p className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Titre</p>
                                <p className="text-base font-bold text-[var(--app-text)]">{selectedEventForDetail.title || 'Sans titre'}</p>
                            </div>

                            <div>
                                <p className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Période</p>
                                <p className="text-sm font-semibold text-[var(--app-text)]">
                                    {selectedEventForDetail.all_day
                                        ? 'Toute la journée'
                                        : `${selectedEventForDetail.start_at_label || '-'}${selectedEventForDetail.end_at_label ? ` → ${selectedEventForDetail.end_at_label}` : ''}`}
                                </p>
                            </div>

                            {selectedEventForDetail.category?.name ? (
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Catégorie</p>
                                    <span
                                        className="inline-flex items-center gap-2 rounded-lg border px-2 py-1 text-xs font-semibold"
                                        style={categoryPillStyle(selectedEventForDetail.category.color, false)}
                                    >
                                        {selectedEventForDetail.category.name}
                                    </span>
                                </div>
                            ) : null}

                            {selectedEventForDetail.depot?.name ? (
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Lieu</p>
                                    <PlaceActionsLink
                                        text={selectedEventForDetail.depot.address_full || selectedEventForDetail.depot.name}
                                        triggerLabel={selectedEventForDetail.depot.name}
                                        triggerClassName="text-sm font-semibold text-[var(--app-link)] underline decoration-dotted underline-offset-2"
                                        buttonClassName="!gap-1.5"
                                    />
                                </div>
                            ) : null}

                            {selectedEventForDetail.description ? (
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Description</p>
                                    <p className="whitespace-pre-wrap text-sm text-[var(--app-text)]">{selectedEventForDetail.description}</p>
                                </div>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="flex items-center justify-between border-t border-[var(--app-border)] px-5 py-4">
                        <button
                            type="button"
                            onMouseDown={(event) => event.stopPropagation()}
                            onClick={closeEventDetailModalFromClick}
                            className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                        >
                            Fermer
                        </button>
                        {selectedEventForDetail && canManageEvents && !selectedEventForDetail.is_external ? (
                            <button
                                type="button"
                                onClick={openEditFromDetailModal}
                                className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)]"
                            >
                                Modifier
                            </button>
                        ) : null}
                    </div>
                    </div>
                </div>
            ) : null}

            <Modal show={openLeavesModal} onClose={() => setOpenLeavesModal(false)} maxWidth="2xl">
                <div className="overflow-hidden rounded-2xl bg-[var(--app-surface)]">
                    <div className="flex items-center justify-between border-b border-[var(--app-border)] px-5 py-4">
                        <h3 className="text-lg font-black tracking-[0.04em] text-[var(--app-text)]">
                            Demande de congé
                        </h3>
                        <button
                            type="button"
                            onClick={() => setOpenLeavesModal(false)}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                            aria-label="Fermer"
                        >
                            <X className="h-4 w-4" strokeWidth={2.2} />
                        </button>
                    </div>

                    <div className="px-5 py-4">
                        <LeaveRequestForm
                            users={users}
                            leaveTypes={leaveTypes}
                            defaultTargetUserId={defaultTargetUserId}
                            canRequestForOthers={canRequestForOthers}
                            allowMultiplePeriods
                            onSuccess={() => setOpenLeavesModal(false)}
                        />
                    </div>
                </div>
            </Modal>

            <Modal show={modalOpen} onClose={closeModal} maxWidth="xl">
                <form onSubmit={submitEvent} className="bg-[var(--app-surface)]">
                    <div className="border-b border-[var(--app-border)] px-5 py-4">
                        <h3 className="text-lg font-black tracking-[0.04em] text-[var(--app-text)]">
                            {editingEventId ? 'Modifier un événement' : 'Créer un événement'}
                        </h3>
                    </div>

                    <div className="space-y-4 px-5 py-4">
                        <div>
                            <InputLabel value="Titre" />
                            <TextInput
                                value={eventForm.data.title}
                                onChange={(event) => eventForm.setData('title', event.target.value)}
                                className="mt-1 block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                            />
                            <InputError message={eventForm.errors.title} className="mt-1" />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Début" />
                                <TextInput
                                    type="datetime-local"
                                    value={eventForm.data.start_at}
                                    onChange={(event) => eventForm.setData('start_at', event.target.value)}
                                    className="mt-1 block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                                />
                                <InputError message={eventForm.errors.start_at} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel value="Fin" />
                                <TextInput
                                    type="datetime-local"
                                    value={eventForm.data.end_at}
                                    onChange={(event) => eventForm.setData('end_at', event.target.value)}
                                    className="mt-1 block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                                />
                                <InputError message={eventForm.errors.end_at} className="mt-1" />
                            </div>
                        </div>

                        <label className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--app-text)]">
                            <input
                                type="checkbox"
                                checked={Boolean(eventForm.data.all_day)}
                                onChange={(event) => eventForm.setData('all_day', event.target.checked)}
                                className="h-4 w-4 rounded border-[var(--app-border)]"
                            />
                            Journée entière
                        </label>

                        <div>
                            <InputLabel value="Catégorie" />
                            <div className="mt-1 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => eventForm.setData('category_id', '')}
                                    className={`inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-black uppercase tracking-[0.08em] ${
                                        !eventForm.data.category_id
                                            ? 'border-[var(--app-border)] bg-[var(--brand-yellow-soft)] text-[var(--color-black)]'
                                            : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]'
                                    }`}
                                >
                                    Sans catégorie
                                </button>
                                {activeCategories.map((category) => {
                                    const selected = String(eventForm.data.category_id || '') === String(category.id);
                                    return (
                                        <button
                                            key={category.id}
                                            type="button"
                                            onClick={() => eventForm.setData('category_id', String(category.id))}
                                            className="inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-black uppercase tracking-[0.08em]"
                                            style={categoryPillStyle(category.color, selected)}
                                        >
                                            <span
                                                className="inline-block h-2.5 w-2.5 rounded-full"
                                                style={{ backgroundColor: category.color }}
                                            />
                                            {category.name}
                                        </button>
                                    );
                                })}
                            </div>
                            <InputError message={eventForm.errors.category_id} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel value="Description" />
                            <textarea
                                value={eventForm.data.description}
                                onChange={(event) => eventForm.setData('description', event.target.value)}
                                rows={4}
                                className="mt-1 block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface-soft)] text-sm text-[var(--app-text)] shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <InputError message={eventForm.errors.description} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel value="Lieu" />
                            <select
                                value={eventForm.data.depot_id}
                                onChange={(event) => eventForm.setData('depot_id', event.target.value)}
                                className="mt-1 block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface-soft)] text-sm text-[var(--app-text)] shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">Aucun</option>
                                {depots.map((depot) => (
                                    <option key={depot.id} value={depot.id}>
                                        {depot.name}{depot.is_active ? '' : ' (inactif)'}
                                    </option>
                                ))}
                            </select>
                            <InputError message={eventForm.errors.depot_id} className="mt-1" />
                        </div>
                    </div>

                    <div className="flex items-center justify-between border-t border-[var(--app-border)] px-5 py-4">
                        <div>
                            {editingEventId ? (
                                <button
                                    type="button"
                                    onClick={deleteEvent}
                                    className="inline-flex h-10 items-center gap-1 rounded-xl border border-red-300 bg-red-50 px-3 text-xs font-black uppercase tracking-[0.08em] text-red-700"
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Supprimer
                                </button>
                            ) : null}
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={closeModal}
                                className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                            >
                                Annuler
                            </button>
                            <button
                                type="submit"
                                disabled={eventForm.processing}
                                className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)] disabled:opacity-60"
                            >
                                {editingEventId ? 'Mettre à jour' : 'Créer'}
                            </button>
                        </div>
                    </div>
                </form>
            </Modal>

            <Modal
                show={dayOverflowModal.open}
                onClose={() => setDayOverflowModal({ open: false, dayKey: null, events: [] })}
                maxWidth="md"
            >
                <div className="bg-[var(--app-surface)]">
                    <div className="flex items-center justify-between border-b border-[var(--app-border)] px-5 py-4">
                        <h3 className="text-lg font-black tracking-[0.04em] text-[var(--app-text)]">
                            {formatDayKeyLabel(dayOverflowModal.dayKey)}
                        </h3>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={openCreateFromDayModal}
                                disabled={!canManageEvents}
                                className="inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-1.5 text-[10px] font-black uppercase tracking-[0.04em] text-[var(--color-black)] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Plus className="h-3.5 w-3.5" strokeWidth={2.6} />
                                Événement
                            </button>
                            <button
                                type="button"
                                onClick={() => setDayOverflowModal({ open: false, dayKey: null, events: [] })}
                                className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                aria-label="Fermer"
                            >
                                <X className="h-4 w-4" strokeWidth={2.2} />
                            </button>
                        </div>
                    </div>

                    <div className="space-y-2 px-5 py-4">
                        {dayOverflowModal.events.map((eventItem) => (
                            <button
                                key={`overflow-${eventItem.id}`}
                                type="button"
                                onClick={(event) => openEventDetailModal(event, eventItem.id)}
                                className="block w-full overflow-hidden rounded-md border px-[5px] py-1 text-left text-[12px] font-normal leading-tight hover:brightness-95"
                                style={{ ...eventPillStyle(eventItem, false), ...eventTextFontStyle }}
                                title={eventItem?.feed_name ? `${eventItem.title} · ${eventItem.feed_name}` : eventItem.title}
                            >
                                <span className="flex items-center justify-between gap-2">
                                    <span className="min-w-0 truncate">{eventItem.title}</span>
                                    <span className="shrink-0 whitespace-nowrap text-[10px] opacity-80">
                                        {formatEventPeriodLabel(eventItem, dayOverflowModal.dayKey)}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>

                    <div className="flex justify-end border-t border-[var(--app-border)] px-5 py-4">
                        <button
                            type="button"
                            onClick={() => setDayOverflowModal({ open: false, dayKey: null, events: [] })}
                            className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                        >
                            Fermer
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={categoryModalOpen && canManageCategories} onClose={closeCategoryModal} maxWidth="xl">
                <div className="bg-[var(--app-surface)]">
                    <div className="border-b border-[var(--app-border)] px-5 py-4">
                        <h3 className="text-lg font-black tracking-[0.04em] text-[var(--app-text)]">Catégories d’événements</h3>
                    </div>

                    <div className="space-y-4 px-5 py-4">
                        <form onSubmit={submitCategory} className="space-y-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto_auto]">
                                <TextInput
                                    value={categoryForm.data.name}
                                    onChange={(event) => categoryForm.setData('name', event.target.value)}
                                    placeholder="Nom catégorie"
                                    className="block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface)]"
                                />
                                <TextInput
                                    type="color"
                                    value={categoryForm.data.color}
                                    onChange={(event) => categoryForm.setData('color', event.target.value)}
                                    className="h-10 w-14 rounded-xl border-[var(--app-border)] bg-[var(--app-surface)] p-1"
                                />
                                <label className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--app-text)]">
                                    <input
                                        type="checkbox"
                                        checked={Boolean(categoryForm.data.is_active)}
                                        onChange={(event) => categoryForm.setData('is_active', event.target.checked)}
                                        className="h-4 w-4 rounded border-[var(--app-border)]"
                                    />
                                    Active
                                </label>
                                <button
                                    type="submit"
                                    disabled={categoryForm.processing}
                                    className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)] disabled:opacity-60"
                                >
                                    {editingCategoryId ? 'Mettre à jour' : 'Ajouter'}
                                </button>
                            </div>
                            <InputError message={categoryForm.errors.name || categoryForm.errors.color} />
                        </form>

                        <div className="space-y-2">
                            {categories.map((category) => (
                                <div
                                    key={category.id}
                                    className="flex items-center justify-between gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2"
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="inline-block h-3 w-3 rounded-full" style={{ backgroundColor: category.color }} />
                                        <span className="truncate text-sm font-semibold text-[var(--app-text)]">{category.name}</span>
                                        {!category.is_active ? (
                                            <span className="rounded-full border border-[var(--app-border)] px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                                Inactive
                                            </span>
                                        ) : null}
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => editCategory(category.id)}
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)]"
                                            title="Modifier"
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => deleteCategory(category.id)}
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-red-300 bg-red-50 text-red-700"
                                            title="Supprimer"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex justify-end border-t border-[var(--app-border)] px-5 py-4">
                        <button
                            type="button"
                            onClick={closeCategoryModal}
                            className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                        >
                            Fermer
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={feedModalOpen && canManageFeeds} onClose={closeFeedModal} maxWidth="2xl">
                <div className="bg-[var(--app-surface)]">
                    <div className="border-b border-[var(--app-border)] px-5 py-4">
                        <h3 className="text-lg font-black tracking-[0.04em] text-[var(--app-text)]">Calendriers publics (URL)</h3>
                    </div>

                    <div className="space-y-4 px-5 py-4">
                        <form onSubmit={submitFeed} className="space-y-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            <div className="grid gap-3 sm:grid-cols-[1fr_1.2fr_auto_auto_auto]">
                                <TextInput
                                    value={feedForm.data.name}
                                    onChange={(event) => feedForm.setData('name', event.target.value)}
                                    placeholder="Nom"
                                    className="block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface)]"
                                />
                                <TextInput
                                    value={feedForm.data.url}
                                    onChange={(event) => feedForm.setData('url', event.target.value)}
                                    placeholder="https://.../calendar.ics"
                                    className="block w-full rounded-xl border-[var(--app-border)] bg-[var(--app-surface)]"
                                />
                                <TextInput
                                    type="color"
                                    value={feedForm.data.color}
                                    onChange={(event) => feedForm.setData('color', event.target.value)}
                                    className="h-10 w-14 rounded-xl border-[var(--app-border)] bg-[var(--app-surface)] p-1"
                                />
                                <label className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--app-text)]">
                                    <input
                                        type="checkbox"
                                        checked={Boolean(feedForm.data.is_active)}
                                        onChange={(event) => feedForm.setData('is_active', event.target.checked)}
                                        className="h-4 w-4 rounded border-[var(--app-border)]"
                                    />
                                    Actif
                                </label>
                                <button
                                    type="submit"
                                    disabled={feedForm.processing}
                                    className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)] disabled:opacity-60"
                                >
                                    {editingFeedId ? 'Mettre à jour' : 'Ajouter'}
                                </button>
                            </div>
                            <InputError message={feedForm.errors.name || feedForm.errors.url || feedForm.errors.color} />
                        </form>

                        <div className="space-y-2">
                            {feeds.length === 0 ? (
                                <p className="text-sm font-medium text-[var(--app-muted)]">Aucun calendrier public importé.</p>
                            ) : null}

                            {feeds.map((feed) => (
                                <div
                                    key={feed.id}
                                    className="flex items-center justify-between gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <div className="flex items-center gap-2">
                                            <span className="inline-block h-3 w-3 rounded-full" style={{ backgroundColor: feed.color || '#3A86FF' }} />
                                            <span className="truncate text-sm font-semibold text-[var(--app-text)]">{feed.name}</span>
                                            {!feed.is_active ? (
                                                <span className="rounded-full border border-[var(--app-border)] px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                                    Inactif
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="truncate text-xs text-[var(--app-muted)]">{feed.url}</p>
                                        <p className="text-[11px] text-[var(--app-muted)]">
                                            Dernière synchro : {feed.last_synced_at_label || 'jamais'}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => editFeed(feed.id)}
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)]"
                                            title="Modifier"
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => deleteFeed(feed.id)}
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-red-300 bg-red-50 text-red-700"
                                            title="Supprimer"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex justify-end border-t border-[var(--app-border)] px-5 py-4">
                        <button
                            type="button"
                            onClick={closeFeedModal}
                            className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                        >
                            Fermer
                        </button>
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}
