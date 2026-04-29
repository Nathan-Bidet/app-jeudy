import FormattedText from '@/Components/FormattedText';
import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { adaptiveTaskStyle } from '@/Support/taskColorStyle';
import { stripTextMarkers } from '@/Support/textFormatting';
import { CalendarDays, Check, CheckCircle2, Mail, PhoneCall, Smartphone, Truck, UserRound } from 'lucide-react';

function SemiTruckIcon({ className = 'h-3.5 w-3.5' }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" className={className} aria-hidden="true">
            <path d="M2 13h11v-3.5c0-.8.7-1.5 1.5-1.5H18l3 3v5h-1.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M13 13h8" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            <circle cx="6" cy="17" r="1.8" stroke="currentColor" strokeWidth="1.8" />
            <circle cx="12" cy="17" r="1.8" stroke="currentColor" strokeWidth="1.8" />
            <circle cx="18" cy="17" r="1.8" stroke="currentColor" strokeWidth="1.8" />
        </svg>
    );
}

function TrailerIcon({ className = 'h-3.5 w-3.5' }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" className={className} aria-hidden="true">
            <rect x="3" y="7" width="15" height="7" rx="1.5" stroke="currentColor" strokeWidth="1.8" />
            <path d="M18 10h3" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            <circle cx="8" cy="17" r="1.8" stroke="currentColor" strokeWidth="1.8" />
            <circle cx="14" cy="17" r="1.8" stroke="currentColor" strokeWidth="1.8" />
        </svg>
    );
}

function FilledTruckIcon({ className = 'h-4 w-4', style = undefined }) {
    return (
        <svg viewBox="0 0 24 24" className={className} style={style} aria-hidden="true">
            <path
                fill="currentColor"
                d="M3 7a1 1 0 0 1 1-1h8.5a1 1 0 0 1 1 1V9h3.2a1 1 0 0 1 .8.4l2 2.6a1 1 0 0 1 .2.6V16a1 1 0 0 1-1 1h-1.1a2.4 2.4 0 0 1-4.7 0H9.1a2.4 2.4 0 0 1-4.7 0H4a1 1 0 0 1-1-1V7Zm12.5 4.2v2.3H18v-.8l-1.3-1.5h-1.2ZM6.75 17.9a1.2 1.2 0 1 0 0-2.4 1.2 1.2 0 0 0 0 2.4Zm8.5 0a1.2 1.2 0 1 0 0-2.4 1.2 1.2 0 0 0 0 2.4Z"
            />
        </svg>
    );
}

function styleFromTaskItem(item) {
    return adaptiveTaskStyle(item?.style || {});
}

function phoneHref(number) {
    const raw = (number || '').toString().trim();
    if (!raw) return null;
    return `tel:${raw.replace(/[^\d+]/g, '')}`;
}

function smsHref(number) {
    const raw = (number || '').toString().trim();
    if (!raw) return null;
    return `sms:${raw.replace(/[^\d+]/g, '')}`;
}

function mailHref(address) {
    const email = String(address || '').trim();
    if (!email) return null;
    return `mailto:${encodeURIComponent(email)}`;
}

function transportSegments(item) {
    const transport = item?.transport;
    const safe = (value) => String(value || '').trim();

    if (transport?.mode === 'ensemble_pl') {
        return [
            { kind: 'ensemble', label: safe(transport.ensemble_label) },
            { kind: 'camion', label: safe(transport.camion_label) },
            { kind: 'remorque', label: safe(transport.remorque_label) },
        ].filter((segment) => segment.label !== '');
    }

    const camion = safe(transport?.camion_label || item?.vehicle_label);
    const remorque = safe(transport?.remorque_label || item?.remorque_label);

    return [
        ...(camion ? [{ kind: 'camion', label: camion }] : []),
        ...(remorque ? [{ kind: 'remorque', label: remorque }] : []),
    ];
}

function transportLinesForSms(item) {
    const segments = transportSegments(item);

    return segments
        .filter((segment) => segment.kind === 'camion' || segment.kind === 'remorque')
        .map((segment) => (segment.kind === 'camion'
            ? `Camion: ${segment.label}`
            : `Remorque: ${segment.label}`));
}

function singleLineSmsText(value) {
    return stripTextMarkers(value)
        .replace(/\r\n|\r|\n/g, ' | ')
        .replace(/\s{2,}/g, ' ')
        .trim();
}

function buildMailLink(address, subject, lines) {
    const base = mailHref(address);
    if (!base) return null;

    return `${base}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(lines.join('\n'))}`;
}

function taskSmsHref(number, entry, item, includeComment, includeTransport) {
    const base = smsHref(number);
    if (!base) return null;

    const lines = [
        `${entry?.date_label || ''}`.trim(),
        stripTextMarkers(item?.task).trim(),
    ].filter(Boolean);

    const loading = singleLineSmsText(item?.loading_place);
    if (loading) {
        lines.push(`Chargement: ${loading}`);
    }

    const delivery = singleLineSmsText(item?.delivery_place);
    if (delivery) {
        lines.push(`Livraison: ${delivery}`);
    }

    if (includeTransport) {
        lines.push(...transportLinesForSms(item));
    }

    const comment = singleLineSmsText(item?.comment);
    if (includeComment && comment) {
        lines.push(`Commentaire: ${comment}`);
    }

    return `${base}?body=${encodeURIComponent(lines.join('\n'))}`;
}

function taskMailHref(address, entry, item, includeComment, includeTransport) {
    const task = stripTextMarkers(item?.task).trim();
    if (!task) return null;

    const lines = [
        `${entry?.date_label || ''}`.trim(),
        '',
        task,
    ].filter(Boolean);

    const loading = singleLineSmsText(item?.loading_place);
    if (loading) {
        lines.push(`Chargement: ${loading}`);
    }

    const delivery = singleLineSmsText(item?.delivery_place);
    if (delivery) {
        lines.push(`Livraison: ${delivery}`);
    }

    if (includeTransport) {
        lines.push(...transportLinesForSms(item));
    }

    const comment = singleLineSmsText(item?.comment);
    if (includeComment && comment) {
        lines.push(`Commentaire: ${comment}`);
    }

    const subject = `LDT - ${entry?.date_label || ''} - ${task.slice(0, 80)}`.trim();

    return buildMailLink(address, subject, lines);
}

function entrySmsHref(number, entry, taskItems, commentSelection, transportSelection) {
    const base = smsHref(number);
    if (!base) return null;

    const lines = [
        `${entry?.date_label || ''}`.trim(),
        '',
    ];

    taskItems.forEach((item, index) => {
        const task = stripTextMarkers(item?.task).trim();
        if (!task) return;

        lines.push(`${index + 1}. ${task}`);

        const loading = singleLineSmsText(item?.loading_place);
        if (loading) {
            lines.push(`   Chargement: ${loading}`);
        }

        const delivery = singleLineSmsText(item?.delivery_place);
        if (delivery) {
            lines.push(`   Livraison: ${delivery}`);
        }

        const taskKey = String(item?.id ?? index);
        const includeTransport = Boolean(transportSelection?.[taskKey]);
        if (includeTransport) {
            transportLinesForSms(item).forEach((transportLine) => {
                lines.push(`   ${transportLine}`);
            });
        }

        const includeComment = Boolean(commentSelection?.[taskKey]);
        const comment = singleLineSmsText(item?.comment);
        if (includeComment && comment) {
            lines.push(`   Commentaire: ${comment}`);
        }
    });

    return `${base}?body=${encodeURIComponent(lines.join('\n'))}`;
}

function entryMailHref(address, entry, taskItems, commentSelection, transportSelection) {
    const lines = [
        `${entry?.date_label || ''}`.trim(),
        `${entry?.assignee_label || ''}`.trim(),
        '',
    ].filter(Boolean);

    taskItems.forEach((item, index) => {
        const task = stripTextMarkers(item?.task).trim();
        if (!task) return;

        lines.push(`${index + 1}. ${task}`);

        const loading = singleLineSmsText(item?.loading_place);
        if (loading) {
            lines.push(`   Chargement: ${loading}`);
        }

        const delivery = singleLineSmsText(item?.delivery_place);
        if (delivery) {
            lines.push(`   Livraison: ${delivery}`);
        }

        const taskKey = String(item?.id ?? index);
        const includeTransport = Boolean(transportSelection?.[taskKey]);
        if (includeTransport) {
            transportLinesForSms(item).forEach((transportLine) => {
                lines.push(`   ${transportLine}`);
            });
        }

        const includeComment = Boolean(commentSelection?.[taskKey]);
        const comment = singleLineSmsText(item?.comment);
        if (includeComment && comment) {
            lines.push(`   Commentaire: ${comment}`);
        }
    });

    const subject = `LDT - ${entry?.date_label || ''} - ${entry?.assignee_label || ''}`.trim();

    return buildMailLink(address, subject, lines);
}

function pickPrimaryPhone(phones = []) {
    if (!Array.isArray(phones) || phones.length === 0) return null;
    return phones.find((item) => item?.number) || null;
}

function pickSmsPhone(phones = []) {
    if (!Array.isArray(phones) || phones.length === 0) return null;
    const mobile = phones.find((item) => {
        const normalized = String(item?.number || '').replace(/\s+/g, '');
        return /^(\+33|0)[67]/.test(normalized);
    });

    return mobile || pickPrimaryPhone(phones);
}

function AssigneeAvatar({ entry }) {
    const assigneeType = String(entry?.assignee_type || '');
    const hasPhoto = Boolean(entry?.assignee_photo_url);

    if (assigneeType === 'user' && hasPhoto) {
        return (
            <img
                src={entry.assignee_photo_url}
                alt={entry.assignee_label}
                className="h-8 w-8 rounded-full border-2 border-[var(--app-border)] object-cover"
            />
        );
    }

    if (assigneeType === 'transporter') {
        return (
            <span className="ldt-avatar-transporter inline-flex h-8 w-8 items-center justify-center rounded-full border-2 border-[var(--app-border)] bg-[var(--app-surface)]">
                <FilledTruckIcon
                    className="block"
                    style={{ width: '1.65rem', height: '1.65rem', transform: 'translateX(1px)' }}
                />
            </span>
        );
    }

    if (assigneeType === 'depot') {
        return (
            <span className="ldt-avatar-depot inline-flex h-8 w-8 items-center justify-center rounded-full border-2 border-[var(--app-border)] bg-[var(--app-surface)]">
                <img
                    src="/silo-depot.png"
                    alt="Silo"
                    className="ldt-avatar-silo object-contain"
                    style={{ width: '1.3rem', height: '1.3rem' }}
                    onError={(event) => {
                        event.currentTarget.style.display = 'none';
                    }}
                />
            </span>
        );
    }

    return (
        <span className="inline-flex h-8 w-8 items-center justify-center rounded-full border-2 border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--brand-yellow-dark)]">
            <UserRound className="h-4 w-4" strokeWidth={2.2} />
        </span>
    );
}

export default function EntryCard({
    entry,
    placeResolver = {},
    commentSelection,
    transportSelection,
    onToggleTaskComment,
    onToggleTaskTransport,
    canSmsMark,
    onToggleSmsSent,
    highlighted = false,
    setEntryRef,
    highlightedTaskId = null,
    setTaskRef,
}) {
    const callPhone = pickPrimaryPhone(entry?.phones || []);
    const smsPhone = pickSmsPhone(entry?.phones || []);
    const mailAddress = entry?.assignee_type === 'transporter'
        ? String(entry?.assignee_email || '').trim()
        : '';
    const hasMail = mailAddress !== '';
    const taskItems = Array.isArray(entry.task_items) && entry.task_items.length
        ? entry.task_items
        : (entry.tasks_lines || []).map((line, index) => ({
              id: index + 1,
              task: line,
              comment: (entry.comments_lines || [])[index] || '',
              vehicle_label: entry.vehicles_text || '',
              style: entry.color_style || null,
          }));

    return (
        <article
            ref={(node) => setEntryRef?.(entry?.id, node)}
            className={`w-full overflow-hidden rounded-2xl border-2 border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm transition-colors ${highlighted ? 'ring-2 ring-[var(--brand-yellow-dark)]' : ''}`}
        >
            <div className="border-b-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-2 text-[var(--app-text)] sm:px-4 sm:py-2.5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="inline-flex items-center gap-1 rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[11px] font-black uppercase tracking-[0.12em]">
                                <CalendarDays className="h-3.5 w-3.5" strokeWidth={2.2} />
                                {entry.date_label}
                            </span>
                            <span className="inline-flex items-center rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-bold text-[var(--app-muted)]">
                                {entry.tasks_count} tâche(s)
                            </span>
                        </div>

                        <p className="mt-1 inline-flex items-center gap-2 text-[18px] font-black leading-tight text-[var(--app-text)] sm:text-[20px]">
                            <AssigneeAvatar entry={entry} />
                            {entry.assignee_label}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {callPhone?.number ? (
                            <a
                                href={phoneHref(callPhone.number)}
                                className="inline-flex h-8 items-center gap-1 rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-[11px] font-black uppercase tracking-[0.08em] text-[rgb(141,88,26)]"
                            >
                                <PhoneCall className="h-3.5 w-3.5" strokeWidth={2.2} />
                                Appeler
                            </a>
                        ) : null}

                        {smsPhone?.number ? (
                            <a
                                href={entrySmsHref(
                                    smsPhone.number,
                                    entry,
                                    taskItems,
                                    commentSelection,
                                    transportSelection,
                                )}
                                className="inline-flex h-8 items-center gap-1 rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-[11px] font-black uppercase tracking-[0.08em] text-[rgb(141,88,26)]"
                            >
                                <Smartphone className="h-3.5 w-3.5" strokeWidth={2.2} />
                                SMS
                            </a>
                        ) : null}
                        {hasMail ? (
                            <a
                                href={entryMailHref(
                                    mailAddress,
                                    entry,
                                    taskItems,
                                    commentSelection,
                                    transportSelection,
                                )}
                                className="inline-flex h-8 items-center gap-1 rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-[11px] font-black uppercase tracking-[0.08em] text-[rgb(141,88,26)]"
                            >
                                <Mail className="h-3.5 w-3.5" strokeWidth={2.2} />
                                Mail
                            </a>
                        ) : null}

                    </div>
                </div>
            </div>

            <div className="space-y-3 px-2.5 py-2.5 sm:px-4 sm:py-3">
                <div className="grid gap-2">
                    {taskItems.map((item, index) => {
                        const transport = transportSegments(item);
                        const itemStyle = styleFromTaskItem(item);
                        const detailTextColor = 'var(--app-text)';
                        const taskId = Number(item?.id || 0);
                        const canOpenAprevoir = Number.isInteger(taskId)
                            && taskId > 0
                            && Array.isArray(entry?.source_task_ids)
                            && entry.source_task_ids.map((id) => Number(id)).includes(taskId);

                        const isHighlightedTask = highlightedTaskId && Number(highlightedTaskId) === taskId;

                        return (
                            <div
                                key={`${entry.id}-task-${item.id || index}`}
                                ref={(node) => {
                                    if (!setTaskRef || !taskId) return;
                                    setTaskRef(taskId, node);
                                }}
                                id={taskId ? `ldt-task-${taskId}` : undefined}
                                className={`rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-2 sm:px-3 ${isHighlightedTask ? 'ring-2 ring-[var(--brand-yellow-dark)]' : ''}`}
                                style={itemStyle}
                            >
                            {canOpenAprevoir ? (
                                <div className="mb-1.5 flex justify-end sm:hidden">
                                    <a
                                        href={route('a_prevoir.index', { focus_task_id: taskId })}
                                        className="inline-flex shrink-0 items-center rounded-md border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-black uppercase tracking-[0.08em] text-[rgb(141,88,26)]"
                                    >
                                        À Prévoir
                                    </a>
                                </div>
                            ) : null}
                            <div className="flex items-start justify-between gap-2">
                                <FormattedText
                                    as="p"
                                    className="text-[15px] font-semibold leading-snug"
                                    text={item.task}
                                    multiline
                                />
                                {canOpenAprevoir ? (
                                    <a
                                        href={route('a_prevoir.index', { focus_task_id: taskId })}
                                        className="hidden shrink-0 items-center rounded-md border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-black uppercase tracking-[0.08em] text-[rgb(141,88,26)] sm:inline-flex"
                                    >
                                        À Prévoir
                                    </a>
                                ) : null}
                            </div>
                            {String(item.loading_place || '').trim() !== '' || String(item.delivery_place || '').trim() !== '' ? (
                                <div
                                    className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] leading-snug opacity-95"
                                    style={{ color: detailTextColor }}
                                >
                                    {String(item.loading_place || '').trim() !== '' ? (
                                        <span className="inline-flex items-center gap-1">
                                            <span className="font-semibold">Chargement :</span>
                                            <PlaceActionsLink
                                                text={item.loading_place}
                                                placeResolver={placeResolver}
                                                buttonClassName="text-[12px] text-[rgb(141,88,26)]"
                                            />
                                        </span>
                                    ) : null}
                                    {String(item.delivery_place || '').trim() !== '' ? (
                                        <span className="inline-flex items-center gap-1">
                                            <span className="font-semibold">Livraison :</span>
                                            <PlaceActionsLink
                                                text={item.delivery_place}
                                                placeResolver={placeResolver}
                                                buttonClassName="text-[12px] text-[rgb(141,88,26)]"
                                            />
                                        </span>
                                    ) : null}
                                </div>
                            ) : null}
                            {String(item.comment || '').trim() !== '' ? (
                                <div className="mt-1 flex items-start justify-between gap-3">
                                    <p className="text-[12px] leading-snug opacity-95" style={{ color: detailTextColor }}>
                                        <span className="font-semibold">Commentaire :</span>{' '}
                                        <FormattedText as="span" text={item.comment} multiline />
                                    </p>
                                    <label
                                        className="inline-flex shrink-0 items-center gap-1.5 text-[11px] font-semibold opacity-95"
                                        style={{ color: detailTextColor }}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={Boolean(commentSelection?.[String(item.id ?? index)])}
                                            onChange={(event) =>
                                                onToggleTaskComment?.(entry.id, item.id ?? index, event.target.checked)
                                            }
                                            className="h-4 w-4 rounded border-[var(--app-border)] bg-[var(--app-surface)] accent-[var(--brand-yellow-dark)]"
                                        />
                                        Commentaire
                                    </label>
                                </div>
                            ) : null}
                            {(transport.length > 0 || smsPhone?.number || hasMail) ? (
                                <div className="mt-1 flex items-center justify-between gap-2">
                                    {transport.length > 0 ? (
                                        <div className="flex flex-wrap items-center gap-2 text-[11px] font-medium opacity-95" style={{ color: detailTextColor }}>
                                            {transport.map((segment, segmentIndex) => (
                                                <span
                                                    key={`${entry.id}-${item.id || index}-${segment.kind}-${segmentIndex}`}
                                                    className="inline-flex items-center gap-1.5 rounded-md border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[var(--app-text)]"
                                                >
                                                    {segment.kind === 'ensemble' ? (
                                                        <SemiTruckIcon className="h-3.5 w-3.5" />
                                                    ) : null}
                                                    {segment.kind === 'camion' ? (
                                                        <Truck className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    ) : null}
                                                    {segment.kind === 'remorque' ? (
                                                        <TrailerIcon className="h-3.5 w-3.5" />
                                                    ) : null}
                                                    {segment.label}
                                                </span>
                                            ))}
                                        </div>
                                    ) : <span />}

                                    <div className="inline-flex items-center gap-2">
                                        {(smsPhone?.number || hasMail) && transport.length > 0 ? (
                                            <label
                                                className="inline-flex shrink-0 items-center gap-1.5 text-[11px] font-semibold opacity-95"
                                                style={{ color: detailTextColor }}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(transportSelection?.[String(item.id ?? index)])}
                                                    onChange={(event) =>
                                                        onToggleTaskTransport?.(entry.id, item.id ?? index, event.target.checked)
                                                    }
                                                    className="h-4 w-4 rounded border-[var(--app-border)] bg-[var(--app-surface)] accent-[var(--brand-yellow-dark)]"
                                                />
                                                Transport
                                            </label>
                                        ) : null}

                                        {smsPhone?.number ? (
                                            <a
                                                href={taskSmsHref(
                                                    smsPhone.number,
                                                    entry,
                                                    item,
                                                    Boolean(commentSelection?.[String(item.id ?? index)]),
                                                    Boolean(transportSelection?.[String(item.id ?? index)]),
                                                )}
                                                className="inline-flex h-6 items-center gap-1 rounded-md border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-[10px] font-black uppercase tracking-[0.08em] text-[rgb(141,88,26)]"
                                            >
                                                <Smartphone className="h-3 w-3" strokeWidth={2.2} />
                                                SMS
                                            </a>
                                        ) : null}
                                        {hasMail ? (
                                            <a
                                                href={taskMailHref(
                                                    mailAddress,
                                                    entry,
                                                    item,
                                                    Boolean(commentSelection?.[String(item.id ?? index)]),
                                                    Boolean(transportSelection?.[String(item.id ?? index)]),
                                                )}
                                                className="inline-flex h-6 items-center gap-1 rounded-md border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-[10px] font-black uppercase tracking-[0.08em] text-[rgb(141,88,26)]"
                                            >
                                                <Mail className="h-3 w-3" strokeWidth={2.2} />
                                                Mail
                                            </a>
                                        ) : null}
                                    </div>
                                </div>
                            ) : null}
                            </div>
                        );
                    })}
                </div>

            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-2 text-xs sm:px-4 sm:py-2.5">
                <div className="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        disabled={!canSmsMark}
                        onClick={() => canSmsMark && onToggleSmsSent?.(entry, !Boolean(entry.sms_sent))}
                        className={`inline-flex items-center gap-1.5 rounded-full border-2 px-2.5 py-1 text-[11px] font-black uppercase tracking-[0.08em] transition disabled:cursor-not-allowed disabled:opacity-50 ${
                            entry.sms_sent
                                ? 'border-sky-300 bg-sky-50 text-sky-700'
                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)]'
                        }`}
                    >
                        <span className={`inline-flex h-4 w-4 items-center justify-center rounded-full border-2 ${
                            entry.sms_sent ? 'border-sky-400 bg-sky-500 text-white' : 'border-[var(--app-border)]'
                        }`}>
                            {entry.sms_sent ? <Check className="h-3 w-3" strokeWidth={3} /> : null}
                        </span>
                        SMS Envoyé
                    </button>
                </div>

                {entry.sms_sent ? (
                    <span className="font-medium text-[var(--app-muted)]">
                        {entry.sms_sent_at_label
                            ? `Le ${entry.sms_sent_at_label}${entry.sms_sent_by_initials ? ` • ${entry.sms_sent_by_initials}` : ''}`
                            : `Envoyé${entry.sms_sent_by_initials ? ` • ${entry.sms_sent_by_initials}` : ''}`}
                    </span>
                ) : null}

                {entry.is_all_pointed ? (
                    <span className="inline-flex items-center gap-1 rounded-full border-2 border-emerald-300 bg-emerald-50 px-2 py-0.5 text-emerald-700">
                        <CheckCircle2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                        Pointé
                    </span>
                ) : null}
            </div>
        </article>
    );
}
