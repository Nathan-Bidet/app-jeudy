import FormattedText from '@/Components/FormattedText';
import {
    BoursagriIndicatorCell,
    DirectIndicatorCell,
    IndicatorsInline,
    PointedIndicatorCell,
} from '@/Components/Aprevoir/TaskStateIndicators';
import { Calendar, Check, Eye, Filter, RotateCcw, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { adaptiveTaskStyle } from '@/Support/taskColorStyle';

function labelForPagination(label) {
    const raw = String(label || '')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;/g, ' ')
        .trim();

    const normalized = raw
        .replace(/&laquo;|«/g, '')
        .replace(/&raquo;|»/g, '')
        .trim()
        .toLowerCase();

    if (
        normalized.includes('previous')
        || normalized.includes('précédent')
        || normalized.includes('precedent')
        || normalized.includes('pagination.previous')
    ) {
        return '←';
    }

    if (
        normalized.includes('next')
        || normalized.includes('suivant')
        || normalized.includes('pagination.next')
    ) {
        return '→';
    }

    return raw;
}

function rowStyle(row) {
    return adaptiveTaskStyle(row?.style || {});
}

export default function ArchiveTable({
    archives,
    perPageOptions = [25, 50, 100, 150],
    perPage = 50,
    filters = {},
    assigneeOptions = [],
    onFilterChange,
    onApplyFilters,
    onResetFilters,
    onChangePage,
    onPerPageChange,
    onOpenDetail,
    canManage = false,
    onRequestRestore,
}) {
    const wrapperRef = useRef(null);
    const dateFilterButtonRef = useRef(null);
    const assigneeFilterButtonRef = useRef(null);
    const taskFilterButtonRef = useRef(null);
    const contractFilterButtonRef = useRef(null);
    const businessFilterButtonRef = useRef(null);
    const datePopoverRef = useRef(null);
    const assigneePopoverRef = useRef(null);
    const taskPopoverRef = useRef(null);
    const contractPopoverRef = useRef(null);
    const businessPopoverRef = useRef(null);

    const rows = Array.isArray(archives?.data) ? archives.data : [];
    const links = Array.isArray(archives?.links) ? archives.links : [];

    const [showDateFilter, setShowDateFilter] = useState(false);
    const [showAssigneeFilter, setShowAssigneeFilter] = useState(false);
    const [showTaskFilter, setShowTaskFilter] = useState(false);
    const [showContractFilter, setShowContractFilter] = useState(false);
    const [showBusinessFilter, setShowBusinessFilter] = useState(false);
    const [dateFilterPos, setDateFilterPos] = useState({ top: 40, left: 12 });
    const [assigneeFilterPos, setAssigneeFilterPos] = useState({ top: 40, left: 200 });
    const [taskFilterPos, setTaskFilterPos] = useState({ top: 40, left: 420 });
    const [contractFilterPos, setContractFilterPos] = useState({ top: 40, left: 700 });
    const [businessFilterPos, setBusinessFilterPos] = useState({ top: 40, left: 840 });
    const [localDateFrom, setLocalDateFrom] = useState(filters.date_from || '');
    const [localDateTo, setLocalDateTo] = useState(filters.date_to || '');
    const [assigneeSearchText, setAssigneeSearchText] = useState('');
    const [taskSearchText, setTaskSearchText] = useState(filters.search || '');
    const [contractSearchText, setContractSearchText] = useState(filters.contract || '');
    const [localDirect, setLocalDirect] = useState(Boolean(filters.direct));
    const [localBoursagri, setLocalBoursagri] = useState(Boolean(filters.boursagri));
    const [localIndicators, setLocalIndicators] = useState(Array.isArray(filters.indicators) ? filters.indicators : []);

    useEffect(() => {
        setLocalDateFrom(filters.date_from || '');
        setLocalDateTo(filters.date_to || '');
        setTaskSearchText(filters.search || '');
        setContractSearchText(filters.contract || '');
        setLocalDirect(Boolean(filters.direct));
        setLocalBoursagri(Boolean(filters.boursagri));
        setLocalIndicators(Array.isArray(filters.indicators) ? filters.indicators : []);
    }, [filters.date_from, filters.date_to, filters.search, filters.contract, filters.direct, filters.boursagri, filters.indicators]);

    const closeAllColumnFilters = () => {
        setShowDateFilter(false);
        setShowAssigneeFilter(false);
        setShowTaskFilter(false);
        setShowContractFilter(false);
        setShowBusinessFilter(false);
    };

    useEffect(() => {
        const anyOpen = showDateFilter || showAssigneeFilter || showTaskFilter || showContractFilter || showBusinessFilter;
        if (!anyOpen) {
            return;
        }

        const nodes = [
            dateFilterButtonRef.current,
            assigneeFilterButtonRef.current,
            taskFilterButtonRef.current,
            contractFilterButtonRef.current,
            businessFilterButtonRef.current,
            datePopoverRef.current,
            assigneePopoverRef.current,
            taskPopoverRef.current,
            contractPopoverRef.current,
            businessPopoverRef.current,
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
    }, [showDateFilter, showAssigneeFilter, showTaskFilter, showContractFilter, showBusinessFilter]);

    const updatePopoverPosition = (buttonRef, setPos, width = 320) => {
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

    const applyFilters = (partial = {}) => {
        onApplyFilters?.({
            ...filters,
            ...partial,
        });
    };

    const toggleDateFilter = () => {
        if (showDateFilter) {
            setShowDateFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(dateFilterButtonRef, setDateFilterPos, 280);
        setShowDateFilter(true);
    };

    const toggleAssigneeFilter = () => {
        if (showAssigneeFilter) {
            setShowAssigneeFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(assigneeFilterButtonRef, setAssigneeFilterPos, 320);
        setShowAssigneeFilter(true);
    };

    const toggleTaskFilter = () => {
        if (showTaskFilter) {
            setShowTaskFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(taskFilterButtonRef, setTaskFilterPos, 320);
        setShowTaskFilter(true);
    };

    const toggleContractFilter = () => {
        if (showContractFilter) {
            setShowContractFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(contractFilterButtonRef, setContractFilterPos, 300);
        setShowContractFilter(true);
    };

    const toggleBusinessFilter = () => {
        if (showBusinessFilter) {
            setShowBusinessFilter(false);
            return;
        }
        closeAllColumnFilters();
        updatePopoverPosition(businessFilterButtonRef, setBusinessFilterPos, 360);
        setShowBusinessFilter(true);
    };

    const applyDateFilters = () => {
        onFilterChange?.('date_from', localDateFrom);
        onFilterChange?.('date_to', localDateTo);
        applyFilters({
            date_from: localDateFrom,
            date_to: localDateTo,
        });
        setShowDateFilter(false);
    };

    const clearDateFilters = () => {
        setLocalDateFrom('');
        setLocalDateTo('');
        onFilterChange?.('date_from', '');
        onFilterChange?.('date_to', '');
        applyFilters({
            date_from: '',
            date_to: '',
        });
        setShowDateFilter(false);
    };

    const selectAssigneeFilter = (value) => {
        onFilterChange?.('assignee', value);
        applyFilters({ assignee: value });
        setShowAssigneeFilter(false);
    };

    const applyTaskFilter = () => {
        onFilterChange?.('search', taskSearchText);
        applyFilters({ search: taskSearchText });
        setShowTaskFilter(false);
    };

    const clearTaskFilter = () => {
        setTaskSearchText('');
        onFilterChange?.('search', '');
        applyFilters({ search: '' });
        setShowTaskFilter(false);
    };

    const applyContractFilter = () => {
        onFilterChange?.('contract', contractSearchText);
        applyFilters({ contract: contractSearchText });
        setShowContractFilter(false);
    };

    const clearContractFilter = () => {
        setContractSearchText('');
        onFilterChange?.('contract', '');
        applyFilters({ contract: '' });
        setShowContractFilter(false);
    };

    const applyBusinessFilters = () => {
        onFilterChange?.('direct', localDirect);
        onFilterChange?.('boursagri', localBoursagri);
        onFilterChange?.('indicators', localIndicators);
        onFilterChange?.('contract', localBoursagri ? contractSearchText : '');
        applyFilters({
            direct: localDirect,
            boursagri: localBoursagri,
            indicators: localIndicators,
            contract: localBoursagri ? contractSearchText : '',
        });
        setShowBusinessFilter(false);
    };

    const clearBusinessFilters = () => {
        setLocalDirect(false);
        setLocalBoursagri(false);
        setLocalIndicators([]);
        setContractSearchText('');
        onFilterChange?.('direct', false);
        onFilterChange?.('boursagri', false);
        onFilterChange?.('indicators', []);
        onFilterChange?.('contract', '');
        applyFilters({
            direct: false,
            boursagri: false,
            indicators: [],
            contract: '',
        });
        setShowBusinessFilter(false);
    };

    const filteredAssigneeOptions = useMemo(() => {
        const needle = assigneeSearchText.trim().toLowerCase();
        if (!needle) {
            return assigneeOptions;
        }

        return assigneeOptions.filter((option) =>
            String(option?.label || '')
                .toLowerCase()
                .includes(needle)
        );
    }, [assigneeOptions, assigneeSearchText]);

    const isBusinessFilterActive = Boolean(
        filters.direct
        || filters.boursagri
        || (Array.isArray(filters.indicators) && filters.indicators.length > 0)
    );

    return (
        <section ref={wrapperRef} className="relative rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold text-[var(--app-text)]">Lignes archivées</h2>
                <div className="flex items-center gap-2">
                    <p className="text-sm text-[var(--app-muted)]">
                        {archives?.total ?? rows.length} ligne(s)
                    </p>
                    {onResetFilters ? (
                        <button
                            type="button"
                            onClick={onResetFilters}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="mt-4 overflow-auto rounded-xl border border-[var(--app-border)]">
                <table className="min-w-[1180px] w-full border-collapse text-sm">
                    <thead>
                        <tr className="bg-[var(--app-surface-soft)] text-left text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">
                            <th className="border-b border-[var(--app-border)] px-3 py-2">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Date</span>
                                    <button
                                        ref={dateFilterButtonRef}
                                        type="button"
                                        onClick={toggleDateFilter}
                                        title="Filtrer par date"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showDateFilter || filters.date_from || filters.date_to
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="border-b border-[var(--app-border)] px-3 py-2">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Assigné</span>
                                    <button
                                        ref={assigneeFilterButtonRef}
                                        type="button"
                                        onClick={toggleAssigneeFilter}
                                        title="Filtrer assigné"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showAssigneeFilter || filters.assignee
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="border-b border-[var(--app-border)] px-3 py-2">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Tâche</span>
                                    <button
                                        ref={taskFilterButtonRef}
                                        type="button"
                                        onClick={toggleTaskFilter}
                                        title="Filtrer tâche / recherche"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showTaskFilter || filters.search
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="border-b border-[var(--app-border)] px-3 py-2">
                                <div className="flex items-center justify-center gap-2">
                                    <span>Commentaires</span>
                                    <button
                                        ref={contractFilterButtonRef}
                                        type="button"
                                        onClick={toggleContractFilter}
                                        title="Filtrer contrat Boursagri"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showContractFilter || filters.contract
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="border-b border-[var(--app-border)] px-2 py-2 text-center">X</th>
                            <th className="border-b border-[var(--app-border)] px-2 py-2 text-center">D</th>
                            <th className="border-b border-[var(--app-border)] px-2 py-2 text-center">
                                <div className="flex items-center justify-center gap-1.5">
                                    <span>B</span>
                                    <button
                                        ref={businessFilterButtonRef}
                                        type="button"
                                        onClick={toggleBusinessFilter}
                                        title="Filtres métier (D/B/Indicateurs)"
                                        className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                                            showBusinessFilter || isBusinessFilterActive
                                                ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                                        }`}
                                    >
                                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    </button>
                                </div>
                            </th>
                            <th className="border-b border-[var(--app-border)] px-3 py-2">Archivé le</th>
                            <th className="border-b border-[var(--app-border)] px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={9} className="px-3 py-6 text-center text-sm text-[var(--app-muted)]">
                                    Aucune ligne archivée pour ces filtres.
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr
                                    key={row.id}
                                    className="odd:bg-[var(--app-surface)] even:bg-[var(--app-surface-soft)]"
                                    style={rowStyle(row)}
                                >
                                    <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">
                                        <div className="font-semibold">{row.date_label || '—'}</div>
                                        {row.original_task_id ? (
                                            <div className="text-xs text-[var(--app-muted)]">
                                                Source #{row.original_task_id}
                                            </div>
                                        ) : null}
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">
                                        <div className="font-medium">{row.assignee_label || 'Sans assigné'}</div>
                                        <div className="text-xs text-[var(--app-muted)]">
                                            {row.assignee_meta || '—'}
                                        </div>
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">
                                        <FormattedText className="line-clamp-3 font-medium" text={row.task || '—'} multiline />
                                        {row.is_boursagri && row.boursagri_contract_number ? (
                                            <div className="mt-1 text-xs text-[var(--app-muted)]">
                                                Contrat: {row.boursagri_contract_number}
                                            </div>
                                        ) : null}
                                        <IndicatorsInline indicators={row?.indicators || []} />
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">
                                        {row.comment ? (
                                            <FormattedText className="line-clamp-3 text-xs text-[var(--app-muted)]" text={row.comment} multiline />
                                        ) : (
                                            <span className="text-[var(--app-muted)]">—</span>
                                        )}
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-2 py-2 text-center">
                                        <PointedIndicatorCell pointed={row?.pointed} />
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-2 py-2 text-center">
                                        <DirectIndicatorCell isDirect={row?.is_direct} />
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-2 py-2 text-center">
                                        <BoursagriIndicatorCell isBoursagri={row?.is_boursagri} />
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">
                                        <div>{row.archived_at_label || '—'}</div>
                                        <div className="text-xs text-[var(--app-muted)]">
                                            {row.archived_by_system ? 'Automatique' : 'Manuel'}
                                        </div>
                                    </td>
                                    <td className="border-b border-[var(--app-border)] px-3 py-2 text-right">
                                        <div className="inline-flex items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={() => onOpenDetail(row)}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-xs font-semibold text-[var(--app-text)]"
                                            >
                                                <Eye className="h-3.5 w-3.5" />
                                                Voir
                                            </button>
                                            {canManage ? (
                                                <button
                                                    type="button"
                                                    onClick={() => onRequestRestore?.(row)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/50 bg-emerald-500/10 px-2 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                                                >
                                                    <RotateCcw className="h-3.5 w-3.5" />
                                                    Restaurer
                                                </button>
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {links.length > 0 ? (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {links.map((link, index) => (
                            <button
                                key={`${link.label}-${index}`}
                                type="button"
                                disabled={!link.url || link.active}
                                onClick={() => onChangePage(link.url)}
                                className={`rounded-xl border px-2.5 py-1.5 text-xs ${
                                    link.active
                                        ? 'border-[var(--app-border)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                        : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] disabled:opacity-40'
                                }`}
                            >
                                {labelForPagination(link.label)}
                            </button>
                        ))}
                    </div>

                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium text-[var(--app-muted)]">Lignes/page</label>
                        <select
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-sm text-[var(--app-text)]"
                            value={String(perPage)}
                            onChange={(event) => onPerPageChange(event.target.value)}
                        >
                            {perPageOptions.map((size) => (
                                <option key={size} value={size}>
                                    {size}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            ) : null}

            {showDateFilter ? (
                <div
                    ref={datePopoverRef}
                    className="absolute z-30 w-[280px] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${dateFilterPos.top}px`, left: `${dateFilterPos.left}px` }}
                >
                    <div className="space-y-3">
                        <label>
                            <span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Du</span>
                            <span className="flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3">
                                <Calendar className="h-4 w-4 text-[var(--app-muted)]" />
                                <input
                                    type="date"
                                    value={localDateFrom}
                                    onChange={(event) => setLocalDateFrom(event.target.value)}
                                    className="h-10 w-full border-0 bg-transparent p-0 text-sm text-[var(--app-text)] focus:outline-none focus:ring-0"
                                />
                            </span>
                        </label>

                        <label>
                            <span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Au</span>
                            <span className="flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3">
                                <Calendar className="h-4 w-4 text-[var(--app-muted)]" />
                                <input
                                    type="date"
                                    value={localDateTo}
                                    onChange={(event) => setLocalDateTo(event.target.value)}
                                    className="h-10 w-full border-0 bg-transparent p-0 text-sm text-[var(--app-text)] focus:outline-none focus:ring-0"
                                />
                            </span>
                        </label>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={clearDateFilters}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                            >
                                Vider
                            </button>
                            <button
                                type="button"
                                onClick={applyDateFilters}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--color-black)]"
                            >
                                Appliquer
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}

            {showAssigneeFilter ? (
                <div
                    ref={assigneePopoverRef}
                    className="absolute z-30 w-[320px] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-lg"
                    style={{ top: `${assigneeFilterPos.top}px`, left: `${assigneeFilterPos.left}px` }}
                >
                    <div className="mb-2">
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Rechercher
                        </label>
                        <div className="relative mt-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                            <input
                                type="text"
                                value={assigneeSearchText}
                                onChange={(event) => setAssigneeSearchText(event.target.value)}
                                placeholder="Nom assigné..."
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                                autoFocus
                            />
                        </div>
                    </div>

                    <div className="max-h-72 overflow-y-auto">
                        <button
                            type="button"
                            onClick={() => selectAssigneeFilter('')}
                            className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                !filters.assignee
                                    ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                    : 'hover:bg-[var(--app-surface-soft)]'
                            }`}
                        >
                            <span>Tous</span>
                            {!filters.assignee ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                        </button>

                        {filteredAssigneeOptions.length ? <div className="my-2 h-px bg-[var(--app-border)]" /> : null}

                        {filteredAssigneeOptions.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => selectAssigneeFilter(option.value)}
                                className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                    filters.assignee === option.value
                                        ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                        : 'hover:bg-[var(--app-surface-soft)]'
                                }`}
                            >
                                <span>{option.label}</span>
                                {filters.assignee === option.value ? <Check className="h-4 w-4" strokeWidth={2.4} /> : null}
                            </button>
                        ))}
                    </div>
                </div>
            ) : null}

            {showTaskFilter ? (
                <div
                    ref={taskPopoverRef}
                    className="absolute z-30 w-[320px] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${taskFilterPos.top}px`, left: `${taskFilterPos.left}px` }}
                >
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Rechercher dans les tâches
                    </label>
                    <div className="relative mt-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={taskSearchText}
                            onChange={(event) => setTaskSearchText(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    applyTaskFilter();
                                }
                            }}
                            placeholder="Texte tâche/commentaire..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                            autoFocus
                        />
                    </div>
                    <div className="mt-3 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={clearTaskFilter}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                        >
                            Vider
                        </button>
                        <button
                            type="button"
                            onClick={applyTaskFilter}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--color-black)]"
                        >
                            Appliquer
                        </button>
                    </div>
                </div>
            ) : null}

            {showContractFilter ? (
                <div
                    ref={contractPopoverRef}
                    className="absolute z-30 w-[300px] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${contractFilterPos.top}px`, left: `${contractFilterPos.left}px` }}
                >
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Contrat Boursagri
                    </label>
                    <input
                        type="text"
                        value={contractSearchText}
                        onChange={(event) => setContractSearchText(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                applyContractFilter();
                            }
                        }}
                        placeholder="Numéro de contrat"
                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        autoFocus
                    />
                    <div className="mt-3 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={clearContractFilter}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                        >
                            Vider
                        </button>
                        <button
                            type="button"
                            onClick={applyContractFilter}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--color-black)]"
                        >
                            Appliquer
                        </button>
                    </div>
                </div>
            ) : null}

            {showBusinessFilter ? (
                <div
                    ref={businessPopoverRef}
                    className="absolute z-30 w-[360px] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-lg"
                    style={{ top: `${businessFilterPos.top}px`, left: `${businessFilterPos.left}px` }}
                >
                    <div className="space-y-3">
                        <div>
                            <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Direct / Boursagri
                            </p>
                            <div className="mt-2 grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() => setLocalDirect((prev) => !prev)}
                                    className={`rounded-lg border px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] ${
                                        localDirect
                                            ? 'border-emerald-500/60 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                            : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]'
                                    }`}
                                >
                                    Direct
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setLocalBoursagri((prev) => !prev)}
                                    className={`rounded-lg border px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] ${
                                        localBoursagri
                                            ? 'border-amber-500/60 bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                            : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]'
                                    }`}
                                >
                                    Boursagri
                                </button>
                            </div>
                        </div>

                        {localBoursagri ? (
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                    Contrat Boursagri
                                </label>
                                <input
                                    type="text"
                                    value={contractSearchText}
                                    onChange={(event) => setContractSearchText(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            applyBusinessFilters();
                                        }
                                    }}
                                    placeholder="Numéro de contrat"
                                    className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                />
                            </div>
                        ) : null}

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={clearBusinessFilters}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                            >
                                Vider
                            </button>
                            <button
                                type="button"
                                onClick={applyBusinessFilters}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--color-black)]"
                            >
                                Appliquer
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
