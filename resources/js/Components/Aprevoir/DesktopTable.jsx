import Modal from '@/Components/Modal';
import FormattedText from '@/Components/FormattedText';
import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { BoursagriIndicatorCell, DirectIndicatorCell, IndicatorsInline, PointedIndicatorCell } from '@/Components/Aprevoir/TaskStateIndicators';
import { adaptiveTaskStyle } from '@/Support/taskColorStyle';
import { BookOpen, Check, Circle, Copy, Filter, GripVertical, Pencil, Search, Trash2 } from 'lucide-react';
import { Fragment, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';

function compactBookButton(task) {
    const left = task?.updated_by?.initials || '--';
    const right = task?.created_by?.initials || '--';
    const projected = Boolean(task?.book?.projected && task?.book?.url);

    const content = (
        <span className="inline-flex items-center gap-1">
            <span className="inline-flex h-4 min-w-4 items-center justify-center rounded bg-[var(--app-surface)] px-1 text-[9px] font-black">
                {left}
            </span>
            <BookOpen className="h-3 w-3" strokeWidth={2.1} />
            <span>Livre</span>
            <span className="inline-flex h-4 min-w-4 items-center justify-center rounded bg-[var(--app-surface)] px-1 text-[9px] font-black">
                {right}
            </span>
        </span>
    );

    const className =
        'inline-flex rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-1.5 py-1 text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--app-text)]';

    if (!projected) return <span className={`${className} opacity-60`}>{content}</span>;

    return <a href={task.book.url} className={className}>{content}</a>;
}

function plainBookButton(task) {
    const projected = Boolean(task?.book?.projected && task?.book?.url);
    const className =
        'inline-flex items-center gap-1 rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--app-text)]';
    const content = (
        <span className="inline-flex items-center gap-1">
            <BookOpen className="h-3 w-3" strokeWidth={2.1} />
            <span>Livre</span>
        </span>
    );

    if (!projected) return <span className={`${className} opacity-60`}>{content}</span>;
    return <a href={task.book.url} className={className}>{content}</a>;
}

function rowStyle(task) {
    return adaptiveTaskStyle(task?.style || {});
}

function taskColorMeta(task) {
    const style = task?.style;
    if (!style?.matched) return null;

    const label = String(style.rule_name || style.rule_pattern || 'Couleur').trim() || 'Couleur';
    const bgColor = String(style.bg_color || '').trim();
    const textColor = String(style.text_color || '').trim();
    const ruleId = style.rule_id ? String(style.rule_id) : '';
    const key = `rid:${ruleId}|label:${label.toLowerCase()}|bg:${bgColor.toLowerCase()}|text:${textColor.toLowerCase()}`;

    return { key, label, bgColor: bgColor || null, textColor: textColor || null };
}

function vehicleLabel(task) {
    if (!task?.vehicle) return '—';
    return [task.vehicle.name, task.vehicle.registration].filter(Boolean).join(' • ') || '—';
}

function remorqueLabel(task) {
    if (!task?.remorque) return null;
    return [task.remorque.name, task.remorque.registration].filter(Boolean).join(' • ') || null;
}

function phoneHref(value) {
    const raw = (value || '').toString().trim();
    if (!raw) return null;
    return `tel:${raw.replace(/[^\d+]/g, '')}`;
}

function mapsHrefFromAddress(parts) {
    const text = (parts || []).filter(Boolean).join(', ');
    if (!text) return null;
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(text)}`;
}

function AssigneeContactModal({ contact, onClose }) {
    if (!contact) return null;

    const isUser = contact.type === 'user';
    const depotMapsHref = contact.type === 'depot'
        ? mapsHrefFromAddress([
            contact.address_line1,
            contact.address_line2,
            contact.postal_code,
            contact.city,
            contact.country,
        ])
        : null;

    return (
        <Modal show={Boolean(contact)} onClose={onClose} maxWidth="2xl">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                    {isUser ? 'Fiche contact chauffeur' : 'Fiche contact dépôt'}
                </h3>
            </div>

            <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4 text-sm">
                <div>
                    <div className="text-base font-bold">{contact.name || '—'}</div>
                    <div className="mt-1 text-xs text-[var(--app-muted)]">
                        {isUser ? (contact.email || '—') : ([contact.address_line1, contact.address_line2, contact.postal_code, contact.city].filter(Boolean).join(' • ') || 'Dépôt')}
                    </div>
                </div>

                <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                    <div>
                        Téléphone:{' '}
                        {contact.phone ? (
                            <a href={phoneHref(contact.phone)} className="underline decoration-dotted underline-offset-2">
                                {contact.phone}
                            </a>
                        ) : '—'}
                    </div>

                    {isUser ? (
                        <>
                            <div>
                                Mobile:{' '}
                                {contact.mobile_phone ? (
                                    <a href={phoneHref(contact.mobile_phone)} className="underline decoration-dotted underline-offset-2">
                                        {contact.mobile_phone}
                                    </a>
                                ) : '—'}
                            </div>
                            <div>
                                Email:{' '}
                                {contact.email ? (
                                    <a href={`mailto:${contact.email}`} className="underline decoration-dotted underline-offset-2">
                                        {contact.email}
                                    </a>
                                ) : '—'}
                            </div>
                            <div>
                                Interne:{' '}
                                {contact.internal_number ? (
                                    <a href={phoneHref(contact.internal_number)} className="underline decoration-dotted underline-offset-2">
                                        {contact.internal_number}
                                    </a>
                                ) : '—'}
                            </div>
                            <div>
                                Adresse dépôt:{' '}
                                {contact.depot_address ? (
                                    <a
                                        href={mapsHrefFromAddress([contact.depot_address]) || '#'}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline decoration-dotted underline-offset-2"
                                    >
                                        {contact.depot_address}
                                    </a>
                                ) : '—'}
                            </div>
                            <div className="mt-3 flex justify-end">
                                <a
                                    href={route('directory.show', contact.id)}
                                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em]"
                                >
                                    Ouvrir la fiche annuaire
                                </a>
                            </div>
                        </>
                    ) : (
                        <>
                            <div>
                                Email:{' '}
                                {contact.email ? (
                                    <a href={`mailto:${contact.email}`} className="underline decoration-dotted underline-offset-2">
                                        {contact.email}
                                    </a>
                                ) : '—'}
                            </div>
                            <div>
                                Adresse:{' '}
                                {depotMapsHref ? (
                                    <a href={depotMapsHref} target="_blank" rel="noreferrer" className="underline decoration-dotted underline-offset-2">
                                        {[contact.address_line1, contact.address_line2, contact.postal_code, contact.city, contact.country].filter(Boolean).join(' • ') || 'Adresse'}
                                    </a>
                                ) : '—'}
                            </div>
                        </>
                    )}
                </div>
            </div>

            <div className="flex justify-end border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                >
                    Fermer
                </button>
            </div>
        </Modal>
    );
}

function ActionIconButton({ onClick, title, children, danger = false, active = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={title}
            className={`inline-flex h-7 w-7 items-center justify-center rounded-md border text-[10px] ${
                danger
                    ? 'border-red-500 bg-red-500 text-white'
                    : active
                        ? 'border-emerald-600 bg-emerald-600 text-white'
                        : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]'
            }`}
        >
            {children}
        </button>
    );
}

function DropIndicatorRow() {
    return (
        <tr aria-hidden="true">
            <td colSpan={11} className="p-0">
                <div className="h-1 w-full rounded-full bg-[var(--brand-yellow-dark)]" />
            </td>
        </tr>
    );
}

function TaskDataRow({
    group,
    task,
    placeResolver,
    highlighted = false,
    saving = false,
    dropPosition = null,
    canUpdate,
    canDelete,
    canPoint,
    onEditTask,
    onDuplicateTask,
    onDeleteTask,
    onTogglePoint,
    onDragStartTask,
    onDragEndTask,
    onDragOverTask,
    onDropTask,
    onOpenAssigneeContact,
}) {
    const isPointed = task?.pointed === true || task?.pointed === 1 || task?.pointed === '1' || task?.pointed === 'true';

    const rowBoxShadow = [];
    if (highlighted) {
        rowBoxShadow.push('inset 0 0 0 2px #f97316');
    }
    if (dropPosition === 'before') {
        rowBoxShadow.push('inset 0 3px 0 0 var(--brand-yellow-dark)');
    }
    if (dropPosition === 'after') {
        rowBoxShadow.push('inset 0 -3px 0 0 var(--brand-yellow-dark)');
    }

    return (
        <tr
            data-task-id={task?.id}
            onDragOver={(event) => onDragOverTask?.(event, group, task)}
            onDrop={(event) => onDropTask?.(event, group, task)}
            onDoubleClick={() => canUpdate && onEditTask?.(task)}
            className={`border-t border-[var(--app-border)] align-top ${canUpdate ? 'cursor-pointer' : ''} ${
                highlighted ? 'aprevoir-focus-blink' : ''
            } ${saving ? 'opacity-70' : ''}`}
            style={{
                ...rowStyle(task),
                ...(rowBoxShadow.length > 0 ? { boxShadow: rowBoxShadow.join(', ') } : {}),
            }}
        >
            <td className="px-3 py-3 font-medium text-center">
                <div className="flex flex-col items-center">
                    <div>{group?.date_label || group?.date || '—'}</div>
                    <div className="mt-1">{plainBookButton(task)}</div>
                </div>
            </td>
            <td className="px-3 py-3 text-center font-semibold">{task.fin_label || '—'}</td>
            <td className="px-3 py-3 text-center">
                {group?.assignee?.type === 'none' ? (
                    <span>—</span>
                ) : (
                    <div className="flex flex-col items-center gap-0.5">
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                onOpenAssigneeContact?.(group?.assignee || null);
                            }}
                            className="block text-center font-medium underline decoration-dotted underline-offset-2 hover:opacity-80"
                        >
                            {group?.assignee?.name || '—'}
                        </button>
                        {group?.assignee?.phone ? (
                            <a
                                href={phoneHref(group.assignee.phone)}
                                className="block text-s text-emerald-700 underline underline-offset-2"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {group.assignee.phone}
                            </a>
                        ) : null}
                    </div>
                )}
            </td>
            <td className="px-3 py-3 text-center">
                <div className="flex flex-col items-center">
                    <span>{vehicleLabel(task)}</span>
                    {remorqueLabel(task) ? (
                        <span className="mt-1 text-xs opacity-80">Remorque: {remorqueLabel(task)}</span>
                    ) : null}
                </div>
            </td>
            <td className="px-3 py-3">
                <FormattedText className="font-medium" text={task.task} multiline />
                {task.loading_place || task.delivery_place ? (
                    <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs opacity-95">
                        {task.loading_place ? (
                                <span className="inline-flex items-center gap-1">
                                    <span className="font-semibold">Chargement :</span>
                                <PlaceActionsLink text={task.loading_place} placeResolver={placeResolver} buttonClassName="text-xs" />
                                </span>
                        ) : null}
                        {task.delivery_place ? (
                            <span className="inline-flex items-center gap-1">
                                <span className="font-semibold">Livraison :</span>
                                <PlaceActionsLink text={task.delivery_place} placeResolver={placeResolver} buttonClassName="text-xs" />
                            </span>
                        ) : null}
                    </div>
                ) : null}
                {task.is_boursagri && task.boursagri_contract_number ? (
                    <div className="mt-1 text-xs opacity-80">Contrat: {task.boursagri_contract_number}</div>
                ) : null}
                <IndicatorsInline indicators={task?.indicators} />
            </td>
            <td className="px-3 py-3">
                {task.comment ? (
                    <FormattedText text={task.comment} multiline />
                ) : (
                    '—'
                )}
            </td>
            <td className="px-2 py-3 text-center">
                <PointedIndicatorCell pointed={isPointed} />
            </td>
            <td className="px-2 py-3 text-center">
                <DirectIndicatorCell isDirect={task?.is_direct} />
            </td>
            <td className="px-2 py-3 text-center">
                <BoursagriIndicatorCell isBoursagri={task?.is_boursagri} />
            </td>
            <td className="px-3 py-3">
                <div className="flex flex-col items-end gap-1.5">
                    <div className="inline-flex items-center gap-1.5 whitespace-nowrap">
                        {saving ? (
                            <span
                                className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)]"
                                title="Enregistrement..."
                                aria-label="Enregistrement..."
                            >
                                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[var(--brand-yellow-dark)] border-t-transparent" />
                            </span>
                        ) : null}
                        {canPoint ? (
                            <ActionIconButton
                                onClick={() => onTogglePoint?.(task, !isPointed)}
                                title={isPointed ? 'Dépointer' : 'Pointer'}
                                active={isPointed}
                            >
                                {isPointed ? (
                                    <Check className="h-3.5 w-3.5" strokeWidth={2.4} />
                                ) : (
                                    <Circle className="h-3.5 w-3.5" strokeWidth={2.2} />
                                )}
                            </ActionIconButton>
                        ) : null}
                        {canUpdate ? (
                            <ActionIconButton onClick={() => onEditTask?.(task)} title="Éditer">
                                <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                            </ActionIconButton>
                        ) : null}
                        {canUpdate ? (
                            <ActionIconButton onClick={() => onDuplicateTask?.(task)} title="Dupliquer">
                                <Copy className="h-3.5 w-3.5" strokeWidth={2.2} />
                            </ActionIconButton>
                        ) : null}
                        {canDelete ? (
                            <ActionIconButton onClick={() => onDeleteTask?.(task)} title="Supprimer" danger>
                                <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                            </ActionIconButton>
                        ) : null}
                    </div>
                    {compactBookButton(task)}
                </div>
            </td>
            <td className="px-2 py-3 text-center">
                {canUpdate ? (
                    <span
                        draggable
                        onDragStart={(event) => onDragStartTask?.(event, group, task)}
                        onDragEnd={() => onDragEndTask?.()}
                        title="Glisser-déposer"
                        className="inline-flex h-7 w-7 cursor-grab items-center justify-center rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)] active:cursor-grabbing"
                    >
                        <GripVertical className="h-4 w-4" strokeWidth={2} />
                    </span>
                ) : null}
            </td>
        </tr>
    );
}

export default function DesktopTable({
    groups = [],
    depotPlaceMap = {},
    highlightedTaskId = null,
    focusTaskId = null,
    savingTaskIds = {},
    dropPreview = null,
    dateFrom = '',
    dateTo = '',
    canUpdate = false,
    canDelete = false,
    canPoint = false,
    onEditTask,
    onDuplicateTask,
    onDeleteTask,
    onTogglePoint,
    onDragStartTask,
    onDragEndTask,
    onDragOverTask,
    onDropTask,
    onDateFiltersChange,
    onApplyDateFilters,
    onResetDateFilters,
}) {
    const wrapperRef = useRef(null);
    const dateFilterButtonRef = useRef(null);
    const finFilterButtonRef = useRef(null);
    const driverFilterButtonRef = useRef(null);
    const vehicleFilterButtonRef = useRef(null);
    const taskFilterButtonRef = useRef(null);
    const commentFilterButtonRef = useRef(null);
    const xdbFilterButtonRef = useRef(null);
    const datePopoverRef = useRef(null);
    const finPopoverRef = useRef(null);
    const driverPopoverRef = useRef(null);
    const vehiclePopoverRef = useRef(null);
    const taskPopoverRef = useRef(null);
    const commentPopoverRef = useRef(null);
    const xdbPopoverRef = useRef(null);
    const prevRowPositionsRef = useRef(new Map());
    const [showDateFilter, setShowDateFilter] = useState(false);
    const [showFinFilter, setShowFinFilter] = useState(false);
    const [showDriverFilter, setShowDriverFilter] = useState(false);
    const [showVehicleFilter, setShowVehicleFilter] = useState(false);
    const [showTaskFilter, setShowTaskFilter] = useState(false);
    const [showCommentFilter, setShowCommentFilter] = useState(false);
    const [showXdbFilter, setShowXdbFilter] = useState(false);
    const [localDateFrom, setLocalDateFrom] = useState(dateFrom || '');
    const [localDateTo, setLocalDateTo] = useState(dateTo || '');
    const [driverFilter, setDriverFilter] = useState('');
    const [finFilter, setFinFilter] = useState('');
    const [vehicleFilter, setVehicleFilter] = useState('');
    const [driverSearchText, setDriverSearchText] = useState('');
    const [vehicleSearchText, setVehicleSearchText] = useState('');
    const [taskTextFilter, setTaskTextFilter] = useState('');
    const [taskColorFilter, setTaskColorFilter] = useState('');
    const [showTaskColorMenu, setShowTaskColorMenu] = useState(false);
    const [commentTextFilter, setCommentTextFilter] = useState('');
    const [xFilterMode, setXFilterMode] = useState('no'); // all|yes|no
    const [dFilterMode, setDFilterMode] = useState('all'); // all|yes|no
    const [bFilterMode, setBFilterMode] = useState('all'); // all|yes|no
    const [bContractTextFilter, setBContractTextFilter] = useState('');
    const [bContractSort, setBContractSort] = useState('asc');
    const [dateFilterPos, setDateFilterPos] = useState({ top: 40, left: 12 });
    const [finFilterPos, setFinFilterPos] = useState({ top: 40, left: 150 });
    const [driverFilterPos, setDriverFilterPos] = useState({ top: 40, left: 240 });
    const [vehicleFilterPos, setVehicleFilterPos] = useState({ top: 40, left: 420 });
    const [taskFilterPos, setTaskFilterPos] = useState({ top: 40, left: 580 });
    const [commentFilterPos, setCommentFilterPos] = useState({ top: 40, left: 760 });
    const [xdbFilterPos, setXdbFilterPos] = useState({ top: 40, left: 980 });
    const [assigneeContact, setAssigneeContact] = useState(null);
    const [lastAppliedFocusFilterId, setLastAppliedFocusFilterId] = useState(null);

    useEffect(() => {
        setLocalDateFrom(dateFrom || '');
        setLocalDateTo(dateTo || '');
    }, [dateFrom, dateTo]);

    useEffect(() => {
        const focusId = Number(focusTaskId || 0);

        if (!focusId) {
            setLastAppliedFocusFilterId(null);
            return;
        }

        if (focusId === Number(lastAppliedFocusFilterId || 0)) {
            return;
        }

        let isFocusedTaskPointed = false;

        groups.some((group) =>
            (group?.tasks || []).some((task) => {
                if (Number(task?.id || 0) !== focusId) return false;
                isFocusedTaskPointed = task?.pointed === true || task?.pointed === 1 || task?.pointed === '1' || task?.pointed === 'true';
                return true;
            }),
        );

        setXFilterMode(isFocusedTaskPointed ? 'all' : 'no');
        setLastAppliedFocusFilterId(focusId);
    }, [focusTaskId, groups, lastAppliedFocusFilterId]);

    const closeAllColumnFilters = () => {
        setShowDateFilter(false);
        setShowFinFilter(false);
        setShowDriverFilter(false);
        setShowVehicleFilter(false);
        setShowTaskFilter(false);
        setShowTaskColorMenu(false);
        setShowCommentFilter(false);
        setShowXdbFilter(false);
    };

    useEffect(() => {
        const anyOpen =
            showDateFilter ||
            showFinFilter ||
            showDriverFilter ||
            showVehicleFilter ||
            showTaskFilter ||
            showCommentFilter ||
            showXdbFilter;
        if (!anyOpen) return;

        const nodes = [
            dateFilterButtonRef.current,
            finFilterButtonRef.current,
            driverFilterButtonRef.current,
            vehicleFilterButtonRef.current,
            taskFilterButtonRef.current,
            commentFilterButtonRef.current,
            xdbFilterButtonRef.current,
            datePopoverRef.current,
            finPopoverRef.current,
            driverPopoverRef.current,
            vehiclePopoverRef.current,
            taskPopoverRef.current,
            commentPopoverRef.current,
            xdbPopoverRef.current,
        ].filter(Boolean);

        const onPointerDown = (event) => {
            const target = event.target;
            const inside = nodes.some((node) => node && node.contains(target));
            if (!inside) {
                closeAllColumnFilters();
            }
        };

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                closeAllColumnFilters();
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [showDateFilter, showFinFilter, showDriverFilter, showVehicleFilter, showTaskFilter, showCommentFilter, showXdbFilter]);

    const updatePopoverPosition = (buttonRef, setPos, width = 288) => {
        const wrapper = wrapperRef.current;
        const button = buttonRef.current;
        if (!wrapper || !button) return;

        const wrapperRect = wrapper.getBoundingClientRect();
        const buttonRect = button.getBoundingClientRect();
        const left = Math.min(
            Math.max(8, buttonRect.left - wrapperRect.left),
            Math.max(8, wrapperRect.width - width - 8),
        );
        const top = Math.max(8, buttonRect.bottom - wrapperRect.top + 8);
        setPos({ top, left });
    };

    const groupedRowsBeforeColor = useMemo(() => {
        const isPointedValue = (value) =>
            value === true || value === 1 || value === '1' || value === 'true';

        const groupMatchesDriver = (group) => {
            if (!driverFilter) return true;
            if (driverFilter === '__none__') {
                return group?.assignee?.type === 'none' || !group?.assignee?.id;
            }
            if (/^\d+$/.test(driverFilter)) {
                return group?.assignee?.type === 'user' && String(group?.assignee?.id || '') === driverFilter;
            }
            const [filterType, filterId] = String(driverFilter).split(':');
            return group?.assignee?.type === filterType && String(group?.assignee?.id || '') === String(filterId || '');
        };

        const taskMatchesVehicle = (task) => {
            if (!vehicleFilter) return true;
            if (vehicleFilter === '__none__') return !task?.vehicle?.id;
            return String(task?.vehicle?.id || '') === vehicleFilter;
        };

        const taskMatchesFin = (task) => {
            if (!finFilter) return true;
            if (finFilter === '__none__') return !task?.fin_date && !task?.fin_label;
            const finValue = (task?.fin_date || task?.fin_label || '').toString();
            return finValue === finFilter;
        };

        const normalize = (value) => (value || '').toString().toLocaleLowerCase('fr');
        const taskNeedle = normalize(taskTextFilter).trim();
        const commentNeedle = normalize(commentTextFilter).trim();

        const taskMatchesTexts = (task) => {
            const taskHaystack = normalize(task?.task);
            const commentHaystack = normalize(task?.comment);

            if (taskNeedle && !taskHaystack.includes(taskNeedle)) return false;
            if (commentNeedle && !commentHaystack.includes(commentNeedle)) return false;
            return true;
        };

        const contractNeedle = normalize(bContractTextFilter).trim();
        const taskMatchesXdb = (task) => {
            const isPointed = isPointedValue(task?.pointed);
            if (xFilterMode === 'yes' && !isPointed) return false;
            if (xFilterMode === 'no' && isPointed) return false;

            if (dFilterMode === 'yes' && !task?.is_direct) return false;
            if (dFilterMode === 'no' && task?.is_direct) return false;

            if (bFilterMode === 'yes' && !task?.is_boursagri) return false;
            if (bFilterMode === 'no' && task?.is_boursagri) return false;

            if (bFilterMode === 'yes' && contractNeedle) {
                const contract = normalize(task?.boursagri_contract_number);
                if (!contract.includes(contractNeedle)) return false;
            }
            return true;
        };

        return groups
            .filter(groupMatchesDriver)
            .map((group) => ({
                ...group,
                tasks: (group.tasks || []).filter(
                    (task) => taskMatchesVehicle(task) && taskMatchesFin(task) && taskMatchesTexts(task) && taskMatchesXdb(task),
                ),
            }))
            .filter((group) => (group.tasks || []).length > 0);
    }, [
        groups,
        driverFilter,
        vehicleFilter,
        taskTextFilter,
        commentTextFilter,
        xFilterMode,
        dFilterMode,
        bFilterMode,
        bContractTextFilter,
        bContractSort,
        finFilter,
    ]);

    const taskColorOptions = useMemo(() => {
        const seen = new Map();

        groupedRowsBeforeColor.forEach((group) => {
            (group.tasks || []).forEach((task) => {
                const color = taskColorMeta(task);
                if (!color) return;
                if (!seen.has(color.key)) {
                    seen.set(color.key, color);
                }
            });
        });

        return Array.from(seen.values()).sort((a, b) => a.label.localeCompare(b.label, 'fr'));
    }, [groupedRowsBeforeColor]);

    useEffect(() => {
        if (!taskColorFilter) return;
        if (!taskColorOptions.some((option) => option.key === taskColorFilter)) {
            setTaskColorFilter('');
        }
    }, [taskColorFilter, taskColorOptions]);

    const filteredGroups = useMemo(() => {
        if (!taskColorFilter) {
            return groupedRowsBeforeColor;
        }

        return groupedRowsBeforeColor
            .map((group) => ({
                ...group,
                tasks: (group.tasks || []).filter((task) => taskColorMeta(task)?.key === taskColorFilter),
            }))
            .filter((group) => (group.tasks || []).length > 0);
    }, [groupedRowsBeforeColor, taskColorFilter]);

    const displayRows = useMemo(() => {
        const rows = filteredGroups.flatMap((group) =>
            (group.tasks || []).map((task) => ({
                group,
                task,
            })),
        );

        if (bFilterMode !== 'yes') {
            return rows;
        }

        const normalize = (value) => (value || '').toString().toLocaleLowerCase('fr');

        return [...rows].sort((a, b) => {
            const av = normalize(a.task?.boursagri_contract_number);
            const bv = normalize(b.task?.boursagri_contract_number);
            const cmp = av.localeCompare(bv, 'fr', { numeric: true, sensitivity: 'base' });
            if (cmp !== 0) {
                return bContractSort === 'asc' ? cmp : -cmp;
            }

            const ad = a.task?.date || '';
            const bd = b.task?.date || '';
            if (ad !== bd) return ad.localeCompare(bd);

            return (a.task?.position ?? 0) - (b.task?.position ?? 0);
        });
    }, [filteredGroups, bFilterMode, bContractSort]);

    useLayoutEffect(() => {
        const wrapper = wrapperRef.current;
        if (!wrapper) return;

        const rows = Array.from(wrapper.querySelectorAll('tr[data-task-id]'));
        if (rows.length === 0) {
            prevRowPositionsRef.current = new Map();
            return;
        }

        const nextPositions = new Map();
        rows.forEach((row) => {
            const id = row.dataset.taskId;
            if (id) {
                nextPositions.set(id, row.getBoundingClientRect());
            }
        });

        const prevPositions = prevRowPositionsRef.current;
        rows.forEach((row) => {
            const id = row.dataset.taskId;
            if (!id) return;
            const prevBox = prevPositions.get(id);
            const nextBox = nextPositions.get(id);
            if (!prevBox || !nextBox) return;

            const deltaY = prevBox.top - nextBox.top;
            if (Math.abs(deltaY) < 1) return;

            row.style.transition = 'none';
            row.style.transform = `translateY(${deltaY}px)`;
            row.getBoundingClientRect();
            row.style.transition = 'transform 240ms ease, opacity 240ms ease';
            row.style.transform = '';
        });

        prevRowPositionsRef.current = nextPositions;
    }, [displayRows]);

    const driverOptions = useMemo(() => {
        const seen = new Map();
        displayRows.forEach(({ group }) => {
            if (!group?.assignee?.id || group?.assignee?.type === 'none') return;
            const type = String(group.assignee.type || '');
            const id = String(group.assignee.id);
            const key = `${type}:${id}`;
            if (!seen.has(key)) {
                const baseName = group.assignee.name || `${type === 'depot' ? 'Dépôt' : 'Utilisateur'} #${id}`;
                const typeLabel = type === 'depot' ? 'Dépôt' : 'Chauffeur';
                seen.set(key, {
                    id: key,
                    type,
                    name: baseName,
                    label: `${baseName} (${typeLabel})`,
                    search_label: `${baseName} ${typeLabel}`.toLocaleLowerCase('fr'),
                });
            }
        });
        return Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name, 'fr'));
    }, [displayRows]);

    const filteredDriverOptions = useMemo(() => {
        const needle = (driverSearchText || '').toLocaleLowerCase('fr').trim();
        if (!needle) return driverOptions;
        return driverOptions.filter((driver) => (driver.search_label || driver.name || '').toLocaleLowerCase('fr').includes(needle));
    }, [driverOptions, driverSearchText]);

    const finOptions = useMemo(() => {
        const seen = new Map();
        displayRows.forEach(({ task }) => {
            const raw = (task?.fin_date || task?.fin_label || '').toString().trim();
            if (!raw) return;
            if (!seen.has(raw)) {
                seen.set(raw, {
                    value: raw,
                    label: task?.fin_label || raw,
                });
            }
        });
        return Array.from(seen.values()).sort((a, b) => a.label.localeCompare(b.label, 'fr'));
    }, [displayRows]);

    const vehicleOptions = useMemo(() => {
        const seen = new Map();
        displayRows.forEach(({ task }) => {
            const vehicle = task?.vehicle;
            if (!vehicle?.id) return;
            if (!seen.has(vehicle.id)) {
                const label = [vehicle.name, vehicle.registration].filter(Boolean).join(' • ') || `Véhicule #${vehicle.id}`;
                seen.set(vehicle.id, { id: String(vehicle.id), label });
            }
        });
        return Array.from(seen.values()).sort((a, b) => a.label.localeCompare(b.label, 'fr'));
    }, [displayRows]);

    const filteredVehicleOptions = useMemo(() => {
        const needle = (vehicleSearchText || '').toLocaleLowerCase('fr').trim();
        if (!needle) return vehicleOptions;
        return vehicleOptions.filter((vehicle) => (vehicle.label || '').toLocaleLowerCase('fr').includes(needle));
    }, [vehicleOptions, vehicleSearchText]);

    const applyDates = () => {
        onDateFiltersChange?.({
            date_from: localDateFrom,
            date_to: localDateTo,
        });
        onApplyDateFilters?.({
            date_from: localDateFrom,
            date_to: localDateTo,
        });
        setShowDateFilter(false);
    };

    const clearDates = () => {
        setLocalDateFrom('');
        setLocalDateTo('');
        onDateFiltersChange?.({
            date_from: '',
            date_to: '',
        });
        onResetDateFilters?.();
        setShowDateFilter(false);
    };

    const selectDriverFilter = (value) => {
        setDriverFilter(value);
        setShowDriverFilter(false);
    };

    const selectFinFilter = (value) => {
        setFinFilter(value);
        setShowFinFilter(false);
    };

    const selectVehicleFilter = (value) => {
        setVehicleFilter(value);
        setShowVehicleFilter(false);
    };

    const toggleDateFilter = () => {
        if (showDateFilter) {
            setShowDateFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(dateFilterButtonRef, setDateFilterPos, 288);
        setShowDateFilter(true);
    };

    const toggleDriverFilter = () => {
        if (showDriverFilter) {
            setShowDriverFilter(false);
            return;
        }
        closeAllColumnFilters();
        setDriverSearchText('');
        updatePopoverPosition(driverFilterButtonRef, setDriverFilterPos, 288);
        setShowDriverFilter(true);
    };

    const toggleFinFilter = () => {
        if (showFinFilter) {
            setShowFinFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(finFilterButtonRef, setFinFilterPos, 220);
        setShowFinFilter(true);
    };

    const toggleVehicleFilter = () => {
        if (showVehicleFilter) {
            setShowVehicleFilter(false);
            return;
        }
        closeAllColumnFilters();
        setVehicleSearchText('');
        updatePopoverPosition(vehicleFilterButtonRef, setVehicleFilterPos, 320);
        setShowVehicleFilter(true);
    };

    const toggleTaskFilter = () => {
        if (showTaskFilter) {
            setShowTaskFilter(false);
            setShowTaskColorMenu(false);
            return;
        }
        closeAllColumnFilters();
        setShowTaskColorMenu(false);
        updatePopoverPosition(taskFilterButtonRef, setTaskFilterPos, 320);
        setShowTaskFilter(true);
    };

    const toggleCommentFilter = () => {
        if (showCommentFilter) {
            setShowCommentFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(commentFilterButtonRef, setCommentFilterPos, 320);
        setShowCommentFilter(true);
    };

    const toggleXdbFilter = () => {
        if (showXdbFilter) {
            setShowXdbFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(xdbFilterButtonRef, setXdbFilterPos, 340);
        setShowXdbFilter(true);
    };

    return (
        <div ref={wrapperRef} className="relative hidden w-full max-w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-sm lg:block">
            <div className="overflow-visible rounded-xl border border-[var(--app-border)]">
                <table className="min-w-[1180px] w-full border-collapse text-sm">
                    <thead className="sticky top-[var(--app-navbar-height,72px)] z-20">
                        <tr className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)] [&>th]:bg-[var(--app-surface-soft)] [&>th:first-child]:rounded-tl-xl [&>th:last-child]:rounded-tr-xl">
                            <th className="relative px-3 py-2 text-center">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Date</span>
                                    <button
                                        ref={dateFilterButtonRef}
                                        type="button"
                                        onClick={toggleDateFilter}
                                        title="Filtrer par date"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showDateFilter || dateFrom || dateTo
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>

                            </th>
                            <th className="relative px-3 py-2 text-center">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Fin</span>
                                    <button
                                        ref={finFilterButtonRef}
                                        type="button"
                                        onClick={toggleFinFilter}
                                        title="Filtrer fin"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showFinFilter || finFilter
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="relative px-3 py-2 text-center">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Chauffeur</span>
                                    <button
                                        ref={driverFilterButtonRef}
                                        type="button"
                                        onClick={toggleDriverFilter}
                                        title="Filtrer chauffeur"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showDriverFilter || driverFilter
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>

                            </th>
                            <th className="relative px-3 py-2 text-center">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Camion</span>
                                    <button
                                        ref={vehicleFilterButtonRef}
                                        type="button"
                                        onClick={toggleVehicleFilter}
                                        title="Filtrer camion"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showVehicleFilter || vehicleFilter
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="relative px-3 py-2 text-center">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Tâche</span>
                                    <button
                                        ref={taskFilterButtonRef}
                                        type="button"
                                        onClick={toggleTaskFilter}
                                        title="Filtrer tâche"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showTaskFilter || taskTextFilter || taskColorFilter
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="relative px-3 py-2 text-center">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Commentaires</span>
                                    <button
                                        ref={commentFilterButtonRef}
                                        type="button"
                                        onClick={toggleCommentFilter}
                                        title="Filtrer commentaires"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showCommentFilter || commentTextFilter
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="px-2 py-2 text-center">X</th>
                            <th className="px-2 py-2 text-center">D</th>
                            <th className="relative px-2 py-2 text-center">
                                <span>B</span>
                                <button
                                    ref={xdbFilterButtonRef}
                                    type="button"
                                    onClick={toggleXdbFilter}
                                    title="Filtres X / D / B"
                                    className={`absolute left-full top-1/2 ml-1 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md border ${
                                            showXdbFilter || xFilterMode !== 'all' || dFilterMode !== 'all' || bFilterMode !== 'all'
                                            ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                            : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                    }`}
                                >
                                    <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                </button>
                            </th>
                            <th className="px-3 py-2 text-center">Actions</th>
                            <th className="px-2 py-2 text-center">↕</th>
                        </tr>
                    </thead>
                    <tbody>
                        {displayRows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={12}
                                    className="px-4 py-6 text-center text-sm text-[var(--app-muted)]"
                                >
                                    Aucune tâche pour les filtres sélectionnés.
                                </td>
                            </tr>
                        ) : (
                            displayRows.map(({ group, task }) => {
                                const isDropTarget = Boolean(
                                    dropPreview
                                    && String(dropPreview.groupKey) === String(group.key)
                                    && Number(dropPreview.targetTaskId) === Number(task?.id),
                                );
                                const dropPosition = isDropTarget ? dropPreview.position : null;

                                return (
                                    <Fragment key={task.id}>
                                        {dropPosition === 'before' ? <DropIndicatorRow /> : null}
                                        <TaskDataRow
                                            group={group}
                                            task={task}
                                            placeResolver={depotPlaceMap}
                                            highlighted={Number(highlightedTaskId || 0) === Number(task?.id || 0)}
                                            saving={Boolean(savingTaskIds?.[task?.id])}
                                            canUpdate={canUpdate}
                                            canDelete={canDelete}
                                            canPoint={canPoint}
                                            onEditTask={onEditTask}
                                            onDuplicateTask={onDuplicateTask}
                                            onDeleteTask={onDeleteTask}
                                            onTogglePoint={onTogglePoint}
                                            onDragStartTask={onDragStartTask}
                                            onDragEndTask={onDragEndTask}
                                            onDragOverTask={onDragOverTask}
                                            onDropTask={onDropTask}
                                            onOpenAssigneeContact={setAssigneeContact}
                                            dropPosition={dropPosition}
                                        />
                                        {dropPosition === 'after' ? <DropIndicatorRow /> : null}
                                    </Fragment>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            <AssigneeContactModal contact={assigneeContact} onClose={() => setAssigneeContact(null)} />

            {showDateFilter ? (
                <div
                    ref={datePopoverRef}
                    className="absolute z-30 w-72 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${dateFilterPos.top}px`, left: `${dateFilterPos.left}px` }}
                >
                    <div className="grid gap-3">
                        <div>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Du
                            </label>
                            <input
                                type="date"
                                value={localDateFrom}
                                onChange={(e) => setLocalDateFrom(e.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Au
                            </label>
                            <input
                                type="date"
                                value={localDateTo}
                                onChange={(e) => setLocalDateTo(e.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={clearDates}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                            >
                                Vider
                            </button>
                            <button
                                type="button"
                                onClick={applyDates}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--color-black)]"
                            >
                                Appliquer
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}

            {showFinFilter ? (
                <div
                    ref={finPopoverRef}
                    className="absolute z-30 w-56 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-lg"
                    style={{ top: `${finFilterPos.top}px`, left: `${finFilterPos.left}px` }}
                >
                    <div className="max-h-72 overflow-y-auto">
                        <button
                            type="button"
                            onClick={() => selectFinFilter('__none__')}
                            className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                finFilter === '__none__'
                                    ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                    : 'hover:bg-[var(--app-surface-soft)]'
                            }`}
                        >
                            <span>Sans fin</span>
                            {finFilter === '__none__' ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                        </button>

                        {finOptions.length ? <div className="my-2 h-px bg-[var(--app-border)]" /> : null}

                        {finOptions.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => selectFinFilter(option.value)}
                                className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                    finFilter === option.value
                                        ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                        : 'hover:bg-[var(--app-surface-soft)]'
                                }`}
                            >
                                <span>{option.label}</span>
                                {finFilter === option.value ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                            </button>
                        ))}
                    </div>

                    <div className="mt-2 flex justify-end border-t border-[var(--app-border)] pt-2">
                        <button
                            type="button"
                            onClick={() => selectFinFilter('')}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                        >
                            Tous
                        </button>
                    </div>
                </div>
            ) : null}

            {showDriverFilter ? (
                <div
                    ref={driverPopoverRef}
                    className="absolute z-30 w-72 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-lg"
                    style={{ top: `${driverFilterPos.top}px`, left: `${driverFilterPos.left}px` }}
                >
                    <div className="mb-2">
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Rechercher
                        </label>
                        <div className="relative mt-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                            <input
                                type="text"
                                value={driverSearchText}
                                onChange={(e) => setDriverSearchText(e.target.value)}
                                placeholder="Nom chauffeur / dépôt..."
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                                autoFocus
                            />
                        </div>
                    </div>

                    <div className="max-h-72 overflow-y-auto">
                        <button
                            type="button"
                            onClick={() => selectDriverFilter('__none__')}
                            className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                driverFilter === '__none__'
                                    ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                    : 'hover:bg-[var(--app-surface-soft)]'
                            }`}
                        >
                            <span>Sans chauffeur</span>
                            {driverFilter === '__none__' ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                        </button>

                        {filteredDriverOptions.length ? <div className="my-2 h-px bg-[var(--app-border)]" /> : null}

                        {filteredDriverOptions.map((driver) => (
                            <button
                                key={driver.id}
                                type="button"
                                onClick={() => selectDriverFilter(driver.id)}
                                className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                    driverFilter === driver.id
                                        ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                        : 'hover:bg-[var(--app-surface-soft)]'
                                }`}
                            >
                                <span>{driver.label || driver.name}</span>
                                {driverFilter === driver.id ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                            </button>
                        ))}
                    </div>

                    <div className="mt-2 flex justify-end border-t border-[var(--app-border)] pt-2">
                        <button
                            type="button"
                            onClick={() => selectDriverFilter('')}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                        >
                            Tous
                        </button>
                    </div>
                </div>
            ) : null}

            {showVehicleFilter ? (
                <div
                    ref={vehiclePopoverRef}
                    className="absolute z-30 w-80 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-lg"
                    style={{ top: `${vehicleFilterPos.top}px`, left: `${vehicleFilterPos.left}px` }}
                >
                    <div className="mb-2">
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Rechercher
                        </label>
                        <div className="relative mt-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                            <input
                                type="text"
                                value={vehicleSearchText}
                                onChange={(e) => setVehicleSearchText(e.target.value)}
                                placeholder="Nom / immatriculation..."
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                                autoFocus
                            />
                        </div>
                    </div>

                    <div className="max-h-72 overflow-y-auto">
                        <button
                            type="button"
                            onClick={() => selectVehicleFilter('__none__')}
                            className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                vehicleFilter === '__none__'
                                    ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                    : 'hover:bg-[var(--app-surface-soft)]'
                            }`}
                        >
                            <span>Sans camion</span>
                            {vehicleFilter === '__none__' ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                        </button>

                        {filteredVehicleOptions.length ? <div className="my-2 h-px bg-[var(--app-border)]" /> : null}

                        {filteredVehicleOptions.map((vehicle) => (
                            <button
                                key={vehicle.id}
                                type="button"
                                onClick={() => selectVehicleFilter(vehicle.id)}
                                className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                    vehicleFilter === vehicle.id
                                        ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                        : 'hover:bg-[var(--app-surface-soft)]'
                                }`}
                            >
                                <span className="pr-2">{vehicle.label}</span>
                                {vehicleFilter === vehicle.id ? <Check className="h-4 w-4 shrink-0" strokeWidth={2.4} /> : null}
                            </button>
                        ))}
                    </div>

                    <div className="mt-2 flex justify-end border-t border-[var(--app-border)] pt-2">
                        <button
                            type="button"
                            onClick={() => selectVehicleFilter('')}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                        >
                            Tous
                        </button>
                    </div>
                </div>
            ) : null}

            {showTaskFilter ? (
                <div
                    ref={taskPopoverRef}
                    className="absolute z-30 w-80 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${taskFilterPos.top}px`, left: `${taskFilterPos.left}px` }}
                >
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Rechercher dans les tâches
                    </label>
                    <div className="relative mt-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={taskTextFilter}
                            onChange={(e) => setTaskTextFilter(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Escape' || e.key === 'Enter') {
                                    e.preventDefault();
                                    setShowTaskFilter(false);
                                    setShowTaskColorMenu(false);
                                }
                            }}
                            placeholder="Texte de tâche..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                            autoFocus
                        />
                    </div>
                    <div className="relative mt-2">
                        <button
                            type="button"
                            onClick={() => setShowTaskColorMenu((prev) => !prev)}
                            className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] ${
                                showTaskColorMenu || taskColorFilter
                                    ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                    : 'border-[var(--app-border)] bg-[var(--app-surface-soft)]'
                            }`}
                        >
                            <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                            <span>Filtrer par couleur</span>
                        </button>

                        {showTaskColorMenu ? (
                            <div className="absolute left-full top-0 z-40 ml-2 w-64 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-lg">
                                <div className="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                    Filtrer par couleur
                                </div>

                                <button
                                    type="button"
                                    onClick={() => {
                                        setTaskColorFilter('');
                                        setShowTaskColorMenu(false);
                                    }}
                                    className={`mb-2 flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                        !taskColorFilter
                                            ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                            : 'hover:bg-[var(--app-surface-soft)]'
                                    }`}
                                >
                                    <span>Toutes les couleurs</span>
                                    {!taskColorFilter ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                                </button>

                                <div className="max-h-64 space-y-1 overflow-y-auto border-t border-[var(--app-border)] pt-2">
                                    {taskColorOptions.length ? (
                                        taskColorOptions.map((option) => {
                                            const optionStyle = adaptiveTaskStyle({
                                                bg_color: option.bgColor || '',
                                                text_color: option.textColor || '',
                                            });

                                            return (
                                                <button
                                                    key={option.key}
                                                    type="button"
                                                    onClick={() => {
                                                        setTaskColorFilter(option.key);
                                                        setShowTaskColorMenu(false);
                                                    }}
                                                    className="flex w-full items-center justify-between rounded-lg border px-2.5 py-2 text-left text-sm"
                                                    style={{
                                                        ...optionStyle,
                                                        backgroundColor: optionStyle.backgroundColor || 'var(--app-surface-soft)',
                                                        color: optionStyle.color || 'var(--app-text)',
                                                        borderColor: 'var(--app-border)',
                                                    }}
                                                >
                                                    <span className="pr-2">{option.label}</span>
                                                    {taskColorFilter === option.key ? <Check className="h-4 w-4 shrink-0" strokeWidth={2.4} /> : null}
                                                </button>
                                            );
                                        })
                                    ) : (
                                        <div className="px-2 py-2 text-xs text-[var(--app-muted)]">
                                            Aucune couleur visible dans le tableau.
                                        </div>
                                    )}
                                </div>
                            </div>
                        ) : null}
                    </div>
                    <div className="mt-2 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                setTaskTextFilter('');
                                setTaskColorFilter('');
                            }}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                        >
                            Vider
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setShowTaskFilter(false);
                                setShowTaskColorMenu(false);
                            }}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--color-black)]"
                        >
                            Fermer
                        </button>
                    </div>
                </div>
            ) : null}

            {showCommentFilter ? (
                <div
                    ref={commentPopoverRef}
                    className="absolute z-30 w-80 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${commentFilterPos.top}px`, left: `${commentFilterPos.left}px` }}
                >
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Rechercher dans les commentaires
                    </label>
                    <div className="relative mt-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={commentTextFilter}
                            onChange={(e) => setCommentTextFilter(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Escape' || e.key === 'Enter') {
                                    e.preventDefault();
                                    setShowCommentFilter(false);
                                }
                            }}
                            placeholder="Texte du commentaire..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                            autoFocus
                        />
                    </div>
                    <div className="mt-2 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setCommentTextFilter('')}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                        >
                            Vider
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowCommentFilter(false)}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--color-black)]"
                        >
                            Fermer
                        </button>
                    </div>
                </div>
            ) : null}

            {showXdbFilter ? (
                <div
                    ref={xdbPopoverRef}
                    className="absolute z-30 w-[340px] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${xdbFilterPos.top}px`, left: `${xdbFilterPos.left}px` }}
                >
                    <div className="space-y-3">
                        <div className="space-y-2">
                            {[
                                ['X', 'Pointé', xFilterMode, setXFilterMode, 'Pointé', 'Non pointé'],
                                ['D', 'Direct', dFilterMode, setDFilterMode, 'Direct', 'Non direct'],
                                ['B', 'Boursagri', bFilterMode, setBFilterMode, 'Boursagri', 'Non boursagri'],
                            ].map(([code, label, mode, setMode, yesLabel, noLabel]) => (
                                <div key={code} className="grid grid-cols-[22px_1fr] items-center gap-2">
                                    <div className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        {code}
                                    </div>
                                    <div className="grid grid-cols-3 gap-1.5">
                                        {[
                                            ['all', 'Tous'],
                                            ['yes', yesLabel],
                                            ['no', noLabel],
                                        ].map(([value, pillLabel]) => (
                                            <button
                                                key={`${code}-${value}`}
                                                type="button"
                                                onClick={() => {
                                                    setMode(value);
                                                    if (code === 'B' && value !== 'yes') {
                                                        setBContractTextFilter('');
                                                        setBContractSort('asc');
                                                    }
                                                    if (code === 'B' && value === 'yes') {
                                                        setBContractSort('asc');
                                                    }
                                                }}
                                                className={`rounded-lg border px-2 py-1 text-[10px] font-bold uppercase tracking-[0.06em] leading-none ${
                                                    mode === value
                                                        ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                        : 'border-[var(--app-border)] bg-[var(--app-surface-soft)]'
                                                }`}
                                            >
                                                {pillLabel}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {bFilterMode === 'yes' ? (
                            <>
                                <div>
                                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Contrat Boursagri
                                    </label>
                                    <div className="relative mt-1">
                                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                                        <input
                                            type="text"
                                            value={bContractTextFilter}
                                            onChange={(e) => setBContractTextFilter(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Escape' || e.key === 'Enter') {
                                                    e.preventDefault();
                                                    setShowXdbFilter(false);
                                                }
                                            }}
                                            placeholder="Rechercher un contrat..."
                                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Tri contrat
                                    </label>
                                    <div className="mt-1 flex flex-wrap gap-2">
                                        {[
                                            ['asc', 'Croissant'],
                                            ['desc', 'Décroissant'],
                                        ].map(([value, label]) => (
                                            <button
                                                key={value}
                                                type="button"
                                                onClick={() => setBContractSort(value)}
                                                className={`rounded-lg border px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.08em] leading-none ${
                                                    bContractSort === value
                                                        ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                        : 'border-[var(--app-border)] bg-[var(--app-surface-soft)]'
                                                }`}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </>
                        ) : null}

                        <div className="flex justify-end gap-2 border-t border-[var(--app-border)] pt-2">
                            <button
                                type="button"
                                onClick={() => {
                                    setXFilterMode('all');
                                    setDFilterMode('all');
                                    setBFilterMode('all');
                                    setBContractTextFilter('');
                                    setBContractSort('asc');
                                }}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.08em] leading-none"
                            >
                                Effacer
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowXdbFilter(false)}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--color-black)] leading-none"
                            >
                                Fermer
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
