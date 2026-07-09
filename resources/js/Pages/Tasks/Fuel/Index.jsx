import AppLayout from '@/Layouts/AppLayout';
import Modal from '@/Components/Modal';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, BarChart2, Check, ChevronDown, ChevronUp, Filter, Pencil, Plus, RefreshCw, Search, Settings, Trash2, X } from 'lucide-react';
import { createPortal } from 'react-dom';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';

const EMPTY_ORDER = { fuel_type: '', volume: '', urgent: false };
const EMPTY_DELIVERY_FIELDS = { site: '' };
const EMPTY_CLIENT_FIELDS = {
    code: '',
    name: '',
    phone: '',
    address: '',
    postal_code: '',
    city: '',
};

const COLUMNS = [
    { key: 'delivery_date', label: 'Date Liv.', type: 'Date', align: 'text-center' },
    { key: 'site', label: 'Site', type: 'Liste' },
    { key: 'client', label: 'Client / Code Tiers', type: 'Relation tiers', minWidth: 'min-w-[190px]' },
    { key: 'phone', label: 'N° Tél', type: 'Auto', align: 'text-center' },
    { key: 'delivery_city', label: 'Commune Liv.', type: 'Auto' },
    { key: 'fuel_type', label: 'Type', type: 'Liste' },
    { key: 'volume', label: 'Volume', type: 'Litrage', align: 'text-right' },
    { key: 'comment', label: 'Commentaire', type: 'Texte', minWidth: 'min-w-[180px]' },
    { key: 'info', label: 'Info', type: 'Information', minWidth: 'min-w-[150px]' },
];

const EMPTY_FILTERS = {
    delivery_date: { mode: '', value: '', from: '', to: '' },
    site: '',
    client: '',
    phone: '',
    delivery_city: '',
    fuel_type: '',
    volume: { mode: '', value: '', from: '', to: '' },
    comment: '',
    info: { text: '', urgent: '', delivered: '' },
};

function cloneFilters(filters = {}) {
    return {
        ...EMPTY_FILTERS,
        ...filters,
        delivery_date: { ...EMPTY_FILTERS.delivery_date, ...(filters.delivery_date || {}) },
        volume: { ...EMPTY_FILTERS.volume, ...(filters.volume || {}) },
        info: { ...EMPTY_FILTERS.info, ...(filters.info || {}) },
    };
}

function hasColumnFilter(filters, key) {
    const value = filters[key];
    if (value === undefined || value === null) return false;
    if (typeof value === 'object') {
        return Object.values(value).some((item) => String(item || '').trim() !== '');
    }
    return String(value || '').trim() !== '';
}

function hasAnyFilter(filters) {
    return Object.keys(EMPTY_FILTERS).some((key) => hasColumnFilter(filters, key));
}

function normalizeDeliveryPayload(deliveries) {
    if (Array.isArray(deliveries)) {
        return {
            rows: deliveries,
            meta: {
                total: deliveries.length,
            },
        };
    }

    return {
        rows: Array.isArray(deliveries?.data) ? deliveries.data : [],
        meta: {
            total: deliveries?.meta?.total || 0,
        },
    };
}

function applyDefaultSort(rows) {
    return [...rows].sort((a, b) => {
        const aNoDate = !a.delivery_date_value;
        const bNoDate = !b.delivery_date_value;
        if (aNoDate !== bNoDate) return aNoDate ? -1 : 1;
        if (aNoDate) return (a.created_at_iso || '').localeCompare(b.created_at_iso || '');
        const byDate = (a.delivery_date_value || '').localeCompare(b.delivery_date_value || '');
        if (byDate !== 0) return byDate;
        const bySite = (a.site || '').localeCompare(b.site || '', undefined, { sensitivity: 'base' });
        if (bySite !== 0) return bySite;
        return (a.created_at_iso || '').localeCompare(b.created_at_iso || '');
    });
}

function computeStatGroups(rows) {
    const groups = [];
    const keyToGroup = new Map();
    for (const row of rows) {
        const dateKey = row.delivery_date_value || '__no_date__';
        if (!keyToGroup.has(dateKey)) {
            const g = { dateKey, dateLabel: row.delivery_date || null, count: 0, bySite: {}, byProduct: {} };
            keyToGroup.set(dateKey, g);
            groups.push(g);
        }
        const g = keyToGroup.get(dateKey);
        g.count += 1;
        const site = row.site || '(sans site)';
        g.bySite[site] = (g.bySite[site] || 0) + 1;
        const product = row.fuel_type || '(sans type)';
        const vol = parseFloat(String(row.volume || '').replace(/\s/g, '').replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
        g.byProduct[product] = (g.byProduct[product] || 0) + vol;
    }
    return groups;
}

function formatStatVol(liters) {
    if (liters === 0) return '0 L';
    const r = Math.round(liters * 10) / 10;
    return `${r % 1 === 0 ? Math.round(r) : r} L`;
}

function formatMonthlyVol(liters) {
    const abs = Math.abs(liters ?? 0);
    if (abs === 0) return '0 L';
    return `${Number(abs).toLocaleString('fr-FR')} L`;
}

function fuelRowStateClass(row) {
    const rec = row?.is_recurring;
    if (row?.is_delivered && row?.urgent) {
        return rec
            ? 'bg-gradient-to-r from-emerald-50/60 via-sky-50/30 to-red-50/60'
            : 'bg-gradient-to-r from-emerald-50/70 via-emerald-50/45 to-red-50/70';
    }

    if (row?.is_delivered) {
        return rec ? 'bg-gradient-to-r from-emerald-50/40 to-sky-50/30' : 'bg-emerald-50/40';
    }

    if (row?.urgent) {
        return rec ? 'bg-gradient-to-r from-sky-50/40 to-red-50/40' : 'bg-red-50/50';
    }

    return rec ? 'bg-sky-50/40' : '';
}

function fuelCardRingClass(row) {
    if (row?.is_delivered && row?.urgent) {
        return 'ring-1 ring-amber-200';
    }

    if (row?.is_delivered) {
        return 'ring-1 ring-emerald-200';
    }

    if (row?.urgent) {
        return 'ring-1 ring-red-100';
    }

    if (row?.is_recurring) {
        return 'ring-1 ring-sky-200';
    }

    return '';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function todayIsoDate() {
    const now = new Date();
    const localDate = new Date(now.getTime() - (now.getTimezoneOffset() * 60000));
    return localDate.toISOString().slice(0, 10);
}

async function copyTextToClipboard(text) {
    if (!text) return;

    if (navigator?.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
}

async function jsonRequest(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers || {}),
        },
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(data?.message || `Erreur HTTP ${response.status}`);
    }

    return data;
}

function FuelFilterFields({ column, draft, onDraftChange, onImmediateApply, siteOptions, productTypeOptions }) {
    if (column.key === 'delivery_date') {
        const dateMode = draft.mode || 'exact';
        return (
            <div className="space-y-2">
                <select
                    value={dateMode}
                    onChange={(event) => onDraftChange({ ...draft, mode: event.target.value })}
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                >
                    <option value="exact">Date exacte</option>
                    <option value="before">Avant le</option>
                    <option value="after">Après le</option>
                    <option value="between">Entre deux dates</option>
                </select>
                {dateMode === 'between' ? (
                    <div className="grid grid-cols-2 gap-2">
                        <input
                            type="date"
                            value={draft.from || ''}
                            onChange={(event) => onDraftChange({ ...draft, mode: dateMode, from: event.target.value })}
                            className="min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                        />
                        <input
                            type="date"
                            value={draft.to || ''}
                            onChange={(event) => onDraftChange({ ...draft, mode: dateMode, to: event.target.value })}
                            className="min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                        />
                    </div>
                ) : (
                    <input
                        type="date"
                        value={draft.value || ''}
                        onChange={(event) => onDraftChange({ ...draft, mode: dateMode, value: event.target.value })}
                        className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                    />
                )}
            </div>
        );
    }

    if (column.key === 'site' || column.key === 'fuel_type') {
        const options = column.key === 'site' ? siteOptions : productTypeOptions;
        return (
            <select
                value={draft || ''}
                onChange={(event) => {
                    onDraftChange(event.target.value);
                    onImmediateApply(event.target.value);
                }}
                className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
            >
                <option value="">Toutes les valeurs</option>
                {options.map((option) => (
                    <option key={option.id || option.label} value={option.label}>
                        {option.label}
                    </option>
                ))}
            </select>
        );
    }

    if (column.key === 'volume') {
        return (
            <div className="space-y-2">
                <select
                    value={draft.mode || ''}
                    onChange={(event) => onDraftChange({ ...draft, mode: event.target.value })}
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                >
                    <option value="">Type de filtre</option>
                    <option value="eq">Égal à</option>
                    <option value="gt">Supérieur à</option>
                    <option value="lt">Inférieur à</option>
                    <option value="between">Entre deux valeurs</option>
                </select>
                {draft.mode === 'between' ? (
                    <div className="grid grid-cols-2 gap-2">
                        <input
                            type="number"
                            min="0"
                            value={draft.from || ''}
                            onChange={(event) => onDraftChange({ ...draft, from: event.target.value })}
                            placeholder="Min"
                            className="min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                        />
                        <input
                            type="number"
                            min="0"
                            value={draft.to || ''}
                            onChange={(event) => onDraftChange({ ...draft, to: event.target.value })}
                            placeholder="Max"
                            className="min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                        />
                    </div>
                ) : (
                    <input
                        type="number"
                        min="0"
                        value={draft.value || ''}
                        onChange={(event) => onDraftChange({ ...draft, value: event.target.value })}
                        placeholder="Volume"
                        className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                    />
                )}
            </div>
        );
    }

    if (column.key === 'info') {
        return (
            <div className="space-y-2">
                <input
                    type="text"
                    value={draft.text || ''}
                    onChange={(event) => onDraftChange({ ...draft, text: event.target.value })}
                    placeholder="Date, créateur..."
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                />
                <select
                    value={draft.urgent || ''}
                    onChange={(event) => {
                        const next = { ...draft, urgent: event.target.value };
                        onDraftChange(next);
                        onImmediateApply(next);
                    }}
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                >
                    <option value="">Urgent oui/non</option>
                    <option value="yes">Urgent uniquement</option>
                    <option value="no">Non urgent uniquement</option>
                </select>
                <select
                    value={draft.delivered || ''}
                    onChange={(event) => {
                        const next = { ...draft, delivered: event.target.value };
                        onDraftChange(next);
                        onImmediateApply(next);
                    }}
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                >
                    <option value="">Toutes les livraisons</option>
                    <option value="yes">Livraisons pointées</option>
                    <option value="no">Livraisons non pointées</option>
                </select>
            </div>
        );
    }

    return (
        <input
            type="text"
            value={draft || ''}
            onChange={(event) => onDraftChange(event.target.value)}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.currentTarget.form?.requestSubmit();
                }
            }}
            placeholder="Rechercher..."
            className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
        />
    );
}

function ColumnHeader({
    column,
    sort,
    filters,
    openFilterKey,
    onOpenFilter,
    siteOptions,
    productTypeOptions,
    onSort,
    onFilterChange,
    onClearFilter,
}) {
    const filterOpen = openFilterKey === column.key;
    const [draft, setDraft] = useState(cloneFilters(filters)[column.key]);
    const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0 });
    const [renderFilterMenu, setRenderFilterMenu] = useState(filterOpen);
    const [isFilterClosing, setIsFilterClosing] = useState(false);
    const buttonRef = useRef(null);
    const menuRef = useRef(null);
    const liveFilterReadyRef = useRef(false);
    const isFiltered = hasColumnFilter(filters, column.key);
    const isSorted = sort.key === column.key && sort.direction;
    const isLiveTextFilter = ['client', 'phone', 'delivery_city', 'comment'].includes(column.key);
    const isInfoFilter = column.key === 'info';

    useEffect(() => {
        if (filterOpen) {
            const nextDraft = cloneFilters(filters)[column.key];
            setDraft(column.key === 'delivery_date' ? { ...nextDraft, mode: nextDraft.mode || 'exact' } : nextDraft);
            liveFilterReadyRef.current = false;
        }
    }, [filterOpen, column.key]);

    useEffect(() => {
        if (filterOpen) {
            setRenderFilterMenu(true);
            setIsFilterClosing(false);
            return undefined;
        }

        if (!renderFilterMenu) {
            return undefined;
        }

        setIsFilterClosing(true);
        const timeoutId = window.setTimeout(() => {
            setRenderFilterMenu(false);
            setIsFilterClosing(false);
        }, 120);

        return () => window.clearTimeout(timeoutId);
    }, [filterOpen, renderFilterMenu]);

    const updateMenuPosition = () => {
        const button = buttonRef.current;
        if (!button) return;

        const rect = button.getBoundingClientRect();
        const width = 288;
        const gap = 8;
        const measuredHeight = menuRef.current?.offsetHeight || 240;
        const belowTop = rect.bottom + gap;
        const aboveTop = rect.top - measuredHeight - gap;
        const top = belowTop + measuredHeight > window.innerHeight && aboveTop > 8
            ? aboveTop
            : Math.min(belowTop, Math.max(8, window.innerHeight - measuredHeight - 8));
        const left = Math.min(Math.max(8, rect.left - 8), Math.max(8, window.innerWidth - width - 8));

        setMenuPosition({ top, left });
    };

    useLayoutEffect(() => {
        if (!filterOpen) return undefined;

        updateMenuPosition();
        const handleUpdate = () => updateMenuPosition();
        window.addEventListener('resize', handleUpdate);
        window.addEventListener('scroll', handleUpdate, true);

        return () => {
            window.removeEventListener('resize', handleUpdate);
            window.removeEventListener('scroll', handleUpdate, true);
        };
    }, [filterOpen]);

    useEffect(() => {
        if (!filterOpen) return undefined;

        const handlePointerDown = (event) => {
            if (menuRef.current?.contains(event.target) || buttonRef.current?.contains(event.target)) {
                return;
            }
            onOpenFilter(null);
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onOpenFilter(null);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [filterOpen, onOpenFilter]);

    useEffect(() => {
        if (!filterOpen || (!isLiveTextFilter && !isInfoFilter)) return undefined;

        if (!liveFilterReadyRef.current) {
            liveFilterReadyRef.current = true;
            return undefined;
        }

        const timeoutId = window.setTimeout(() => {
            onFilterChange(column.key, draft);
        }, 300);

        return () => window.clearTimeout(timeoutId);
    }, [draft, filterOpen, isLiveTextFilter, isInfoFilter, column.key, onFilterChange]);

    const applyFilter = (event) => {
        event.preventDefault();
        onFilterChange(column.key, draft);
        onOpenFilter(null);
    };

    const applyImmediateFilter = (value) => {
        onFilterChange(column.key, value);
        onOpenFilter(null);
    };

    const filterMenu = renderFilterMenu && typeof document !== 'undefined' ? createPortal(
        <form
            ref={menuRef}
            onSubmit={applyFilter}
            style={{ top: menuPosition.top, left: menuPosition.left }}
            className={`fuel-filter-menu fixed z-[9999] w-72 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 text-left normal-case tracking-normal shadow-xl ${isFilterClosing ? 'fuel-filter-menu--closing pointer-events-none' : ''}`}
        >
            <p className="mb-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                Filtrer {column.label}
            </p>
            <FuelFilterFields
                column={column}
                draft={draft}
                onDraftChange={setDraft}
                onImmediateApply={applyImmediateFilter}
                siteOptions={siteOptions}
                productTypeOptions={productTypeOptions}
            />
            <div className="mt-3 flex justify-between gap-2">
                <button
                    type="button"
                    onClick={() => {
                        onClearFilter(column.key);
                        onOpenFilter(null);
                    }}
                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                >
                    Effacer
                </button>
                <button
                    type="submit"
                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-[0.08em]"
                >
                    Appliquer
                </button>
            </div>
        </form>,
        document.body
    ) : null;

    return (
        <th className={`relative px-3 py-2 text-center align-middle ${column.minWidth || ''}`}>
            <div className="flex items-center justify-center gap-2">
                <button
                    type="button"
                    onClick={() => onSort(column.key)}
                    title={`Trier ${column.label}`}
                    className={`inline-flex items-center gap-1 transition hover:text-[var(--app-text)] ${isSorted ? 'text-[var(--app-text)]' : ''}`}
                >
                    <span>{column.label}</span>
                    <span className="inline-flex h-5 w-4 flex-shrink-0 flex-col items-center justify-center gap-0.5 text-[var(--app-muted)]">
                        <ChevronUp
                            className={`h-3.5 w-3.5 ${sort.key === column.key && sort.direction === 'asc' ? 'text-[var(--brand-yellow-dark)]' : ''}`}
                            strokeWidth={2.4}
                        />
                        <ChevronDown
                            className={`h-3.5 w-3.5 ${sort.key === column.key && sort.direction === 'desc' ? 'text-[var(--brand-yellow-dark)]' : ''}`}
                            strokeWidth={2.4}
                        />
                    </span>
                </button>
                <button
                    ref={buttonRef}
                    type="button"
                    onClick={() => onOpenFilter(filterOpen ? null : column.key)}
                    title={`Filtrer ${column.label}`}
                    className={`inline-flex h-6 w-6 items-center justify-center rounded-md border ${
                        filterOpen || isFiltered
                            ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                            : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]'
                    }`}
                >
                    <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                </button>
            </div>
            {filterMenu}
        </th>
    );
}

function FuelCountFooter({ meta }) {
    const total = meta.total || 0;

    return (
        <div className="border-t border-[var(--app-border)] px-3 py-3 text-sm text-[var(--app-muted)]">
            {total} livraison{total > 1 ? 's' : ''} affichée{total > 1 ? 's' : ''}
        </div>
    );
}

function FuelFloatingActions({ visible, canUpdate, search, onSearchChange, onAdd, showStats, onToggleStats }) {
    const [searchOpen, setSearchOpen] = useState(false);
    const wrapperRef = useRef(null);
    const inputRef = useRef(null);

    useEffect(() => {
        if (!searchOpen) return undefined;

        inputRef.current?.focus();

        const handlePointerDown = (event) => {
            if (wrapperRef.current?.contains(event.target)) {
                return;
            }
            setSearchOpen(false);
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setSearchOpen(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [searchOpen]);

    if (!visible) {
        return null;
    }

    return (
        <div className="fixed bottom-24 right-3 z-40 flex items-end gap-2 md:bottom-6 md:right-6">
            <button
                type="button"
                onClick={onToggleStats}
                className={`inline-flex h-12 w-12 items-center justify-center rounded-full border shadow-xl transition ${
                    showStats
                        ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                        : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]'
                }`}
                aria-label={showStats ? 'Masquer les statistiques' : 'Statistiques'}
            >
                <BarChart2 className="h-5 w-5" strokeWidth={2.3} />
            </button>

            <div ref={wrapperRef} className="flex items-center justify-end">
                <div
                    className={`overflow-hidden transition-all duration-200 ease-out ${
                        searchOpen ? 'w-[min(22rem,calc(100vw-5.5rem))] opacity-100' : 'w-0 opacity-0'
                    }`}
                >
                    <div className="relative overflow-hidden rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl transition focus-within:border-[var(--brand-yellow-dark)]">
                        <Search className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-[#0F6930]" strokeWidth={2.1} />
                        <input
                            ref={inputRef}
                            type="text"
                            value={search}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Rechercher..."
                            className="h-12 w-full bg-transparent py-2 pl-12 pr-10 text-sm font-medium outline-none placeholder:text-[var(--app-muted)]"
                        />
                        {search ? (
                            <button
                                type="button"
                                onClick={() => onSearchChange('')}
                                className="absolute right-2.5 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-[var(--app-muted)] transition hover:bg-[var(--app-surface-soft)] hover:text-[var(--app-text)]"
                                aria-label="Effacer la recherche"
                            >
                                <X className="h-4 w-4" strokeWidth={2.2} />
                            </button>
                        ) : null}
                    </div>
                </div>
                {!searchOpen ? (
                    <button
                        type="button"
                        onClick={() => setSearchOpen(true)}
                        className="inline-flex h-12 w-12 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] shadow-xl transition hover:border-[var(--brand-yellow-dark)]"
                        aria-label="Rechercher"
                    >
                        <Search className="h-5 w-5" strokeWidth={2.3} />
                    </button>
                ) : null}
            </div>

            {canUpdate ? (
                <button
                    type="button"
                    onClick={onAdd}
                    className="inline-flex h-12 w-12 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)] shadow-xl transition hover:brightness-95"
                    aria-label="Ajouter"
                >
                    <Plus className="h-5 w-5" strokeWidth={2.4} />
                </button>
            ) : null}
        </div>
    );
}

const EMPTY_RECURRING_FORM = {
    client_name: '',
    code_tiers: '',
    phone: '',
    address: '',
    postal_code: '',
    city: '',
    site: '',
    fuel_type: '',
    volume_liters: '',
    urgent: false,
    comment: '',
    first_delivery_date: '',
    recurrence_type: 'weekly',
    recurrence_config: { interval: 1, days: [] },
    days_before: 0,
    active: true,
};

const WEEKDAY_LABELS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

function recurringFormLabel(recurrenceType, config) {
    const interval = Math.max(1, Number(config?.interval) || 1);
    if (recurrenceType === 'daily') return interval === 1 ? 'Tous les jours' : `Tous les ${interval} jours`;
    if (recurrenceType === 'weekly') return interval === 1 ? 'Toutes les semaines' : `Toutes les ${interval} semaines`;
    if (recurrenceType === 'weekdays') {
        const days = (config?.days || []).slice().sort();
        return days.length === 0 ? 'Aucun jour' : days.map((d) => WEEKDAY_LABELS[d]).join(', ');
    }
    if (recurrenceType === 'monthly') return interval === 1 ? 'Tous les mois' : `Tous les ${interval} mois`;
    return recurrenceType;
}

function FuelRecurringModal({ show, onClose, recurrings = [], canUpdate, canDelete, options, onRecurringsChange }) {
    const [view, setView] = useState('list');
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState({ ...EMPTY_RECURRING_FORM });
    const [error, setError] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [copiedCodeId, setCopiedCodeId] = useState(null);
    const copiedTimerRef = useRef(null);

    useEffect(() => () => {
        if (copiedTimerRef.current) window.clearTimeout(copiedTimerRef.current);
    }, []);

    const copyRecurringCode = async (r) => {
        if (!r?.code_tiers) return;
        try {
            await copyTextToClipboard(r.code_tiers);
        } catch {
            return;
        }
        setCopiedCodeId(r.id);
        if (copiedTimerRef.current) window.clearTimeout(copiedTimerRef.current);
        copiedTimerRef.current = window.setTimeout(() => {
            setCopiedCodeId((current) => (current === r.id ? null : current));
            copiedTimerRef.current = null;
        }, 2000);
    };

    const resetToList = () => {
        setView('list');
        setEditingId(null);
        setError('');
        setIsSaving(false);
    };

    const openForm = (recurring = null) => {
        if (recurring) {
            setEditingId(recurring.id);
            setForm({
                client_name: recurring.client_name || '',
                code_tiers: recurring.code_tiers || '',
                phone: recurring.phone || '',
                address: recurring.address || '',
                postal_code: recurring.postal_code || '',
                city: recurring.city || '',
                site: recurring.site || '',
                fuel_type: recurring.fuel_type || '',
                volume_liters: recurring.volume_liters ?? '',
                urgent: Boolean(recurring.urgent),
                comment: recurring.comment || '',
                first_delivery_date: recurring.first_delivery_date || '',
                recurrence_type: recurring.recurrence_type || 'weekly',
                recurrence_config: recurring.recurrence_config || { interval: 1, days: [] },
                days_before: recurring.days_before ?? 0,
                active: recurring.active !== false,
            });
        } else {
            setEditingId(null);
            setForm({ ...EMPTY_RECURRING_FORM });
        }
        setError('');
        setView('form');
    };

    const updateField = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const updateConfig = (key, value) => setForm((f) => ({ ...f, recurrence_config: { ...f.recurrence_config, [key]: value } }));
    const toggleWeekday = (day) => {
        const days = (form.recurrence_config?.days || []);
        const next = days.includes(day) ? days.filter((d) => d !== day) : [...days, day];
        updateConfig('days', next);
    };

    const submit = async () => {
        if (!form.first_delivery_date) {
            setError('La date de première livraison est requise.');
            return;
        }
        if (form.recurrence_type === 'weekdays' && (form.recurrence_config?.days || []).length === 0) {
            setError('Sélectionnez au moins un jour de la semaine.');
            return;
        }
        setIsSaving(true);
        setError('');
        try {
            const payload = {
                client_name: form.client_name.trim() || null,
                code_tiers: form.code_tiers.trim() || null,
                phone: form.phone.trim() || null,
                address: form.address.trim() || null,
                postal_code: form.postal_code.trim() || null,
                city: form.city.trim() || null,
                site: form.site || null,
                fuel_type: form.fuel_type || null,
                volume_liters: form.volume_liters === '' ? null : Number(form.volume_liters),
                urgent: Boolean(form.urgent),
                comment: form.comment.trim() || null,
                first_delivery_date: form.first_delivery_date,
                recurrence_type: form.recurrence_type,
                recurrence_config: form.recurrence_config,
                days_before: Number(form.days_before) || 0,
                active: Boolean(form.active),
            };
            let data;
            if (editingId) {
                data = await jsonRequest(`/tasks/fuel/recurrings/${editingId}`, { method: 'PUT', body: JSON.stringify(payload) });
                onRecurringsChange((prev) => prev.map((r) => r.id === editingId ? data.recurring : r));
            } else {
                data = await jsonRequest('/tasks/fuel/recurrings', { method: 'POST', body: JSON.stringify(payload) });
                onRecurringsChange((prev) => [...prev, data.recurring]);
            }
            window.dispatchEvent(new CustomEvent('app:toast', { detail: { type: 'success', message: data.message || 'Récurrence enregistrée.' } }));
            router.reload({ only: ['deliveries'] });
            resetToList();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Impossible d\'enregistrer la récurrence.');
        } finally {
            setIsSaving(false);
        }
    };

    const toggleActive = async (recurring) => {
        try {
            const data = await jsonRequest(`/tasks/fuel/recurrings/${recurring.id}`, {
                method: 'PUT',
                body: JSON.stringify({ ...recurring, active: !recurring.active }),
            });
            onRecurringsChange((prev) => prev.map((r) => r.id === recurring.id ? data.recurring : r));
            router.reload({ only: ['deliveries'] });
        } catch (err) {
            window.dispatchEvent(new CustomEvent('app:toast', { detail: { type: 'error', message: err instanceof Error ? err.message : 'Erreur.' } }));
        }
    };

    const deleteRecurring = async () => {
        if (!confirmDelete) return;
        setIsDeleting(true);
        try {
            await jsonRequest(`/tasks/fuel/recurrings/${confirmDelete.id}`, { method: 'DELETE' });
            onRecurringsChange((prev) => prev.filter((r) => r.id !== confirmDelete.id));
            window.dispatchEvent(new CustomEvent('app:toast', { detail: { type: 'success', message: 'Récurrence supprimée.' } }));
            setConfirmDelete(null);
        } catch (err) {
            window.dispatchEvent(new CustomEvent('app:toast', { detail: { type: 'error', message: err instanceof Error ? err.message : 'Erreur.' } }));
        } finally {
            setIsDeleting(false);
        }
    };

    const handleClose = () => { resetToList(); onClose(); };

    return (
        <Modal show={show} onClose={handleClose} maxWidth="5xl">
            {/* Header */}
            <div className="flex items-start justify-between gap-4 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <div className="flex items-center gap-2">
                    {view === 'form' ? (
                        <button
                            type="button"
                            onClick={resetToList}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)] hover:text-[var(--app-text)]"
                        >
                            <ChevronDown className="h-4 w-4 -rotate-90" strokeWidth={2.2} />
                        </button>
                    ) : null}
                    <h2 className="text-base font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        {view === 'form' ? (editingId ? 'Modifier une récurrence' : 'Nouvelle récurrence') : 'Livraisons récurrentes'}
                    </h2>
                </div>
                <div className="flex items-center gap-2">
                    {view === 'list' && canUpdate ? (
                        <button
                            type="button"
                            onClick={() => openForm(null)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-1.5 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                        >
                            <Plus className="h-3.5 w-3.5" strokeWidth={2.4} />
                            Ajouter
                        </button>
                    ) : null}
                    <button
                        type="button"
                        onClick={handleClose}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                        aria-label="Fermer"
                    >
                        <X className="h-4 w-4" strokeWidth={2.2} />
                    </button>
                </div>
            </div>

            {/* List view */}
            {view === 'list' ? (
                <div className="max-h-[calc(100vh-10rem)] overflow-y-auto bg-[var(--app-surface)] px-5 py-5">
                    {recurrings.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-[var(--app-border)] p-10 text-center text-sm text-[var(--app-muted)]">
                            Aucune livraison récurrente. Cliquez sur <strong>Ajouter</strong> pour en créer une.
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {recurrings.map((r) => (
                                <div
                                    key={r.id}
                                    className={`rounded-2xl border p-4 ${r.active ? 'border-[var(--app-border)] bg-[var(--app-surface-soft)]' : 'border-[var(--app-border)] bg-[var(--app-surface)] opacity-60'}`}
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-black text-[var(--app-text)]">
                                                    {r.client_name || '—'}
                                                </span>
                                                {r.code_tiers ? (
                                                    <span className="relative inline-flex items-center">
                                                        <button
                                                            type="button"
                                                            onClick={() => copyRecurringCode(r)}
                                                            title="Copier le code tiers"
                                                            className="rounded-md border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-0.5 text-xs font-bold text-[var(--app-muted)] transition hover:border-[var(--brand-yellow-dark)] hover:text-[var(--app-text)]"
                                                        >
                                                            {r.code_tiers}
                                                        </button>
                                                        <span
                                                            className={`pointer-events-none absolute left-full top-1/2 ml-2 -translate-y-1/2 whitespace-nowrap text-xs font-bold text-emerald-600 transition-opacity duration-200 ${copiedCodeId === r.id ? 'opacity-100' : 'opacity-0'}`}
                                                        >
                                                            ✓ Copié
                                                        </span>
                                                    </span>
                                                ) : null}
                                                {r.urgent ? (
                                                    <span className="inline-flex items-center gap-1 rounded-full border border-amber-400 bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-700">
                                                        <AlertTriangle className="h-3 w-3" strokeWidth={2.2} />
                                                        Urgent
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-[var(--app-muted)]">
                                                {r.site ? <span><span className="font-semibold text-[var(--app-text)]">Site</span> {r.site}</span> : null}
                                                {r.fuel_type ? <span><span className="font-semibold text-[var(--app-text)]">{r.fuel_type}</span>{r.volume_liters != null ? ` · ${r.volume_liters} L` : ''}</span> : null}
                                                <span className="inline-flex items-center gap-1">
                                                    <RefreshCw className="h-3 w-3 text-sky-500" strokeWidth={2.2} />
                                                    {r.recurrence_label}
                                                </span>
                                                {r.next_occurrence_label ? (
                                                    <span>Prochaine : <span className="font-semibold text-[var(--app-text)]">{r.next_occurrence_label}</span></span>
                                                ) : null}
                                                {r.days_before > 0 ? (
                                                    <span>{r.days_before} j. avant</span>
                                                ) : null}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {canUpdate ? (
                                                <button
                                                    type="button"
                                                    onClick={() => toggleActive(r)}
                                                    className={`rounded-xl border px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] transition ${r.active ? 'border-emerald-500 bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)] hover:border-[var(--brand-yellow-dark)]'}`}
                                                >
                                                    {r.active ? 'Actif' : 'Inactif'}
                                                </button>
                                            ) : null}
                                            {canUpdate ? (
                                                <button
                                                    type="button"
                                                    onClick={() => openForm(r)}
                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]"
                                                    title="Modifier"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                </button>
                                            ) : null}
                                            {canDelete ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setConfirmDelete(r)}
                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-red-500 bg-red-500 text-white"
                                                    title="Supprimer"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                </button>
                                            ) : null}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            ) : null}

            {/* Form view */}
            {view === 'form' ? (
                <div className="max-h-[calc(100vh-10rem)] space-y-5 overflow-y-auto bg-[var(--app-surface)] px-5 py-5">
                    <FuelTiersSearchBar
                        show={show && view === 'form'}
                        onSelect={(tiers) => {
                            updateField('code_tiers', tiers.code || '');
                            updateField('client_name', tiers.name || '');
                            updateField('phone', tiers.phone || '');
                            updateField('address', tiers.address || '');
                            updateField('postal_code', tiers.postal_code || '');
                            updateField('city', tiers.city || '');
                        }}
                    />
                    {/* Client fields */}
                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                        <h3 className="mb-3 text-sm font-black uppercase tracking-[0.08em]">Client</h3>
                        <div className="grid gap-3 text-sm lg:grid-cols-4">
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Code Tiers</label>
                                <input type="text" value={form.code_tiers} onChange={(e) => updateField('code_tiers', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm" />
                            </div>
                            <div className="lg:col-span-2">
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Nom / Raison sociale</label>
                                <input type="text" value={form.client_name} onChange={(e) => updateField('client_name', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Téléphone</label>
                                <input type="text" value={form.phone} onChange={(e) => updateField('phone', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div className="mt-3 grid gap-3 border-t border-[var(--app-border)] pt-3 text-sm lg:grid-cols-[2fr_12rem_1fr]">
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Adresse</label>
                                <input type="text" value={form.address} onChange={(e) => updateField('address', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Code postal</label>
                                <input type="text" value={form.postal_code} onChange={(e) => updateField('postal_code', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Commune</label>
                                <input type="text" value={form.city} onChange={(e) => updateField('city', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm" />
                            </div>
                        </div>
                    </section>

                    {/* Delivery fields */}
                    <section className="grid gap-3 text-sm md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_12rem_auto]">
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Site</label>
                            <select value={form.site} onChange={(e) => updateField('site', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                                <option value="">— Aucun site —</option>
                                {(options.sites || []).filter((o) => o.active).map((o) => (
                                    <option key={o.id} value={o.label}>{o.label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Type</label>
                            <select value={form.fuel_type} onChange={(e) => updateField('fuel_type', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                                <option value="">Type de produit</option>
                                {(options.product_types || []).filter((o) => o.active).map((o) => (
                                    <option key={o.id} value={o.label}>{o.label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Volume</label>
                            <div className="relative mt-1">
                                <input type="number" min="0" step="1" value={form.volume_liters} onChange={(e) => updateField('volume_liters', e.target.value)} className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pr-8 text-sm" />
                                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-[var(--app-muted)]">L</span>
                            </div>
                        </div>
                        <label className="inline-flex h-[38px] items-center justify-center gap-2 self-end rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-sm font-semibold">
                            <input type="checkbox" checked={form.urgent} onChange={(e) => updateField('urgent', e.target.checked)} className="rounded border-[var(--app-border)]" />
                            Urgent
                        </label>
                    </section>

                    <section>
                        <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Commentaire</label>
                        <textarea value={form.comment} onChange={(e) => updateField('comment', e.target.value)} rows={3} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm" />
                    </section>

                    {/* Recurrence section */}
                    <section className="rounded-2xl border border-sky-200 bg-sky-50/40 p-4">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-black uppercase tracking-[0.08em]">
                            <RefreshCw className="h-4 w-4 text-sky-500" strokeWidth={2.2} />
                            Récurrence
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Première livraison</label>
                                <input type="date" value={form.first_delivery_date} onChange={(e) => updateField('first_delivery_date', e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Type de récurrence</label>
                                <select
                                    value={form.recurrence_type}
                                    onChange={(e) => {
                                        updateField('recurrence_type', e.target.value);
                                        updateConfig('interval', 1);
                                        updateConfig('days', []);
                                    }}
                                    className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                                >
                                    <option value="daily">Tous les X jours</option>
                                    <option value="weekly">Toutes les X semaines</option>
                                    <option value="weekdays">Certains jours de la semaine</option>
                                    <option value="monthly">Tous les X mois</option>
                                </select>
                            </div>

                            {['daily', 'weekly', 'monthly'].includes(form.recurrence_type) ? (
                                <div>
                                    <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        {form.recurrence_type === 'daily' ? 'Intervalle (jours)' : form.recurrence_type === 'weekly' ? 'Intervalle (semaines)' : 'Intervalle (mois)'}
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="365"
                                        value={form.recurrence_config?.interval ?? 1}
                                        onChange={(e) => updateConfig('interval', Math.max(1, parseInt(e.target.value, 10) || 1))}
                                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                                    />
                                    <p className="mt-1 text-xs text-[var(--app-muted)]">
                                        {recurringFormLabel(form.recurrence_type, form.recurrence_config)}
                                    </p>
                                </div>
                            ) : null}

                            {form.recurrence_type === 'weekdays' ? (
                                <div>
                                    <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Jours de la semaine</label>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {WEEKDAY_LABELS.map((label, i) => {
                                            const active = (form.recurrence_config?.days || []).includes(i);
                                            return (
                                                <button
                                                    key={i}
                                                    type="button"
                                                    onClick={() => toggleWeekday(i)}
                                                    className={`rounded-lg border px-3 py-1.5 text-xs font-bold transition ${active ? 'border-sky-500 bg-sky-500 text-white' : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)] hover:border-sky-300'}`}
                                                >
                                                    {label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ) : null}

                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Créer X jours avant la livraison</label>
                                <input
                                    type="number"
                                    min="0"
                                    max="365"
                                    value={form.days_before}
                                    onChange={(e) => updateField('days_before', Math.max(0, parseInt(e.target.value, 10) || 0))}
                                    className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                                />
                                {form.days_before > 0 ? (
                                    <p className="mt-1 text-xs text-[var(--app-muted)]">La ligne apparaît {form.days_before} jour{form.days_before > 1 ? 's' : ''} avant la date de livraison.</p>
                                ) : (
                                    <p className="mt-1 text-xs text-[var(--app-muted)]">La ligne apparaît le jour de la livraison.</p>
                                )}
                            </div>

                            <div className="sm:col-span-2">
                                <label className="inline-flex items-center gap-2 text-sm font-semibold">
                                    <input type="checkbox" checked={form.active} onChange={(e) => updateField('active', e.target.checked)} className="rounded border-[var(--app-border)]" />
                                    Récurrence active
                                </label>
                            </div>
                        </div>
                    </section>

                    {error ? (
                        <div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{error}</div>
                    ) : null}
                </div>
            ) : null}

            {/* Footer */}
            {view === 'form' ? (
                <div className="flex flex-col-reverse gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4 sm:flex-row sm:justify-end">
                    <button type="button" onClick={resetToList} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em]">
                        Annuler
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={isSaving}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {isSaving ? 'Enregistrement...' : 'Enregistrer'}
                    </button>
                </div>
            ) : null}

            {/* Delete confirmation */}
            {confirmDelete ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="mx-4 w-full max-w-sm rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-2xl">
                        <h3 className="text-base font-black uppercase tracking-[0.06em]">Supprimer cette récurrence&nbsp;?</h3>
                        <p className="mt-2 text-sm text-[var(--app-muted)]">
                            Les livraisons déjà générées ne seront pas supprimées automatiquement.
                        </p>
                        <div className="mt-5 flex justify-end gap-3">
                            <button type="button" onClick={() => setConfirmDelete(null)} disabled={isDeleting} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] disabled:opacity-50">
                                Annuler
                            </button>
                            <button type="button" onClick={deleteRecurring} disabled={isDeleting} className="rounded-xl border border-red-600 bg-red-600 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-50">
                                {isDeleting ? 'Suppression...' : 'Supprimer'}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </Modal>
    );
}

function FuelSitesPanel({ items = [], depots = [], onAdd, onUpdate, onDelete }) {
    const [selectedDepotId, setSelectedDepotId] = useState('');

    const existingDepotIds = useMemo(() => new Set(items.map((item) => item.depot_id).filter(Boolean)), [items]);
    const availableDepots = useMemo(() => depots.filter((depot) => !existingDepotIds.has(depot.id)), [depots, existingDepotIds]);

    const add = async () => {
        if (!selectedDepotId) return;
        await onAdd(Number(selectedDepotId));
        setSelectedDepotId('');
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
            <div className="mb-3">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">Sites</h3>
            </div>

            <div className="flex flex-col gap-2 sm:flex-row">
                <select
                    value={selectedDepotId}
                    onChange={(event) => setSelectedDepotId(event.target.value)}
                    className="min-w-0 flex-1 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                >
                    <option value="">Sélectionner un dépôt</option>
                    {availableDepots.map((depot) => (
                        <option key={depot.id} value={depot.id}>{depot.name}</option>
                    ))}
                </select>
                <button
                    type="button"
                    onClick={add}
                    disabled={!selectedDepotId}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                    Ajouter
                </button>
            </div>

            <div className="mt-4 space-y-2">
                {items.length === 0 ? (
                    <p className="text-sm text-[var(--app-muted)]">Aucun site. Sélectionnez un dépôt ci-dessus.</p>
                ) : (
                    items.map((item) => (
                        <div key={item.id} className="grid gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 sm:grid-cols-[1fr_auto_auto] sm:items-center">
                            <span className="px-3 py-2 text-sm font-medium text-[var(--app-text)]">
                                {item.depot_label || item.label}
                            </span>
                            <button
                                type="button"
                                onClick={() => onUpdate(item.id, { active: !item.active })}
                                className={`rounded-lg border px-3 py-2 text-xs font-black uppercase tracking-[0.08em] ${
                                    item.active
                                        ? 'border-emerald-600 bg-emerald-50 text-emerald-700'
                                        : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)]'
                                }`}
                            >
                                {item.active ? 'Actif' : 'Inactif'}
                            </button>
                            <button
                                type="button"
                                onClick={() => onDelete(item.id)}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-500 bg-red-500 text-white"
                                aria-label="Supprimer"
                            >
                                <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                            </button>
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}

function FuelOptionsPanel({ title, kind, items = [], onCreate, onUpdate, onDelete }) {
    const [newLabel, setNewLabel] = useState('');
    const [editingValues, setEditingValues] = useState({});

    useEffect(() => {
        setEditingValues(Object.fromEntries(items.map((item) => [item.id, item.label || ''])));
    }, [items]);

    const add = async () => {
        const label = newLabel.trim();
        if (!label) return;
        await onCreate(kind, label);
        setNewLabel('');
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">{title}</h3>
            </div>

            <div className="flex flex-col gap-2 sm:flex-row">
                <input
                    type="text"
                    value={newLabel}
                    onChange={(event) => setNewLabel(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            add();
                        }
                    }}
                    placeholder="Nouvelle valeur"
                    className="min-w-0 flex-1 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                />
                <button
                    type="button"
                    onClick={add}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                >
                    <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                    Ajouter
                </button>
            </div>

            <div className="mt-4 space-y-2">
                {items.length === 0 ? (
                    <p className="text-sm text-[var(--app-muted)]">Aucune valeur.</p>
                ) : (
                    items.map((item) => (
                        <div key={item.id} className="grid gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 sm:grid-cols-[1fr_auto_auto] sm:items-center">
                            <input
                                type="text"
                                value={editingValues[item.id] ?? ''}
                                onChange={(event) => setEditingValues((current) => ({ ...current, [item.id]: event.target.value }))}
                                onBlur={() => {
                                    const label = String(editingValues[item.id] ?? '').trim();
                                    if (label && label !== item.label) {
                                        onUpdate(item.id, { label, active: item.active });
                                    }
                                }}
                                className="min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            />
                            <button
                                type="button"
                                onClick={() => onUpdate(item.id, { label: String(editingValues[item.id] ?? item.label).trim() || item.label, active: !item.active })}
                                className={`rounded-lg border px-3 py-2 text-xs font-black uppercase tracking-[0.08em] ${
                                    item.active
                                        ? 'border-emerald-600 bg-emerald-50 text-emerald-700'
                                        : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)]'
                                }`}
                            >
                                {item.active ? 'Actif' : 'Inactif'}
                            </button>
                            <button
                                type="button"
                                onClick={() => onDelete(item.id)}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-500 bg-red-500 text-white"
                                aria-label="Supprimer"
                            >
                                <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                            </button>
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}

function FuelSettingsModal({ show, onClose, options, onOptionsChange, depots = [] }) {
    const [error, setError] = useState('');

    const replaceOption = (option) => {
        onOptionsChange((current) => {
            const groupKey = option.kind === 'site' ? 'sites' : 'product_types';
            return {
                ...current,
                [groupKey]: (current[groupKey] || []).map((item) => (item.id === option.id ? option : item)),
            };
        });
    };

    const addSiteDepot = async (depotId) => {
        setError('');
        try {
            const data = await jsonRequest('/tasks/fuel/options', {
                method: 'POST',
                body: JSON.stringify({ kind: 'site', depot_id: depotId }),
            });
            onOptionsChange((current) => ({
                ...current,
                sites: [...(current.sites || []), data.option],
            }));
        } catch (caughtError) {
            setError(caughtError instanceof Error ? caughtError.message : 'Impossible d\'ajouter le site.');
        }
    };

    const createOption = async (kind, label) => {
        setError('');
        try {
            const data = await jsonRequest('/tasks/fuel/options', {
                method: 'POST',
                body: JSON.stringify({ kind, label }),
            });
            const groupKey = kind === 'site' ? 'sites' : 'product_types';
            onOptionsChange((current) => ({
                ...current,
                [groupKey]: [...(current[groupKey] || []), data.option],
            }));
        } catch (caughtError) {
            setError(caughtError instanceof Error ? caughtError.message : 'Impossible d\'ajouter la valeur.');
        }
    };

    const updateOption = async (id, payload) => {
        setError('');
        try {
            const data = await jsonRequest(`/tasks/fuel/options/${id}`, {
                method: 'PUT',
                body: JSON.stringify(payload),
            });
            replaceOption(data.option);
        } catch (caughtError) {
            setError(caughtError instanceof Error ? caughtError.message : 'Impossible de modifier la valeur.');
        }
    };

    const deleteOption = async (id) => {
        setError('');
        try {
            await jsonRequest(`/tasks/fuel/options/${id}`, { method: 'DELETE' });
            onOptionsChange((current) => ({
                sites: (current.sites || []).filter((item) => item.id !== id),
                product_types: (current.product_types || []).filter((item) => item.id !== id),
            }));
        } catch (caughtError) {
            setError(caughtError instanceof Error ? caughtError.message : 'Impossible de supprimer la valeur.');
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl">
            <div className="flex items-start justify-between gap-4 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h2 className="text-base font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                    Paramètres Carburant
                </h2>
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                    aria-label="Fermer"
                >
                    <X className="h-4 w-4" strokeWidth={2.2} />
                </button>
            </div>

            <div className="max-h-[calc(100vh-10rem)] space-y-4 overflow-y-auto bg-[var(--app-surface)] px-5 py-5">
                {error ? (
                    <div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                        {error}
                    </div>
                ) : null}

                <FuelSitesPanel
                    items={options.sites || []}
                    depots={depots}
                    onAdd={addSiteDepot}
                    onUpdate={updateOption}
                    onDelete={deleteOption}
                />

                <FuelOptionsPanel
                    title="Types de produit"
                    kind="product_type"
                    items={options.product_types || []}
                    onCreate={createOption}
                    onUpdate={updateOption}
                    onDelete={deleteOption}
                />
            </div>
        </Modal>
    );
}

function FuelTiersSearchBar({ show, onSelect, autoFocus = false }) {
    const [tiersQuery, setTiersQuery] = useState('');
    const [tiersResults, setTiersResults] = useState([]);
    const [isSearching, setIsSearching] = useState(false);
    const [searchError, setSearchError] = useState('');

    useEffect(() => {
        if (!show) {
            setTiersQuery('');
            setTiersResults([]);
            setIsSearching(false);
            setSearchError('');
        }
    }, [show]);

    useEffect(() => {
        if (!show) return undefined;

        const trimmed = tiersQuery.trim();
        if (trimmed.length < 2) {
            setTiersResults([]);
            setIsSearching(false);
            setSearchError('');
            return undefined;
        }

        const controller = new AbortController();
        const timeoutId = window.setTimeout(async () => {
            try {
                setIsSearching(true);
                setSearchError('');

                const response = await fetch(`/tasks/fuel/tiers-search?q=${encodeURIComponent(trimmed)}`, {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const payload = await response.json();
                setTiersResults(Array.isArray(payload) ? payload : []);
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') return;
                setTiersResults([]);
                setSearchError('Impossible de rechercher dans les tiers.');
            } finally {
                setIsSearching(false);
            }
        }, 300);

        return () => {
            window.clearTimeout(timeoutId);
            controller.abort();
        };
    }, [show, tiersQuery]);

    const handleSelect = (tiers) => {
        onSelect(tiers);
        setTiersQuery([tiers.code, tiers.name].filter(Boolean).join(' - '));
        setTiersResults([]);
    };

    return (
        <section>
            <label className="block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                Client / tiers
            </label>
            <div className="relative mt-2">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                <input
                    type="text"
                    value={tiersQuery}
                    onChange={(event) => setTiersQuery(event.target.value)}
                    placeholder="Code tiers, nom, adresse, code postal, commune, téléphone..."
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2.5 pl-9 pr-3 text-sm"
                    autoFocus={autoFocus}
                />
            </div>
            {isSearching ? (
                <p className="mt-2 text-sm text-[var(--app-muted)]">Recherche en cours...</p>
            ) : null}
            {searchError ? (
                <p className="mt-2 text-sm text-red-600">{searchError}</p>
            ) : null}
            {tiersResults.length > 0 ? (
                <div className="mt-3 max-h-72 overflow-y-auto rounded-xl border border-[var(--app-border)]">
                    {tiersResults.map((tiers) => (
                        <button
                            key={tiers.id}
                            type="button"
                            onClick={() => handleSelect(tiers)}
                            className="block w-full border-b border-[var(--app-border)] px-3 py-2.5 text-left last:border-b-0 hover:bg-[var(--app-surface-soft)]"
                        >
                            <span className="block text-sm font-bold text-[var(--app-text)]">
                                {[tiers.code, tiers.name].filter(Boolean).join(' - ') || `Tiers #${tiers.id}`}
                            </span>
                            <span className="mt-1 block text-xs text-[var(--app-muted)]">
                                {[tiers.address, tiers.postal_code, tiers.city, tiers.phone].filter(Boolean).join(' • ')}
                            </span>
                        </button>
                    ))}
                </div>
            ) : null}
        </section>
    );
}

function FuelDeliveryModal({ show, onClose, onCreateDeliveries, onCreateNewClient, canCreateNewClient = false, options }) {
    const [selectedTiers, setSelectedTiers] = useState(null);
    const [clientFields, setClientFields] = useState({ ...EMPTY_CLIENT_FIELDS });
    const [deliveryFields, setDeliveryFields] = useState({ ...EMPTY_DELIVERY_FIELDS });
    const [orders, setOrders] = useState([{ ...EMPTY_ORDER }]);
    const [comment, setComment] = useState('');
    const [submitError, setSubmitError] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [showCreateClientConfirm, setShowCreateClientConfirm] = useState(false);
    const [createClientError, setCreateClientError] = useState('');
    const [isCreatingClient, setIsCreatingClient] = useState(false);
    const [clientCreatedFeedback, setClientCreatedFeedback] = useState(false);
    const clientFeedbackTimerRef = useRef(null);

    useEffect(() => {
        if (show) {
            return;
        }

        setSelectedTiers(null);
        setClientFields({ ...EMPTY_CLIENT_FIELDS });
        setSubmitError('');
        setIsSubmitting(false);
        setShowCreateClientConfirm(false);
        setCreateClientError('');
        setIsCreatingClient(false);
        setClientCreatedFeedback(false);
        if (clientFeedbackTimerRef.current) {
            window.clearTimeout(clientFeedbackTimerRef.current);
            clientFeedbackTimerRef.current = null;
        }
        setDeliveryFields({ ...EMPTY_DELIVERY_FIELDS });
        setOrders([{ ...EMPTY_ORDER }]);
        setComment('');
    }, [show]);

    useEffect(() => () => {
        if (clientFeedbackTimerRef.current) {
            window.clearTimeout(clientFeedbackTimerRef.current);
        }
    }, []);

    const updateOrder = (index, key, value) => {
        setOrders((current) => current.map((order, orderIndex) => (
            orderIndex === index ? { ...order, [key]: value } : order
        )));
    };

    const removeOrder = (index) => {
        setOrders((current) => current.length > 1 ? current.filter((_, orderIndex) => orderIndex !== index) : current);
    };

    const updateClientField = (key, value) => {
        setClientFields((current) => ({ ...current, [key]: value }));
    };

    const selectTiers = (tiers) => {
        setSelectedTiers(tiers);
        setClientFields({
            code: tiers.code || '',
            name: tiers.name || '',
            phone: tiers.phone || '',
            address: tiers.address || '',
            postal_code: tiers.postal_code || '',
            city: tiers.city || '',
        });
    };

    const validOrders = orders.filter((order) => order.fuel_type || order.volume);
    const hasClientInfo = Object.values(clientFields).some((value) => String(value || '').trim() !== '');
    const canCreateManualClient = canCreateNewClient && !selectedTiers && hasClientInfo;
    const canSubmit = hasClientInfo && validOrders.length > 0;

    const createManualClient = async () => {
        setIsCreatingClient(true);
        setCreateClientError('');

        try {
            const createdTiers = await onCreateNewClient({
                code: clientFields.code.trim(),
                name: clientFields.name.trim(),
                phone: clientFields.phone.trim(),
                address: clientFields.address.trim(),
                postal_code: clientFields.postal_code.trim(),
                city: clientFields.city.trim(),
            });

            if (createdTiers) {
                setSelectedTiers(createdTiers);
                setTiersQuery([createdTiers.code, createdTiers.name].filter(Boolean).join(' - '));
            }

            setShowCreateClientConfirm(false);
            setClientCreatedFeedback(true);
            if (clientFeedbackTimerRef.current) {
                window.clearTimeout(clientFeedbackTimerRef.current);
            }
            clientFeedbackTimerRef.current = window.setTimeout(() => {
                setClientCreatedFeedback(false);
                clientFeedbackTimerRef.current = null;
            }, 2200);
        } catch (error) {
            setCreateClientError(error instanceof Error ? error.message : 'Impossible de créer ce client.');
        } finally {
            setIsCreatingClient(false);
        }
    };

    const submit = async () => {
        if (!canSubmit) {
            return;
        }

        setIsSubmitting(true);
        setSubmitError('');

        try {
            await onCreateDeliveries({
                tiers_record_id: selectedTiers?.id || null,
                site: deliveryFields.site,
                client: {
                    code: clientFields.code.trim(),
                    name: clientFields.name.trim(),
                    phone: clientFields.phone.trim(),
                    address: clientFields.address.trim(),
                    postal_code: clientFields.postal_code.trim(),
                    city: clientFields.city.trim(),
                },
                orders: validOrders.map((order) => ({
                    fuel_type: order.fuel_type || '',
                    volume: order.volume === '' ? null : Number(order.volume),
                    urgent: Boolean(order.urgent),
                })),
                comment: comment.trim(),
            });

            onClose();
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Impossible d\'ajouter la livraison carburant.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="5xl">
            <div className="flex items-start justify-between gap-4 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <div>
                    <h2 className="text-base font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        Ajouter une livraison carburant
                    </h2>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                    aria-label="Fermer"
                >
                    <X className="h-4 w-4" strokeWidth={2.2} />
                </button>
            </div>

            <div className="max-h-[calc(100vh-10rem)] space-y-5 overflow-y-auto bg-[var(--app-surface)] px-5 py-5">
                <FuelTiersSearchBar show={show} onSelect={selectTiers} autoFocus />

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                    <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">Informations client</h3>
                        <div className="relative min-h-5 text-right">
                            {selectedTiers ? (
                                <span className="text-xs font-semibold text-[var(--app-muted)]">
                                    Tiers sélectionné, champs modifiables
                                </span>
                            ) : null}
                            {canCreateManualClient ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setCreateClientError('');
                                        setShowCreateClientConfirm(true);
                                    }}
                                    className="text-xs font-bold text-emerald-700 underline decoration-dotted underline-offset-4 transition hover:text-emerald-800"
                                >
                                    Créer ce nouveau client
                                </button>
                            ) : null}
                            <span
                                className={`pointer-events-none absolute right-0 top-full mt-1 whitespace-nowrap text-xs font-bold text-emerald-600 transition-opacity duration-200 ${
                                    clientCreatedFeedback ? 'opacity-100' : 'opacity-0'
                                }`}
                            >
                                ✓ Client créé
                            </span>
                        </div>
                    </div>

                    <div className="grid gap-3 text-sm lg:grid-cols-4">
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Code Tiers
                            </label>
                            <input
                                type="text"
                                value={clientFields.code}
                                onChange={(event) => updateClientField('code', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="lg:col-span-2">
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Nom / Raison sociale
                            </label>
                            <input
                                type="text"
                                value={clientFields.name}
                                onChange={(event) => updateClientField('name', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Téléphone
                            </label>
                            <input
                                type="text"
                                value={clientFields.phone}
                                onChange={(event) => updateClientField('phone', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                    <div className="mt-3 grid gap-3 border-t border-[var(--app-border)] pt-3 text-sm lg:grid-cols-[2fr_12rem_1fr]">
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Adresse
                            </label>
                            <input
                                type="text"
                                value={clientFields.address}
                                onChange={(event) => updateClientField('address', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Code postal
                            </label>
                            <input
                                type="text"
                                value={clientFields.postal_code}
                                onChange={(event) => updateClientField('postal_code', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Commune
                            </label>
                            <input
                                type="text"
                                value={clientFields.city}
                                onChange={(event) => updateClientField('city', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                </section>

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">Commande</h3>
                        <button
                            type="button"
                            onClick={() => setOrders((current) => [...current, { ...EMPTY_ORDER }])}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]"
                            aria-label="Ajouter une commande"
                        >
                            <Plus className="h-4 w-4" strokeWidth={2.2} />
                        </button>
                    </div>

                    <div>
                        <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Site
                        </label>
                        <select
                            value={deliveryFields.site}
                            onChange={(event) => setDeliveryFields((current) => ({ ...current, site: event.target.value }))}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        >
                            <option value="">Site</option>
                            {(options.sites || []).filter((option) => option.active).map((option) => (
                                <option key={option.id} value={option.label}>{option.label}</option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-2">
                        {orders.map((order, index) => (
                            <div key={index} className="grid gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 lg:grid-cols-[1fr_11rem_auto_auto] lg:items-center">
                                <select
                                    value={order.fuel_type}
                                    onChange={(event) => updateOrder(index, 'fuel_type', event.target.value)}
                                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                                >
                                    <option value="">Type de produit</option>
                                    {(options.product_types || []).filter((option) => option.active).map((option) => (
                                        <option key={option.id} value={option.label}>{option.label}</option>
                                    ))}
                                </select>
                                <div className="relative">
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        value={order.volume}
                                        onChange={(event) => updateOrder(index, 'volume', event.target.value)}
                                        placeholder="Volume"
                                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 pr-8 text-sm"
                                    />
                                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-[var(--app-muted)]">L</span>
                                </div>
                                <label className="inline-flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={order.urgent}
                                        onChange={(event) => updateOrder(index, 'urgent', event.target.checked)}
                                        className="rounded border-[var(--app-border)]"
                                    />
                                    Urgent
                                </label>
                                <button
                                    type="button"
                                    onClick={() => removeOrder(index)}
                                    disabled={orders.length === 1}
                                    className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)] disabled:opacity-40"
                                    aria-label="Retirer cette commande"
                                >
                                    <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                                </button>
                            </div>
                        ))}
                    </div>
                </section>

                <section>
                    <label className="block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Commentaire
                    </label>
                    <textarea
                        value={comment}
                        onChange={(event) => setComment(event.target.value)}
                        rows={4}
                        className="mt-2 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                    />
                </section>

                {submitError ? (
                    <div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                        {submitError}
                    </div>
                ) : null}
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em]"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={submit}
                    disabled={!canSubmit || isSubmitting}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {isSubmitting ? 'Ajout...' : 'Ajouter'}
                </button>
            </div>

            <Modal show={showCreateClientConfirm} onClose={() => !isCreatingClient && setShowCreateClientConfirm(false)} maxWidth="lg" zIndexClass="z-[60]">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Créer ce nouveau client</h3>
                </div>
                <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4 text-sm">
                    {[
                        ['Code tiers', clientFields.code],
                        ['Nom / Raison sociale', clientFields.name],
                        ['Téléphone', clientFields.phone],
                        ['Adresse', clientFields.address],
                        ['Code postal', clientFields.postal_code],
                        ['Commune', clientFields.city],
                    ].map(([label, value]) => (
                        <div key={label} className="grid grid-cols-[9rem_1fr] gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2">
                            <span className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">{label}</span>
                            <span className="font-semibold">{value || '—'}</span>
                        </div>
                    ))}
                    {createClientError ? (
                        <div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                            {createClientError}
                        </div>
                    ) : null}
                </div>
                <div className="flex flex-col-reverse gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={() => setShowCreateClientConfirm(false)}
                        disabled={isCreatingClient}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] disabled:opacity-50"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        onClick={createManualClient}
                        disabled={isCreatingClient}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-50"
                    >
                        {isCreatingClient ? 'Création...' : 'Confirmer la création'}
                    </button>
                </div>
            </Modal>
        </Modal>
    );
}

function FuelNewClientsModal({ show, clients = [], onClose, onValidate }) {
    const [validatingId, setValidatingId] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        if (show) {
            setError('');
            setValidatingId(null);
        }
    }, [show]);

    const validate = async (client) => {
        setValidatingId(client.id);
        setError('');

        try {
            await onValidate(client.id);
        } catch (caughtError) {
            setError(caughtError instanceof Error ? caughtError.message : 'Impossible de valider ce client.');
        } finally {
            setValidatingId(null);
        }
    };

    const mailBody = [
        'Nouveau client :',
        '',
        ...clients.flatMap((client, index) => {
            const fields = [
                ['Code tiers', client.code],
                ['Nom / Raison sociale', client.name],
                ['Téléphone', client.phone],
                ['Adresse', client.address],
                ['Code postal', client.postal_code],
                ['Commune', client.city],
            ].filter(([, value]) => String(value || '').trim() !== '');

            return [
                `${index + 1}.`,
                ...fields.map(([label, value]) => `   ${label} : ${value}`),
                '',
            ];
        }),
    ].join('\n');

    const mailHref = `mailto:?subject=${encodeURIComponent('Nouveau client')}&body=${encodeURIComponent(mailBody)}`;

    return (
        <Modal show={show} onClose={onClose} maxWidth="5xl">
            <div className="flex items-start justify-between gap-4 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h2 className="text-base font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                    Nouveaux clients
                </h2>
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                    aria-label="Fermer"
                >
                    <X className="h-4 w-4" strokeWidth={2.2} />
                </button>
            </div>

            <div className="max-h-[calc(100vh-10rem)] space-y-3 overflow-y-auto bg-[var(--app-surface)] px-5 py-5">
                {error ? (
                    <div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                        {error}
                    </div>
                ) : null}

                {clients.length === 0 ? (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-5 text-sm text-[var(--app-muted)]">
                        Aucun nouveau client à traiter.
                    </div>
                ) : (
                    clients.map((client) => (
                        <article key={client.id} className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                            <div className="grid gap-3 text-sm lg:grid-cols-[9rem_1.4fr_10rem_1.2fr_8rem_1fr_auto] lg:items-center">
                                <div>
                                    <div className="text-[10px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Code tiers</div>
                                    <div className="font-bold">{client.code || '—'}</div>
                                </div>
                                <div>
                                    <div className="text-[10px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Nom / Raison sociale</div>
                                    <div className="font-bold">{client.name || '—'}</div>
                                </div>
                                <div>
                                    <div className="text-[10px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Téléphone</div>
                                    <div>{client.phone || '—'}</div>
                                </div>
                                <div>
                                    <div className="text-[10px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Adresse</div>
                                    <div>{client.address || '—'}</div>
                                </div>
                                <div>
                                    <div className="text-[10px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Code postal</div>
                                    <div>{client.postal_code || '—'}</div>
                                </div>
                                <div>
                                    <div className="text-[10px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Commune</div>
                                    <div>{client.city || '—'}</div>
                                    <div className="mt-1 text-xs text-[var(--app-muted)]">
                                        {[client.created_at, client.created_by ? `créé par ${client.created_by}` : ''].filter(Boolean).join(' • ')}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => validate(client)}
                                    disabled={validatingId === client.id}
                                    className="inline-flex justify-center rounded-xl border border-emerald-600 bg-emerald-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-50"
                                >
                                    {validatingId === client.id ? 'Validation...' : 'Traité'}
                                </button>
                            </div>
                        </article>
                    ))
                )}
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em]"
                >
                    Fermer
                </button>
                {clients.length > 0 ? (
                    <a
                        href={mailHref}
                        className="inline-flex justify-center rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                    >
                        Envoyer par mail
                    </a>
                ) : null}
            </div>
        </Modal>
    );
}

function FuelQuickEditCell({ row, type, value, displayValue, options = [], canUpdate, onUpdate }) {
    const [isEditing, setIsEditing] = useState(false);
    const [draft, setDraft] = useState(value || '');
    const [isSaving, setIsSaving] = useState(false);
    const initialValueRef = useRef(value || '');
    const draftRef = useRef(value || '');
    const skipCommitRef = useRef(false);
    const committingRef = useRef(false);

    useEffect(() => {
        if (!isEditing) {
            setDraft(value || '');
            draftRef.current = value || '';
            initialValueRef.current = value || '';
            committingRef.current = false;
            skipCommitRef.current = false;
        }
    }, [isEditing, value]);

    const commitValue = async (nextValue) => {
        if (skipCommitRef.current) {
            skipCommitRef.current = false;
            return;
        }
        if (committingRef.current) return;
        committingRef.current = true;

        setIsEditing(false);

        if (nextValue === initialValueRef.current) {
            committingRef.current = false;
            return;
        }

        setIsSaving(true);
        try {
            await onUpdate(row.id, type === 'date' ? { delivery_date: nextValue || null } : { site: nextValue || null });
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Impossible de mettre à jour la livraison.');
            setDraft(initialValueRef.current);
            draftRef.current = initialValueRef.current;
        } finally {
            setIsSaving(false);
        }
    };

    const cancel = () => {
        skipCommitRef.current = true;
        setDraft(initialValueRef.current);
        draftRef.current = initialValueRef.current;
        setIsEditing(false);
    };

    if (isEditing) {
        return (
            <td className="relative px-3 py-3 text-center align-middle">
                {type === 'date' ? (
                    <input
                        type="date"
                        value={draft}
                        onChange={(event) => {
                            const v = event.target.value;
                            setDraft(v);
                            draftRef.current = v;
                            if (v.length === 10) commitValue(v);
                        }}
                        onBlur={() => commitValue(draftRef.current)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                event.currentTarget.blur();
                            }
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                cancel();
                            }
                        }}
                        className="w-36 rounded-lg border border-[var(--brand-yellow-dark)] bg-[var(--app-surface)] px-2 py-1.5 text-center text-sm"
                        autoFocus
                    />
                ) : (
                    <select
                        value={draft}
                        onChange={(event) => {
                            const v = event.target.value;
                            setDraft(v);
                            draftRef.current = v;
                            commitValue(v);
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                cancel();
                            }
                        }}
                        className="w-44 rounded-lg border border-[var(--brand-yellow-dark)] bg-[var(--app-surface)] px-2 py-1.5 text-center text-sm"
                        autoFocus
                    >
                        <option value="">— Aucun site —</option>
                        {options.map((option) => (
                            <option key={option.id || option.label} value={option.label}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                )}
            </td>
        );
    }

    return (
        <td className="group relative px-3 py-3 text-center align-middle">
            <span className={isSaving ? 'opacity-60' : ''}>{displayValue || '—'}</span>
            {canUpdate ? (
                <button
                    type="button"
                    onClick={() => setIsEditing(true)}
                    disabled={isSaving}
                    title="Modifier rapidement"
                    className="absolute bottom-1.5 right-1.5 inline-flex h-5 w-5 items-center justify-center rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)] opacity-70 transition hover:border-[var(--brand-yellow-dark)] hover:text-[var(--app-text)] group-hover:opacity-100 disabled:cursor-wait disabled:opacity-40"
                >
                    <Pencil className="h-3 w-3" strokeWidth={2.2} />
                </button>
            ) : null}
        </td>
    );
}

function FuelEditModal({ show, delivery, onClose, onSave, options }) {
    const [form, setForm] = useState({
        delivery_date: '',
        site: '',
        code_tiers: '',
        client_name: '',
        phone: '',
        address: '',
        postal_code: '',
        city: '',
        fuel_type: '',
        volume_liters: '',
        urgent: false,
        comment: '',
    });
    const [error, setError] = useState('');
    const [isSaving, setIsSaving] = useState(false);

    useEffect(() => {
        if (!delivery) {
            return;
        }

        setForm({
            delivery_date: delivery.delivery_date_value || '',
            site: delivery.site || '',
            code_tiers: delivery.code_tiers || '',
            client_name: delivery.client_name || '',
            phone: delivery.phone || '',
            address: delivery.address || '',
            postal_code: delivery.postal_code || '',
            city: delivery.city || '',
            fuel_type: delivery.fuel_type || '',
            volume_liters: delivery.volume_liters ?? '',
            urgent: Boolean(delivery.urgent),
            comment: delivery.comment || '',
        });
        setError('');
        setIsSaving(false);
    }, [delivery]);

    const updateField = (key, value) => {
        setForm((current) => ({ ...current, [key]: value }));
    };

    const submit = async () => {
        if (!delivery) {
            return;
        }

        setIsSaving(true);
        setError('');

        try {
            await onSave(delivery.id, {
                delivery_date: form.delivery_date || null,
                site: form.site.trim() || null,
                code_tiers: form.code_tiers.trim() || null,
                client_name: form.client_name.trim() || null,
                phone: form.phone.trim() || null,
                address: form.address.trim() || null,
                postal_code: form.postal_code.trim() || null,
                city: form.city.trim() || null,
                fuel_type: form.fuel_type.trim() || null,
                volume_liters: form.volume_liters === '' ? null : Number(form.volume_liters),
                urgent: Boolean(form.urgent),
                comment: form.comment.trim() || null,
            });
            onClose();
        } catch (saveError) {
            setError(saveError instanceof Error ? saveError.message : 'Impossible de modifier la livraison carburant.');
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="5xl">
            <div className="flex items-start justify-between gap-4 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <div>
                    <h2 className="text-base font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        Modifier une livraison carburant
                    </h2>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                    aria-label="Fermer"
                >
                    <X className="h-4 w-4" strokeWidth={2.2} />
                </button>
            </div>

            <div className="max-h-[calc(100vh-10rem)] space-y-5 overflow-y-auto bg-[var(--app-surface)] px-5 py-5">
                <section className="grid gap-3 text-sm md:grid-cols-2">
                    <div>
                        <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Date Liv.
                        </label>
                        <input
                            type="date"
                            value={form.delivery_date}
                            onChange={(event) => updateField('delivery_date', event.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Site
                        </label>
                        <select
                            value={form.site}
                            onChange={(event) => updateField('site', event.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        >
                            <option value="">— Aucun site —</option>
                            {(options.sites || []).filter((option) => option.active).map((option) => (
                                <option key={option.id} value={option.label}>{option.label}</option>
                            ))}
                        </select>
                    </div>
                </section>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                    <h3 className="mb-3 text-sm font-black uppercase tracking-[0.08em]">Client</h3>
                    <div className="grid gap-3 text-sm lg:grid-cols-4">
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Code Tiers
                            </label>
                            <input
                                type="text"
                                value={form.code_tiers}
                                onChange={(event) => updateField('code_tiers', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="lg:col-span-2">
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Nom / Raison sociale
                            </label>
                            <input
                                type="text"
                                value={form.client_name}
                                onChange={(event) => updateField('client_name', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Téléphone
                            </label>
                            <input
                                type="text"
                                value={form.phone}
                                onChange={(event) => updateField('phone', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                    <div className="mt-3 grid gap-3 border-t border-[var(--app-border)] pt-3 text-sm lg:grid-cols-[2fr_12rem_1fr]">
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Adresse
                            </label>
                            <input
                                type="text"
                                value={form.address}
                                onChange={(event) => updateField('address', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Code postal
                            </label>
                            <input
                                type="text"
                                value={form.postal_code}
                                onChange={(event) => updateField('postal_code', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Commune
                            </label>
                            <input
                                type="text"
                                value={form.city}
                                onChange={(event) => updateField('city', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                </section>

                <section className="grid gap-3 text-sm md:grid-cols-[minmax(0,1fr)_12rem_auto] md:items-end">
                    <div>
                        <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Type
                        </label>
                        <select
                            value={form.fuel_type}
                            onChange={(event) => updateField('fuel_type', event.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        >
                            <option value="">Type de produit</option>
                            {(options.product_types || []).filter((option) => option.active).map((option) => (
                                <option key={option.id} value={option.label}>{option.label}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Volume
                        </label>
                        <div className="relative mt-1">
                            <input
                                type="number"
                                min="0"
                                step="1"
                                value={form.volume_liters}
                                onChange={(event) => updateField('volume_liters', event.target.value)}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pr-8 text-sm"
                            />
                            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-[var(--app-muted)]">L</span>
                        </div>
                    </div>
                    <label className="inline-flex h-[38px] items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-sm font-semibold">
                        <input
                            type="checkbox"
                            checked={form.urgent}
                            onChange={(event) => updateField('urgent', event.target.checked)}
                            className="rounded border-[var(--app-border)]"
                        />
                        Urgent
                    </label>
                </section>

                <section>
                    <div>
                        <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Commentaire
                        </label>
                        <textarea
                            value={form.comment}
                            onChange={(event) => updateField('comment', event.target.value)}
                            rows={5}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                    </div>
                </section>

                {error ? (
                    <div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                        {error}
                    </div>
                ) : null}
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em]"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={submit}
                    disabled={isSaving}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {isSaving ? 'Enregistrement...' : 'Enregistrer'}
                </button>
            </div>
        </Modal>
    );
}

function FuelPointModal({ show, delivery, drivers = [], siteOptions = [], onClose, onSubmit }) {
    const [actualDate, setActualDate] = useState(todayIsoDate());
    const [driverId, setDriverId] = useState('');
    const [error, setError] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (!show || !delivery) {
            return;
        }

        const site = siteOptions.find((option) => option.label === delivery.site);
        const siteDepotId = site?.depot_id ? Number(site.depot_id) : null;
        const autoDriver = siteDepotId
            ? drivers.find((driver) => (driver.depot_ids || []).map(Number).includes(siteDepotId))
            : null;

        setActualDate(delivery.actual_delivery_date_value || todayIsoDate());
        setDriverId(String(delivery.delivered_driver_id || autoDriver?.id || drivers[0]?.id || ''));
        setError('');
        setIsSubmitting(false);
    }, [show, delivery, drivers, siteOptions]);

    const submit = async () => {
        if (!delivery) return;

        if (!actualDate || !driverId) {
            setError('Renseignez la date réelle et le chauffeur de livraison.');
            return;
        }

        setIsSubmitting(true);
        setError('');

        try {
            await onSubmit(delivery.id, {
                actual_delivery_date: actualDate,
                delivered_driver_user_id: Number(driverId),
            });
            onClose();
        } catch (caughtError) {
            setError(caughtError instanceof Error ? caughtError.message : 'Impossible de valider le pointage.');
        } finally {
            setIsSubmitting(false);
        }
    };

    const details = delivery ? [
        ['Date Liv.', delivery.delivery_date || '—'],
        ['Site', delivery.site || '—'],
        ['Client / Code Tiers', [delivery.client_name, delivery.code_tiers].filter(Boolean).join(' - ') || '—'],
        ['N° Tél', delivery.phone || '—'],
        ['Commune Liv.', delivery.delivery_city || '—'],
        ['Type', delivery.fuel_type || '—'],
        ['Volume', delivery.volume || '—'],
        ['Commentaire', delivery.comment || '—'],
        ['Info', [delivery.created_at_label, delivery.created_by ? `créé par ${delivery.created_by}` : ''].filter(Boolean).join(' - ') || '—'],
        ['Urgent', delivery.urgent ? 'Oui' : 'Non'],
    ] : [];

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl">
            <div className="flex items-start justify-between gap-4 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <div>
                    <h2 className="text-base font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        Pointer la livraison
                    </h2>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]"
                    aria-label="Fermer"
                >
                    <X className="h-4 w-4" strokeWidth={2.2} />
                </button>
            </div>

            <div className="max-h-[calc(100vh-10rem)] space-y-5 overflow-y-auto bg-[var(--app-surface)] px-5 py-5">
                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                    <h3 className="mb-3 text-sm font-black uppercase tracking-[0.08em]">Récapitulatif</h3>
                    <div className="grid gap-3 text-sm md:grid-cols-2">
                        {details.map(([label, value]) => (
                            <div key={label} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2">
                                <div className="text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">{label}</div>
                                <div className="mt-1 font-semibold text-[var(--app-text)]">{value}</div>
                            </div>
                        ))}
                    </div>
                    {delivery?.is_delivered ? (
                        <div className="mt-3 inline-flex items-center gap-2 rounded-full border border-emerald-600 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                            <Check className="h-3.5 w-3.5" strokeWidth={2.4} />
                            Déjà livrée le {delivery.actual_delivery_date || delivery.delivered_at_label}
                        </div>
                    ) : null}
                </section>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                    <h3 className="mb-3 text-sm font-black uppercase tracking-[0.08em]">Pointage livraison</h3>
                    <div className="grid gap-3 text-sm md:grid-cols-[14rem_1fr]">
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Date de livraison réelle
                            </label>
                            <input
                                type="date"
                                value={actualDate}
                                onChange={(event) => setActualDate(event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Chauffeur de livraison
                            </label>
                            <select
                                value={driverId}
                                onChange={(event) => setDriverId(event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                            >
                                <option value="">Sélectionner un chauffeur</option>
                                {drivers.map((driver) => (
                                    <option key={driver.id} value={driver.id}>
                                        {[driver.name, driver.depot_name].filter(Boolean).join(' - ')}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {error ? (
                        <div className="mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                            {error}
                        </div>
                    ) : null}
                </section>
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em]"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={submit}
                    disabled={isSubmitting}
                    className="rounded-xl border border-emerald-600 bg-emerald-600 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {isSubmitting ? 'Pointage...' : 'Valider le pointage'}
                </button>
            </div>
        </Modal>
    );
}

function FuelTable({
    rows = [],
    meta,
    canUpdate = false,
    canDelete = false,
    siteOptions = [],
    productTypeOptions = [],
    sort,
    filters,
    onSort,
    onFilterChange,
    onClearFilter,
    onQuickUpdate,
    onPointRow,
    onEditRow,
    onDeleteRow,
    showStats = false,
    monthlyStats = null,
}) {
    const [copiedCodeId, setCopiedCodeId] = useState(null);
    const [openFilterKey, setOpenFilterKey] = useState(null);
    const copiedTimerRef = useRef(null);

    const statGroups = useMemo(() => (showStats ? computeStatGroups(rows) : []), [showStats, rows]);
    const statsByDateKey = useMemo(() => {
        const map = {};
        for (const g of statGroups) map[g.dateKey] = g;
        return map;
    }, [statGroups]);
    const firstRowIds = useMemo(() => {
        if (!showStats) return new Set();
        const seen = new Set();
        const ids = new Set();
        for (const row of rows) {
            const key = row.delivery_date_value || '__no_date__';
            if (!seen.has(key)) { seen.add(key); ids.add(row.id); }
        }
        return ids;
    }, [showStats, rows]);

    const copyCode = async (row) => {
        if (!row?.code_tiers) return;

        try {
            await copyTextToClipboard(row.code_tiers);
        } catch {
            return;
        }

        setCopiedCodeId(row.id);

        if (copiedTimerRef.current) {
            window.clearTimeout(copiedTimerRef.current);
        }

        copiedTimerRef.current = window.setTimeout(() => {
            setCopiedCodeId((current) => (current === row.id ? null : current));
            copiedTimerRef.current = null;
        }, 2000);
    };

    useEffect(() => () => {
        if (copiedTimerRef.current) {
            window.clearTimeout(copiedTimerRef.current);
        }
    }, []);

    return (
        <section className="w-full max-w-full space-y-4 px-0 sm:space-y-5">
            <div className="hidden w-full max-w-full rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-sm lg:block">
                <div className="overflow-visible rounded-xl border border-[var(--app-border)]">
                    <table className="min-w-[1260px] w-full border-collapse text-sm">
                        <thead className="sticky top-[var(--app-navbar-height,72px)] z-20">
                            <tr className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)] [&>th]:bg-[var(--app-surface-soft)] [&>th:first-child]:rounded-tl-xl [&>th:last-child]:rounded-tr-xl">
                                {COLUMNS.map((column) => (
                                    <ColumnHeader
                                        key={column.key}
                                        column={column}
                                        sort={sort}
                                        filters={filters}
                                        openFilterKey={openFilterKey}
                                        onOpenFilter={setOpenFilterKey}
                                        siteOptions={siteOptions}
                                        productTypeOptions={productTypeOptions}
                                        onSort={onSort}
                                        onFilterChange={onFilterChange}
                                        onClearFilter={onClearFilter}
                                    />
                                ))}
                                <th className="px-3 py-2 text-center">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {showStats && monthlyStats && rows.length > 0 ? (() => {
                                const lastCount = monthlyStats.last?.count ?? 0;
                                const lastVol = monthlyStats.last?.total_volume ?? 0;
                                const lastByProduct = monthlyStats.last?.by_product ?? {};
                                const curCount = monthlyStats.current?.count ?? 0;
                                const curVol = monthlyStats.current?.total_volume ?? 0;
                                const curByProduct = monthlyStats.current?.by_product ?? {};
                                const deltaCount = curCount - lastCount;
                                const deltaVol = curVol - lastVol;
                                const signCount = deltaCount > 0 ? '+' : '';
                                const signVol = deltaVol > 0 ? '+' : deltaVol < 0 ? '-' : '';
                                const deltaCountClass = deltaCount > 0 ? 'text-emerald-600' : deltaCount < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                                const deltaVolClass = deltaVol > 0 ? 'text-emerald-600' : deltaVol < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                                const threeCount = monthlyStats.three_months?.count ?? 0;
                                const threeVol = monthlyStats.three_months?.total_volume ?? 0;
                                const threeByProduct = monthlyStats.three_months?.by_product ?? {};
                                const sixCount = monthlyStats.six_months?.count ?? 0;
                                const sixVol = monthlyStats.six_months?.total_volume ?? 0;
                                const sixByProduct = monthlyStats.six_months?.by_product ?? {};
                                const priorThreeCount = monthlyStats.prior_three_months?.count ?? 0;
                                const priorThreeVol = monthlyStats.prior_three_months?.total_volume ?? 0;
                                const deltaThreeCount = threeCount - priorThreeCount;
                                const deltaThreeVol = threeVol - priorThreeVol;
                                const signThreeCount = deltaThreeCount > 0 ? '+' : '';
                                const signThreeVol = deltaThreeVol > 0 ? '+' : deltaThreeVol < 0 ? '-' : '';
                                const deltaThreeCountClass = deltaThreeCount > 0 ? 'text-emerald-600' : deltaThreeCount < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                                const deltaThreeVolClass = deltaThreeVol > 0 ? 'text-emerald-600' : deltaThreeVol < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                                const renderProductBreakdown = (byProduct) => Object.keys(byProduct).length > 0 ? (
                                    <span className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[var(--app-muted)]">
                                        <span className="select-none text-[var(--app-border)]">|</span>
                                        {Object.entries(byProduct).map(([prod, vol]) => (
                                            <span key={prod}><span className="font-semibold text-[var(--app-text)]">{prod}</span> {formatMonthlyVol(vol)}</span>
                                        ))}
                                    </span>
                                ) : null;
                                return (
                                    <tr key="monthly-stats-global" className="border-b-2 border-indigo-300">
                                        <td colSpan={COLUMNS.length + 1} className="bg-indigo-50/60 px-4 py-2.5 dark:bg-indigo-950/20">
                                            <div className="space-y-1 text-xs">
                                                {/* Row 1: mois dernier (gauche) | 3 derniers mois (droite) */}
                                                <div className="flex items-center gap-6">
                                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-0.5">
                                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-600">{monthlyStats.last_month_label}</span>
                                                        <span className="text-[var(--app-muted)]">
                                                            <span className="font-semibold text-[var(--app-text)]">{lastCount}</span>{' '}
                                                            livraison{lastCount !== 1 ? 's' : ''}{' — '}
                                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(lastVol)}</span>
                                                        </span>
                                                        {renderProductBreakdown(lastByProduct)}
                                                    </div>
                                                    <div className="ml-auto flex flex-wrap items-center justify-end gap-x-4 gap-y-0.5">
                                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-500">3 derniers mois</span>
                                                        <span className="text-[var(--app-muted)]">
                                                            <span className="font-semibold text-[var(--app-text)]">{threeCount}</span>{' '}
                                                            livraison{threeCount !== 1 ? 's' : ''}{' — '}
                                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(threeVol)}</span>
                                                        </span>
                                                        {renderProductBreakdown(threeByProduct)}
                                                    </div>
                                                </div>
                                                {/* Row 2: mois actuel (gauche) | 6 derniers mois (droite) */}
                                                <div className="flex items-center gap-6">
                                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-0.5">
                                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-700">{monthlyStats.current_month_label}</span>
                                                        <span className="text-[var(--app-muted)]">
                                                            <span className="font-semibold text-[var(--app-text)]">{curCount}</span>{' '}
                                                            livraison{curCount !== 1 ? 's' : ''}{' — '}
                                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(curVol)}</span>
                                                        </span>
                                                        {renderProductBreakdown(curByProduct)}
                                                    </div>
                                                    <div className="ml-auto flex flex-wrap items-center justify-end gap-x-4 gap-y-0.5">
                                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-500">6 derniers mois</span>
                                                        <span className="text-[var(--app-muted)]">
                                                            <span className="font-semibold text-[var(--app-text)]">{sixCount}</span>{' '}
                                                            livraison{sixCount !== 1 ? 's' : ''}{' — '}
                                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(sixVol)}</span>
                                                        </span>
                                                        {renderProductBreakdown(sixByProduct)}
                                                    </div>
                                                </div>
                                                {/* Row 3: évolution mois (gauche) | évolution 3 vs prior 3 (droite) */}
                                                <div className="flex items-center gap-6">
                                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[var(--app-muted)]">
                                                        <span className="font-black uppercase tracking-[0.08em]">Évolution</span>
                                                        <span className={`font-semibold ${deltaCountClass}`}>{signCount}{deltaCount} livraison{Math.abs(deltaCount) !== 1 ? 's' : ''}</span>
                                                        <span className="select-none">/</span>
                                                        <span className={`font-semibold ${deltaVolClass}`}>{signVol}{formatMonthlyVol(deltaVol)}</span>
                                                    </div>
                                                    <div className="ml-auto flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5 text-[var(--app-muted)]">
                                                        <span className="font-black uppercase tracking-[0.08em]">Évolution</span>
                                                        <span>(3 derniers vs 3 précédents)</span>
                                                        <span className={`font-semibold ${deltaThreeCountClass}`}>{signThreeCount}{deltaThreeCount} livraison{Math.abs(deltaThreeCount) !== 1 ? 's' : ''}</span>
                                                        <span className="select-none">/</span>
                                                        <span className={`font-semibold ${deltaThreeVolClass}`}>{signThreeVol}{formatMonthlyVol(deltaThreeVol)}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })() : null}
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={COLUMNS.length + 1}
                                        className="px-4 py-10 text-center text-sm text-[var(--app-muted)]"
                                    >
                                        Aucune livraison carburant.
                                    </td>
                                </tr>
                            ) : (
                                rows.flatMap((row) => {
                                    const dateKey = row.delivery_date_value || '__no_date__';
                                    const statsRow = showStats && firstRowIds.has(row.id) ? (
                                        <tr key={`stats-${dateKey}`} className="border-t-2 border-[var(--brand-yellow-dark)]">
                                            <td colSpan={COLUMNS.length + 1} className="bg-[var(--app-surface-soft)] px-4 py-2">
                                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                                                    <span className="font-black uppercase tracking-[0.08em]">
                                                        {statsByDateKey[dateKey]?.dateLabel || 'Sans date de livraison'}
                                                        {' — '}
                                                        {statsByDateKey[dateKey]?.count ?? 0}{' '}
                                                        livraison{(statsByDateKey[dateKey]?.count ?? 0) > 1 ? 's' : ''}
                                                    </span>
                                                    {Object.keys(statsByDateKey[dateKey]?.bySite || {}).length > 0 ? (
                                                        <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[var(--app-muted)]">
                                                            <span className="select-none text-[var(--app-border)]">|</span>
                                                            {Object.entries(statsByDateKey[dateKey]?.bySite || {}).map(([site, cnt]) => (
                                                                <span key={site}><span className="font-semibold text-[var(--app-text)]">{site}</span> {cnt}</span>
                                                            ))}
                                                        </span>
                                                    ) : null}
                                                    {Object.keys(statsByDateKey[dateKey]?.byProduct || {}).length > 0 ? (
                                                        <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[var(--app-muted)]">
                                                            <span className="select-none text-[var(--app-border)]">|</span>
                                                            {Object.entries(statsByDateKey[dateKey]?.byProduct || {}).map(([prod, vol]) => (
                                                                <span key={prod}><span className="font-semibold text-[var(--app-text)]">{prod}</span> {formatStatVol(vol)}</span>
                                                            ))}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </td>
                                        </tr>
                                    ) : null;
                                    const dataRow = (
                                        <tr
                                            key={row.id}
                                            data-fuel-row={row.id}
                                        className={`border-t border-[var(--app-border)] align-middle ${fuelRowStateClass(row)}`}
                                    >
                                        {COLUMNS.map((column) => {
                                            if (column.key === 'delivery_date') {
                                                return (
                                                    <FuelQuickEditCell
                                                        key={column.key}
                                                        row={row}
                                                        type="date"
                                                        value={row.delivery_date_value || ''}
                                                        displayValue={row.delivery_date}
                                                        canUpdate={canUpdate}
                                                        onUpdate={onQuickUpdate}
                                                    />
                                                );
                                            }

                                            if (column.key === 'site') {
                                                return (
                                                    <FuelQuickEditCell
                                                        key={column.key}
                                                        row={row}
                                                        type="site"
                                                        value={row.site || ''}
                                                        displayValue={row.site}
                                                        options={siteOptions}
                                                        canUpdate={canUpdate}
                                                        onUpdate={onQuickUpdate}
                                                    />
                                                );
                                            }

                                            if (column.key === 'client') {
                                                return (
                                                    <td key={column.key} className="px-3 py-3 text-center align-middle">
                                                        <div className="font-semibold">{row.client_name || '—'}</div>
                                                        {row.code_tiers ? (
                                                            <span className="relative mt-1 inline-flex items-center justify-center">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => copyCode(row)}
                                                                    title="Copier le code tiers"
                                                                    className="inline-flex rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1 text-xs font-bold text-[var(--app-muted)] transition hover:border-[var(--brand-yellow-dark)] hover:text-[var(--app-text)]"
                                                                >
                                                                    {row.code_tiers}
                                                                </button>
                                                                <span
                                                                    className={`pointer-events-none absolute left-full top-1/2 ml-2 -translate-y-1/2 whitespace-nowrap text-xs font-bold text-emerald-600 transition-opacity duration-200 ${
                                                                        copiedCodeId === row.id ? 'opacity-100' : 'opacity-0'
                                                                    }`}
                                                                >
                                                                    ✓ Copié
                                                                </span>
                                                            </span>
                                                        ) : null}
                                                    </td>
                                                );
                                            }

                                            if (column.key === 'info') {
                                                return (
                                                    <td key={column.key} className="px-3 py-3 text-center align-middle">
                                                        <div className="inline-flex items-center justify-center gap-2">
                                                            <div className="text-center">
                                                                <div>{row.created_at_label || '—'}</div>
                                                                {row.created_by ? (
                                                                    <div className="mt-1 text-xs text-[var(--app-muted)]">créé par {row.created_by}</div>
                                                                ) : null}
                                                            </div>
                                                            {row.is_recurring ? (
                                                                <span
                                                                    title="Récurrent"
                                                                    className="inline-flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full border border-sky-400 bg-sky-50 text-sky-600"
                                                                >
                                                                    <RefreshCw className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                                </span>
                                                            ) : null}
                                                            {row.urgent ? (
                                                                <span
                                                                    title="Urgent"
                                                                    className="inline-flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full border border-amber-500 bg-amber-100 text-amber-700"
                                                                >
                                                                    <AlertTriangle className="h-4 w-4" strokeWidth={2.2} />
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        {row.is_delivered ? (
                                                            <div className="mt-2 inline-flex items-center gap-1 rounded-full border border-emerald-600 bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">
                                                                <Check className="h-3 w-3" strokeWidth={2.4} />
                                                                Livrée
                                                            </div>
                                                        ) : null}
                                                    </td>
                                                );
                                            }

                                            return (
                                                <td key={column.key} className="px-3 py-3 text-center align-middle">
                                                    {row[column.key] || '—'}
                                                </td>
                                            );
                                        })}
                                        <td className="px-3 py-3 text-center align-middle">
                                            <div className="inline-flex items-center justify-center gap-1.5 whitespace-nowrap">
                                                {canUpdate ? (
                                                    <button
                                                        type="button"
                                                        title={row.is_delivered ? 'Voir / modifier le pointage' : 'Pointer la livraison'}
                                                        onClick={() => onPointRow(row)}
                                                        className={`inline-flex h-7 w-7 items-center justify-center rounded-md border ${
                                                            row.is_delivered
                                                                ? 'border-emerald-600 bg-emerald-600 text-white'
                                                                : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]'
                                                        }`}
                                                    >
                                                        <Check className="h-3.5 w-3.5" strokeWidth={2.4} />
                                                    </button>
                                                ) : null}
                                                {canUpdate ? (
                                                    <button
                                                        type="button"
                                                        title="Modifier"
                                                        onClick={() => onEditRow(row)}
                                                        className="inline-flex h-7 w-7 items-center justify-center rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    </button>
                                                ) : null}
                                                {canDelete ? (
                                                    <button
                                                        type="button"
                                                        title="Supprimer"
                                                        onClick={() => onDeleteRow(row)}
                                                        className="inline-flex h-7 w-7 items-center justify-center rounded-md border border-red-500 bg-red-500 text-white"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    </button>
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                    );
                                    return [statsRow, dataRow].filter(Boolean);
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                <FuelCountFooter meta={meta} />
            </div>

            <div className="grid gap-4 lg:hidden">
                {showStats && monthlyStats && rows.length > 0 ? (() => {
                    const lastCount = monthlyStats.last?.count ?? 0;
                    const lastVol = monthlyStats.last?.total_volume ?? 0;
                    const lastByProduct = monthlyStats.last?.by_product ?? {};
                    const curCount = monthlyStats.current?.count ?? 0;
                    const curVol = monthlyStats.current?.total_volume ?? 0;
                    const curByProduct = monthlyStats.current?.by_product ?? {};
                    const deltaCount = curCount - lastCount;
                    const deltaVol = curVol - lastVol;
                    const signCount = deltaCount > 0 ? '+' : '';
                    const signVol = deltaVol > 0 ? '+' : deltaVol < 0 ? '-' : '';
                    const deltaCountClass = deltaCount > 0 ? 'text-emerald-600' : deltaCount < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                    const deltaVolClass = deltaVol > 0 ? 'text-emerald-600' : deltaVol < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                    const threeCount = monthlyStats.three_months?.count ?? 0;
                    const threeVol = monthlyStats.three_months?.total_volume ?? 0;
                    const threeByProduct = monthlyStats.three_months?.by_product ?? {};
                    const sixCount = monthlyStats.six_months?.count ?? 0;
                    const sixVol = monthlyStats.six_months?.total_volume ?? 0;
                    const sixByProduct = monthlyStats.six_months?.by_product ?? {};
                    const priorThreeCount = monthlyStats.prior_three_months?.count ?? 0;
                    const priorThreeVol = monthlyStats.prior_three_months?.total_volume ?? 0;
                    const deltaThreeCount = threeCount - priorThreeCount;
                    const deltaThreeVol = threeVol - priorThreeVol;
                    const signThreeCount = deltaThreeCount > 0 ? '+' : '';
                    const signThreeVol = deltaThreeVol > 0 ? '+' : deltaThreeVol < 0 ? '-' : '';
                    const deltaThreeCountClass = deltaThreeCount > 0 ? 'text-emerald-600' : deltaThreeCount < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                    const deltaThreeVolClass = deltaThreeVol > 0 ? 'text-emerald-600' : deltaThreeVol < 0 ? 'text-red-500' : 'text-[var(--app-muted)]';
                    const renderMobileProducts = (byProduct) => Object.keys(byProduct).length > 0 ? (
                        <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[var(--app-muted)]">
                            {Object.entries(byProduct).map(([prod, vol]) => (
                                <span key={prod}><span className="font-semibold text-[var(--app-text)]">{prod}</span> {formatMonthlyVol(vol)}</span>
                            ))}
                        </div>
                    ) : null;
                    return (
                        <div key="monthly-stats-global-card" className="rounded-2xl border-2 border-indigo-300 bg-indigo-50/60 px-4 py-3 shadow-sm dark:bg-indigo-950/20">
                            <div className="space-y-2 text-xs">
                                {/* Mois dernier / Mois actuel / Évolution */}
                                <div className="space-y-1">
                                    <div>
                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-600">{monthlyStats.last_month_label}</span>
                                        <span className="ml-2 text-[var(--app-muted)]">
                                            <span className="font-semibold text-[var(--app-text)]">{lastCount}</span> livraison{lastCount !== 1 ? 's' : ''}{' — '}
                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(lastVol)}</span>
                                        </span>
                                        {renderMobileProducts(lastByProduct)}
                                    </div>
                                    <div>
                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-700">{monthlyStats.current_month_label}</span>
                                        <span className="ml-2 text-[var(--app-muted)]">
                                            <span className="font-semibold text-[var(--app-text)]">{curCount}</span> livraison{curCount !== 1 ? 's' : ''}{' — '}
                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(curVol)}</span>
                                        </span>
                                        {renderMobileProducts(curByProduct)}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[var(--app-muted)]">
                                        <span className="font-black uppercase tracking-[0.08em]">Évolution</span>
                                        <span className={`font-semibold ${deltaCountClass}`}>{signCount}{deltaCount} livraison{Math.abs(deltaCount) !== 1 ? 's' : ''}</span>
                                        <span>/</span>
                                        <span className={`font-semibold ${deltaVolClass}`}>{signVol}{formatMonthlyVol(deltaVol)}</span>
                                    </div>
                                </div>
                                {/* Séparateur */}
                                <div className="border-t border-indigo-200" />
                                {/* 3 derniers mois / 6 derniers mois / Évolution 3 vs 3 précédents */}
                                <div className="space-y-1">
                                    <div>
                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-500">3 derniers mois</span>
                                        <span className="ml-2 text-[var(--app-muted)]">
                                            <span className="font-semibold text-[var(--app-text)]">{threeCount}</span> livraison{threeCount !== 1 ? 's' : ''}{' — '}
                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(threeVol)}</span>
                                        </span>
                                        {renderMobileProducts(threeByProduct)}
                                    </div>
                                    <div>
                                        <span className="font-black uppercase tracking-[0.08em] text-indigo-500">6 derniers mois</span>
                                        <span className="ml-2 text-[var(--app-muted)]">
                                            <span className="font-semibold text-[var(--app-text)]">{sixCount}</span> livraison{sixCount !== 1 ? 's' : ''}{' — '}
                                            <span className="font-semibold text-[var(--app-text)]">{formatMonthlyVol(sixVol)}</span>
                                        </span>
                                        {renderMobileProducts(sixByProduct)}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[var(--app-muted)]">
                                        <span className="font-black uppercase tracking-[0.08em]">Évolution</span>
                                        <span className="text-[var(--app-muted)]">(3 mois vs 3 précédents)</span>
                                        <span className={`font-semibold ${deltaThreeCountClass}`}>{signThreeCount}{deltaThreeCount} livraison{Math.abs(deltaThreeCount) !== 1 ? 's' : ''}</span>
                                        <span>/</span>
                                        <span className={`font-semibold ${deltaThreeVolClass}`}>{signThreeVol}{formatMonthlyVol(deltaThreeVol)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })() : null}
                {rows.length === 0 ? (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)] shadow-sm">
                        Aucune livraison carburant.
                    </div>
                ) : (
                    rows.flatMap((row) => {
                        const dateKey = row.delivery_date_value || '__no_date__';
                        const statsCard = showStats && firstRowIds.has(row.id) ? (
                            <div key={`stats-${dateKey}`} className="rounded-2xl border-2 border-[var(--brand-yellow-dark)] bg-[var(--app-surface-soft)] px-4 py-3 shadow-sm">
                                <p className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                                    {statsByDateKey[dateKey]?.dateLabel || 'Sans date de livraison'}
                                    {' — '}
                                    {statsByDateKey[dateKey]?.count ?? 0}{' '}
                                    livraison{(statsByDateKey[dateKey]?.count ?? 0) > 1 ? 's' : ''}
                                </p>
                                {Object.keys(statsByDateKey[dateKey]?.bySite || {}).length > 0 ? (
                                    <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-[var(--app-muted)]">
                                        {Object.entries(statsByDateKey[dateKey]?.bySite || {}).map(([site, cnt]) => (
                                            <span key={site}><span className="font-semibold text-[var(--app-text)]">{site}</span> {cnt}</span>
                                        ))}
                                    </div>
                                ) : null}
                                {Object.keys(statsByDateKey[dateKey]?.byProduct || {}).length > 0 ? (
                                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-[var(--app-muted)]">
                                        {Object.entries(statsByDateKey[dateKey]?.byProduct || {}).map(([prod, vol]) => (
                                            <span key={prod}><span className="font-semibold text-[var(--app-text)]">{prod}</span> {formatStatVol(vol)}</span>
                                        ))}
                                    </div>
                                ) : null}
                            </div>
                        ) : null;
                        const card = (
                        <article
                            key={row.id}
                            data-fuel-row={row.id}
                            className={`rounded-2xl border border-[var(--app-border)] p-4 shadow-sm ${fuelRowStateClass(row) || 'bg-[var(--app-surface)]'} ${fuelCardRingClass(row)}`}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        {row.delivery_date || 'Date à définir'}
                                    </p>
                                    <h2 className="mt-1 text-base font-black text-[var(--app-text)]">
                                        {row.client_name || 'Client à définir'}
                                    </h2>
                                    {row.code_tiers ? (
                                        <span className="relative mt-1 inline-flex items-center">
                                            <button
                                                type="button"
                                                onClick={() => copyCode(row)}
                                                className="rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1 text-xs font-bold text-[var(--app-muted)]"
                                            >
                                                {row.code_tiers}
                                            </button>
                                            <span
                                                className={`pointer-events-none absolute left-full top-1/2 ml-2 -translate-y-1/2 whitespace-nowrap text-xs font-bold text-emerald-600 transition-opacity duration-200 ${
                                                    copiedCodeId === row.id ? 'opacity-100' : 'opacity-0'
                                                }`}
                                            >
                                                ✓ Copié
                                            </span>
                                        </span>
                                    ) : null}
                                </div>
                                <div className="flex flex-col items-end gap-2">
                                    {canUpdate ? (
                                        <button
                                            type="button"
                                            onClick={() => onPointRow(row)}
                                            className={`inline-flex h-8 w-8 items-center justify-center rounded-lg border ${
                                                row.is_delivered
                                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                                    : 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]'
                                            }`}
                                            aria-label="Pointer"
                                        >
                                            <Check className="h-3.5 w-3.5" strokeWidth={2.4} />
                                        </button>
                                    ) : null}
                                    {canUpdate ? (
                                        <button
                                            type="button"
                                            onClick={() => onEditRow(row)}
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                            aria-label="Modifier"
                                        >
                                            <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                        </button>
                                    ) : null}
                                    {canDelete ? (
                                        <button
                                            type="button"
                                            onClick={() => onDeleteRow(row)}
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-red-500 bg-red-500 text-white"
                                            aria-label="Supprimer"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                        </button>
                                    ) : null}
                                    <span className="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1 text-xs font-bold">
                                        {row.volume || '—'}
                                    </span>
                                    {row.is_recurring ? (
                                        <span className="inline-flex items-center gap-1 rounded-full border border-sky-400 bg-sky-50 px-2 py-1 text-xs font-bold text-sky-600">
                                            <RefreshCw className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            Récurrent
                                        </span>
                                    ) : null}
                                    {row.urgent ? (
                                        <span className="inline-flex items-center gap-1 rounded-full border border-amber-500 bg-amber-100 px-2 py-1 text-xs font-bold text-amber-700">
                                            <AlertTriangle className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            Urgent
                                        </span>
                                    ) : null}
                                    {row.is_delivered ? (
                                        <span className="inline-flex items-center gap-1 rounded-full border border-emerald-600 bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">
                                            <Check className="h-3.5 w-3.5" strokeWidth={2.4} />
                                            Livrée
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                            <div className="mt-3 grid gap-2 text-sm text-[var(--app-muted)]">
                                <div>{[row.fuel_type, row.delivery_city].filter(Boolean).join(' • ') || '—'}</div>
                                {row.comment ? <div>{row.comment}</div> : null}
                                <div>{[row.created_at_label, row.created_by ? `créé par ${row.created_by}` : ''].filter(Boolean).join(' • ')}</div>
                            </div>
                        </article>
                        );
                        return [statsCard, card].filter(Boolean);
                    })
                )}
            </div>
            <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm lg:hidden">
                <FuelCountFooter meta={meta} />
            </div>
        </section>
    );
}

export default function TaskFuelIndex({ permissions = {}, deliveries = [], options = { sites: [], product_types: [] }, query = {}, depots = [], fuelDrivers = [], newClients = [], recurrings: initialRecurrings = [], monthlyStats = null }) {
    const canUpdate = Boolean(permissions.can_update);
    const canDelete = Boolean(permissions.can_delete);
    const canManageNewClients = Boolean(permissions.can_manage_new_clients);
    const canManageRecurrings = Boolean(permissions.can_manage_recurrings);
    const initialDeliveries = normalizeDeliveryPayload(deliveries);
    const [search, setSearch] = useState(query.search || '');
    const [rows, setRows] = useState(initialDeliveries.rows);
    const [paginationMeta, setPaginationMeta] = useState(initialDeliveries.meta);
    const [fuelOptions, setFuelOptions] = useState(options);
    const [filters, setFilters] = useState(cloneFilters(query.filters || {}));
    const [sort, setSort] = useState({
        key: query.sort?.key || '',
        direction: query.sort?.direction || '',
    });
    const [recurrings, setRecurrings] = useState(initialRecurrings);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showSettingsModal, setShowSettingsModal] = useState(false);
    const [showRecurringModal, setShowRecurringModal] = useState(false);
    const [showNewClientsModal, setShowNewClientsModal] = useState(false);
    const [pendingNewClients, setPendingNewClients] = useState(Array.isArray(newClients) ? newClients : []);
    const [editingDelivery, setEditingDelivery] = useState(null);
    const [pointingDelivery, setPointingDelivery] = useState(null);
    const [confirmDeleteRow, setConfirmDeleteRow] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [showFloatingActions, setShowFloatingActions] = useState(false);
    const [showStats, setShowStats] = useState(false);
    const searchDebounceReadyRef = useRef(false);
    const pendingFlipRef = useRef(null);

    useEffect(() => {
        const nextDeliveries = normalizeDeliveryPayload(deliveries);
        setRows(nextDeliveries.rows);
        setPaginationMeta(nextDeliveries.meta);
    }, [deliveries]);

    useEffect(() => {
        setFuelOptions(options);
    }, [options]);

    useEffect(() => {
        setPendingNewClients(Array.isArray(newClients) ? newClients : []);
    }, [newClients]);

    useEffect(() => {
        setRecurrings(Array.isArray(initialRecurrings) ? initialRecurrings : []);
    }, [initialRecurrings]);

    useEffect(() => {
        const updateFloatingActions = () => {
            setShowFloatingActions(window.scrollY > 0);
        };

        updateFloatingActions();
        window.addEventListener('scroll', updateFloatingActions, { passive: true });
        window.addEventListener('resize', updateFloatingActions);

        return () => {
            window.removeEventListener('scroll', updateFloatingActions);
            window.removeEventListener('resize', updateFloatingActions);
        };
    }, []);

    const activeSiteOptions = useMemo(() => (fuelOptions.sites || []).filter((option) => option.active), [fuelOptions.sites]);
    const activeProductTypeOptions = useMemo(() => (fuelOptions.product_types || []).filter((option) => option.active), [fuelOptions.product_types]);
    const filtersActive = hasAnyFilter(filters);
    const isDefaultSortActive = sort.key === '' && sort.direction === '';

    const captureFlipPositions = () => {
        const positions = {};
        document.querySelectorAll('[data-fuel-row]').forEach((el) => {
            const rect = el.getBoundingClientRect();
            if (rect.width > 0) positions[el.dataset.fuelRow] = rect.top;
        });
        return positions;
    };

    const setRowsWithFlip = (nextRowsOrFn) => {
        pendingFlipRef.current = captureFlipPositions();
        setRows(nextRowsOrFn);
    };

    useLayoutEffect(() => {
        const firstPositions = pendingFlipRef.current;
        if (!firstPositions) return;
        pendingFlipRef.current = null;

        const timers = [];
        document.querySelectorAll('[data-fuel-row]').forEach((el) => {
            const first = firstPositions[el.dataset.fuelRow];
            if (first === undefined) return;
            const last = el.getBoundingClientRect().top;
            const delta = first - last;
            if (Math.abs(delta) < 1) return;

            el.style.transform = `translateY(${delta}px)`;
            el.style.transition = 'none';

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    el.style.transform = '';
                    el.style.transition = 'transform 360ms cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                    timers.push(setTimeout(() => {
                        el.style.transform = '';
                        el.style.transition = '';
                    }, 400));
                });
            });
        });

        return () => timers.forEach(clearTimeout);
    }, [rows]);

    const loadFuelPage = (overrides = {}) => {
        router.get('/tasks/fuel', {
            search: overrides.search ?? search,
            filters: overrides.filters ?? filters,
            sort: overrides.sort ?? sort,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    useEffect(() => {
        if (!searchDebounceReadyRef.current) {
            searchDebounceReadyRef.current = true;
            return undefined;
        }

        const timeoutId = window.setTimeout(() => {
            loadFuelPage({ search });
        }, 300);

        return () => window.clearTimeout(timeoutId);
    }, [search]);

    const updateSort = (key) => {
        const nextSort = sort.key !== key
            ? { key, direction: 'asc' }
            : sort.direction === 'asc'
                ? { key, direction: 'desc' }
                : sort.direction === 'desc'
                    ? { key: '', direction: '' }
                    : { key, direction: 'asc' };

        setSort(nextSort);
        loadFuelPage({ sort: nextSort });
    };

    const updateFilter = (key, value) => {
        const nextFilters = cloneFilters({ ...filters, [key]: value });
        setFilters(nextFilters);
        loadFuelPage({ filters: nextFilters });
    };

    const clearFilter = (key) => {
        updateFilter(key, EMPTY_FILTERS[key]);
    };

    const resetFilters = () => {
        const nextFilters = cloneFilters();
        setFilters(nextFilters);
        loadFuelPage({ filters: nextFilters });
    };

    const updateDelivery = async (id, payload) => {
        const data = await jsonRequest(`/tasks/fuel/${id}`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
        });

        if (data?.delivery) {
            if (isDefaultSortActive) {
                setRowsWithFlip((current) =>
                    applyDefaultSort(current.map((row) => (row.id === id ? data.delivery : row)))
                );
            } else {
                setRows((current) => current.map((row) => (row.id === id ? data.delivery : row)));
            }
            setEditingDelivery((current) => (current?.id === id ? data.delivery : current));
        }

        return data?.delivery;
    };

    const pointDelivery = async (id, payload) => {
        const data = await jsonRequest(`/tasks/fuel/${id}/point`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
        });

        if (data?.delivery) {
            const updateRows = (current) => {
                if (filters.info?.delivered === 'no' && data.delivery.is_delivered) {
                    return current.filter((row) => row.id !== id);
                }

                const nextRows = current.map((row) => (row.id === id ? data.delivery : row));
                return isDefaultSortActive ? applyDefaultSort(nextRows) : nextRows;
            };

            if (isDefaultSortActive) {
                setRowsWithFlip(updateRows);
            } else {
                setRows(updateRows);
            }
            if (filters.info?.delivered === 'no' && data.delivery.is_delivered) {
                setPaginationMeta((current) => ({
                    ...current,
                    total: Math.max(0, (current.total || 0) - 1),
                }));
            }
            setPointingDelivery((current) => (current?.id === id ? data.delivery : current));
        }

        return data?.delivery;
    };

    const deleteDelivery = async () => {
        if (!confirmDeleteRow) return;
        setIsDeleting(true);

        try {
            await jsonRequest(`/tasks/fuel/${confirmDeleteRow.id}`, { method: 'DELETE' });

            setRows((current) => current.filter((row) => row.id !== confirmDeleteRow.id));
            setPaginationMeta((current) => ({
                ...current,
                total: rows.length > 0 ? rows.length - 1 : Math.max(0, (current.total || 0) - 1),
            }));
            setConfirmDeleteRow(null);

            window.dispatchEvent(new CustomEvent('app:toast', {
                detail: { type: 'success', message: '✓ Livraison supprimée' },
            }));
        } catch {
            window.dispatchEvent(new CustomEvent('app:toast', {
                detail: { type: 'error', message: 'Impossible de supprimer cette livraison.' },
            }));
        } finally {
            setIsDeleting(false);
        }
    };

    const createDeliveries = async (payload) => {
        const data = await jsonRequest('/tasks/fuel', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        const newRows = Array.isArray(data?.deliveries) ? data.deliveries : [];
        if (isDefaultSortActive) {
            setRowsWithFlip((current) => applyDefaultSort([...newRows, ...current]));
        } else {
            setRows((current) => [...newRows, ...current]);
        }
        setPaginationMeta((current) => ({
            ...current,
            total: rows.length + newRows.length,
        }));
    };

    const createNewClient = async (client) => {
        const data = await jsonRequest('/tasks/fuel/new-clients', {
            method: 'POST',
            body: JSON.stringify({ client }),
        });

        if (Array.isArray(data?.new_clients)) {
            setPendingNewClients(data.new_clients);
        }

        return data?.tiers || null;
    };

    const validateNewClient = async (id) => {
        const data = await jsonRequest(`/tasks/fuel/new-clients/${id}/validate`, {
            method: 'PATCH',
        });

        if (Array.isArray(data?.new_clients)) {
            setPendingNewClients(data.new_clients);
        } else {
            setPendingNewClients((current) => current.filter((client) => client.id !== id));
        }
    };

    const pageHeader = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h1 className="text-[22px] leading-none">
                <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">
                    CARBURANT
                </span>
            </h1>

            <div className="flex w-full flex-col gap-2 lg:w-auto lg:flex-row lg:items-center">
                <button
                    type="button"
                    onClick={() => setShowStats((v) => !v)}
                    className={`inline-flex items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-black uppercase tracking-[0.12em] transition lg:shrink-0 ${
                        showStats
                            ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                            : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]'
                    }`}
                >
                    <BarChart2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                    <span>{showStats ? 'Masquer les statistiques' : 'Statistiques'}</span>
                </button>

                {canUpdate ? (
                    <button
                        type="button"
                        onClick={() => setShowSettingsModal(true)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)] lg:shrink-0"
                    >
                        <Settings className="h-3.5 w-3.5" strokeWidth={2.2} />
                        <span>Paramètres</span>
                    </button>
                ) : null}

                {canManageRecurrings ? (
                    <button
                        type="button"
                        onClick={() => setShowRecurringModal(true)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)] lg:shrink-0"
                    >
                        <RefreshCw className="h-3.5 w-3.5" strokeWidth={2.2} />
                        <span>Récurrent</span>
                        {recurrings.length > 0 ? (
                            <span className="rounded-full bg-sky-500 px-1.5 py-0.5 text-[10px] leading-none text-white">{recurrings.length}</span>
                        ) : null}
                    </button>
                ) : null}

                {canManageNewClients && pendingNewClients.length > 0 ? (
                    <button
                        type="button"
                        onClick={() => setShowNewClientsModal(true)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-emerald-600 bg-emerald-50 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-emerald-700 transition hover:bg-emerald-100 lg:shrink-0"
                    >
                        <span>Nouveaux clients</span>
                        <span className="rounded-full bg-emerald-600 px-1.5 py-0.5 text-[10px] leading-none text-white">
                            {pendingNewClients.length}
                        </span>
                    </button>
                ) : null}

                {filtersActive ? (
                    <button
                        type="button"
                        onClick={resetFilters}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)] lg:shrink-0"
                    >
                        <X className="h-3.5 w-3.5" strokeWidth={2.2} />
                        <span>Réinitialiser les filtres</span>
                    </button>
                ) : null}

                <div className="w-full lg:w-[420px]">
                    <label className="sr-only">Recherche carburant</label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Site, client, code tiers, commune, commentaire, type..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                        />
                    </div>
                </div>

                {canUpdate ? (
                    <button
                        type="button"
                        onClick={() => setShowCreateModal(true)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] lg:shrink-0"
                    >
                        <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                        <span>Ajouter</span>
                    </button>
                ) : null}
            </div>
        </div>
    );

    return (
        <AppLayout title="Carburant" header={pageHeader}>
            <Head title="Carburant" />

            <FuelTable
                rows={rows}
                meta={paginationMeta}
                canUpdate={canUpdate}
                canDelete={canDelete}
                siteOptions={activeSiteOptions}
                productTypeOptions={activeProductTypeOptions}
                sort={sort}
                filters={filters}
                onSort={updateSort}
                onFilterChange={updateFilter}
                onClearFilter={clearFilter}
                onQuickUpdate={updateDelivery}
                onPointRow={setPointingDelivery}
                onEditRow={setEditingDelivery}
                onDeleteRow={setConfirmDeleteRow}
                showStats={showStats}
                monthlyStats={monthlyStats}
            />

            <FuelFloatingActions
                visible={showFloatingActions}
                canUpdate={canUpdate}
                search={search}
                onSearchChange={setSearch}
                onAdd={() => setShowCreateModal(true)}
                showStats={showStats}
                onToggleStats={() => setShowStats((v) => !v)}
            />

            <FuelSettingsModal
                show={showSettingsModal}
                onClose={() => setShowSettingsModal(false)}
                options={fuelOptions}
                onOptionsChange={setFuelOptions}
                depots={depots}
            />

            <FuelRecurringModal
                show={showRecurringModal}
                onClose={() => setShowRecurringModal(false)}
                recurrings={recurrings}
                canUpdate={canUpdate}
                canDelete={canDelete}
                options={fuelOptions}
                onRecurringsChange={setRecurrings}
            />

            <FuelDeliveryModal
                show={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                onCreateDeliveries={createDeliveries}
                onCreateNewClient={createNewClient}
                canCreateNewClient={canManageNewClients}
                options={fuelOptions}
            />

            <FuelNewClientsModal
                show={showNewClientsModal}
                clients={pendingNewClients}
                onClose={() => setShowNewClientsModal(false)}
                onValidate={validateNewClient}
            />

            <FuelEditModal
                show={Boolean(editingDelivery)}
                delivery={editingDelivery}
                onClose={() => setEditingDelivery(null)}
                onSave={updateDelivery}
                options={fuelOptions}
            />

            <FuelPointModal
                show={Boolean(pointingDelivery)}
                delivery={pointingDelivery}
                drivers={fuelDrivers}
                siteOptions={activeSiteOptions}
                onClose={() => setPointingDelivery(null)}
                onSubmit={pointDelivery}
            />

            <Modal show={Boolean(confirmDeleteRow)} onClose={() => !isDeleting && setConfirmDeleteRow(null)} maxWidth="sm">
                <div className="p-6">
                    <h2 className="text-base font-black uppercase tracking-[0.06em] text-[var(--app-text)]">
                        Supprimer cette livraison&nbsp;?
                    </h2>
                    <p className="mt-3 text-sm text-[var(--app-muted)]">
                        Êtes-vous certain de vouloir supprimer cette livraison carburant&nbsp;? Cette action est irréversible.
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => setConfirmDeleteRow(null)}
                            disabled={isDeleting}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] disabled:opacity-50"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            onClick={deleteDelivery}
                            disabled={isDeleting}
                            className="rounded-xl border border-red-600 bg-red-600 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-50"
                        >
                            {isDeleting ? 'Suppression...' : 'Supprimer'}
                        </button>
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}
