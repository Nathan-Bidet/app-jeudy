import AppLayout from '@/Layouts/AppLayout';
import Modal from '@/Components/Modal';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Filter, Pencil, Plus, Search, Settings, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const PAGE_SIZE = 50;
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
    info: { text: '', urgent: '' },
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
                current_page: 1,
                from: deliveries.length ? 1 : 0,
                last_page: 1,
                per_page: PAGE_SIZE,
                to: deliveries.length,
                total: deliveries.length,
            },
        };
    }

    return {
        rows: Array.isArray(deliveries?.data) ? deliveries.data : [],
        meta: {
            current_page: deliveries?.meta?.current_page || 1,
            from: deliveries?.meta?.from || 0,
            last_page: deliveries?.meta?.last_page || 1,
            per_page: deliveries?.meta?.per_page || PAGE_SIZE,
            to: deliveries?.meta?.to || 0,
            total: deliveries?.meta?.total || 0,
        },
    };
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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

function FuelFilterFields({ column, draft, onDraftChange, siteOptions, productTypeOptions }) {
    if (column.key === 'delivery_date') {
        return (
            <div className="space-y-2">
                <select
                    value={draft.mode || ''}
                    onChange={(event) => onDraftChange({ ...draft, mode: event.target.value })}
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                >
                    <option value="">Type de filtre</option>
                    <option value="exact">Date exacte</option>
                    <option value="before">Avant le</option>
                    <option value="after">Après le</option>
                    <option value="between">Entre deux dates</option>
                </select>
                {draft.mode === 'between' ? (
                    <div className="grid grid-cols-2 gap-2">
                        <input
                            type="date"
                            value={draft.from || ''}
                            onChange={(event) => onDraftChange({ ...draft, from: event.target.value })}
                            className="min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                        />
                        <input
                            type="date"
                            value={draft.to || ''}
                            onChange={(event) => onDraftChange({ ...draft, to: event.target.value })}
                            className="min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                        />
                    </div>
                ) : (
                    <input
                        type="date"
                        value={draft.value || ''}
                        onChange={(event) => onDraftChange({ ...draft, value: event.target.value })}
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
                onChange={(event) => onDraftChange(event.target.value)}
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
                    onChange={(event) => onDraftChange({ ...draft, urgent: event.target.value })}
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1.5 text-xs"
                >
                    <option value="">Urgent oui/non</option>
                    <option value="yes">Urgent uniquement</option>
                    <option value="no">Non urgent uniquement</option>
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

function ColumnHeader({ column, sort, filters, siteOptions, productTypeOptions, onSort, onFilterChange, onClearFilter }) {
    const [filterOpen, setFilterOpen] = useState(false);
    const [draft, setDraft] = useState(cloneFilters(filters)[column.key]);
    const isFiltered = hasColumnFilter(filters, column.key);
    const isSorted = sort.key === column.key && sort.direction;

    useEffect(() => {
        if (filterOpen) {
            setDraft(cloneFilters(filters)[column.key]);
        }
    }, [filterOpen, filters, column.key]);

    const applyFilter = (event) => {
        event.preventDefault();
        onFilterChange(column.key, draft);
        setFilterOpen(false);
    };

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
                    type="button"
                    onClick={() => setFilterOpen((current) => !current)}
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

            {filterOpen ? (
                <form onSubmit={applyFilter} className="absolute left-2 top-full z-30 mt-2 w-72 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 text-left normal-case tracking-normal shadow-lg">
                    <p className="mb-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Filtrer {column.label}
                    </p>
                    <FuelFilterFields
                        column={column}
                        draft={draft}
                        onDraftChange={setDraft}
                        siteOptions={siteOptions}
                        productTypeOptions={productTypeOptions}
                    />
                    <div className="mt-3 flex justify-between gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                onClearFilter(column.key);
                                setFilterOpen(false);
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
                </form>
            ) : null}
        </th>
    );
}

function PaginationFooter({ meta, onPageChange }) {
    const page = meta.current_page || 1;
    const pageCount = meta.last_page || 1;
    const first = meta.from || 0;
    const last = meta.to || 0;
    const total = meta.total || 0;

    return (
        <div className="flex flex-col gap-3 border-t border-[var(--app-border)] px-3 py-3 text-sm text-[var(--app-muted)] sm:flex-row sm:items-center sm:justify-between">
            <div>
                {first} à {last} sur {total} livraison{total > 1 ? 's' : ''}
            </div>
            <div className="inline-flex items-center gap-2">
                <button
                    type="button"
                    disabled={page <= 1}
                    onClick={() => onPageChange(page - 1)}
                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] disabled:opacity-50"
                    aria-label="Page précédente"
                >
                    <ChevronLeft className="h-4 w-4" strokeWidth={2.2} />
                </button>
                <span className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-1.5 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                    Page {page} / {pageCount}
                </span>
                <button
                    type="button"
                    disabled={page >= pageCount}
                    onClick={() => onPageChange(page + 1)}
                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] disabled:opacity-50"
                    aria-label="Page suivante"
                >
                    <ChevronRight className="h-4 w-4" strokeWidth={2.2} />
                </button>
            </div>
        </div>
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

function FuelSettingsModal({ show, onClose, options, onOptionsChange }) {
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

    const createOption = async (kind, label) => {
        setError('');
        try {
            const data = await jsonRequest('/tasks/fuel/options', {
                method: 'POST',
                body: JSON.stringify({ kind, label }),
            });
            const option = data.option;
            const groupKey = kind === 'site' ? 'sites' : 'product_types';
            onOptionsChange((current) => ({
                ...current,
                [groupKey]: [...(current[groupKey] || []), option],
            }));
        } catch (caughtError) {
            setError(caughtError instanceof Error ? caughtError.message : 'Impossible d’ajouter la valeur.');
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

                <FuelOptionsPanel
                    title="Sites"
                    kind="site"
                    items={options.sites || []}
                    onCreate={createOption}
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

function FuelDeliveryModal({ show, onClose, onCreateDeliveries, options }) {
    const [tiersQuery, setTiersQuery] = useState('');
    const [tiersResults, setTiersResults] = useState([]);
    const [selectedTiers, setSelectedTiers] = useState(null);
    const [clientFields, setClientFields] = useState({ ...EMPTY_CLIENT_FIELDS });
    const [isSearching, setIsSearching] = useState(false);
    const [searchError, setSearchError] = useState('');
    const [deliveryFields, setDeliveryFields] = useState({ ...EMPTY_DELIVERY_FIELDS });
    const [orders, setOrders] = useState([{ ...EMPTY_ORDER }]);
    const [comment, setComment] = useState('');
    const [submitError, setSubmitError] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (!show) {
            return undefined;
        }

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

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const payload = await response.json();
                setTiersResults(Array.isArray(payload) ? payload : []);
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

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

    useEffect(() => {
        if (show) {
            return;
        }

        setTiersQuery('');
        setTiersResults([]);
        setSelectedTiers(null);
        setClientFields({ ...EMPTY_CLIENT_FIELDS });
        setIsSearching(false);
        setSearchError('');
        setSubmitError('');
        setIsSubmitting(false);
        setDeliveryFields({ ...EMPTY_DELIVERY_FIELDS });
        setOrders([{ ...EMPTY_ORDER }]);
        setComment('');
    }, [show]);

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
        setTiersQuery([tiers.code, tiers.name].filter(Boolean).join(' - '));
        setTiersResults([]);
    };

    const validOrders = orders.filter((order) => order.fuel_type || order.volume);
    const hasClientInfo = Object.values(clientFields).some((value) => String(value || '').trim() !== '');
    const canSubmit = hasClientInfo && validOrders.length > 0;

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
            setSubmitError(error instanceof Error ? error.message : 'Impossible d’ajouter la livraison carburant.');
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
                            autoFocus
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
                                    onClick={() => selectTiers(tiers)}
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

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                    <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">Informations client</h3>
                        {selectedTiers ? (
                            <span className="text-xs font-semibold text-[var(--app-muted)]">
                                Tiers sélectionné, champs modifiables
                            </span>
                        ) : null}
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
        </Modal>
    );
}

function FuelQuickEditCell({ row, type, value, displayValue, options = [], canUpdate, onUpdate }) {
    const [isEditing, setIsEditing] = useState(false);
    const [draft, setDraft] = useState(value || '');
    const [isSaving, setIsSaving] = useState(false);
    const initialValueRef = useRef(value || '');
    const skipCommitRef = useRef(false);

    useEffect(() => {
        if (!isEditing) {
            setDraft(value || '');
            initialValueRef.current = value || '';
        }
    }, [isEditing, value]);

    const commit = async () => {
        if (skipCommitRef.current) {
            skipCommitRef.current = false;
            return;
        }

        const nextValue = String(draft || '');
        setIsEditing(false);

        if (nextValue === initialValueRef.current) {
            return;
        }

        setIsSaving(true);
        try {
            await onUpdate(row.id, type === 'date' ? { delivery_date: nextValue || null } : { site: nextValue || null });
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Impossible de mettre à jour la livraison.');
            setDraft(initialValueRef.current);
        } finally {
            setIsSaving(false);
        }
    };

    const cancel = () => {
        skipCommitRef.current = true;
        setDraft(initialValueRef.current);
        setIsEditing(false);
    };

    if (isEditing) {
        return (
            <td className="relative px-3 py-3 text-center align-middle">
                {type === 'date' ? (
                    <input
                        type="date"
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        onBlur={commit}
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
                        onChange={(event) => setDraft(event.target.value)}
                        onBlur={commit}
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
    onPageChange,
    onQuickUpdate,
    onEditRow,
    onDeleteRow,
}) {
    const [copiedCodeId, setCopiedCodeId] = useState(null);
    const copiedTimerRef = useRef(null);

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
                <div className="overflow-x-auto rounded-xl border border-[var(--app-border)]">
                    <table className="min-w-[1260px] w-full border-collapse text-sm">
                        <thead>
                            <tr className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)] [&>th]:bg-[var(--app-surface-soft)] [&>th:first-child]:rounded-tl-xl">
                                {COLUMNS.map((column) => (
                                    <ColumnHeader
                                        key={column.key}
                                        column={column}
                                        sort={sort}
                                        filters={filters}
                                        siteOptions={siteOptions}
                                        productTypeOptions={productTypeOptions}
                                        onSort={onSort}
                                        onFilterChange={onFilterChange}
                                        onClearFilter={onClearFilter}
                                    />
                                ))}
                                <th className="relative rounded-tr-xl bg-[var(--app-surface-soft)] px-3 py-2 text-center">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
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
                                rows.map((row) => (
                                    <tr key={row.id} className="border-t border-[var(--app-border)] align-middle">
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
                                                            {row.urgent ? (
                                                                <span
                                                                    title="Urgent"
                                                                    className="inline-flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full border border-amber-500 bg-amber-100 text-amber-700"
                                                                >
                                                                    <AlertTriangle className="h-4 w-4" strokeWidth={2.2} />
                                                                </span>
                                                            ) : null}
                                                        </div>
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
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationFooter meta={meta} onPageChange={onPageChange} />
            </div>

            <div className="grid gap-4 lg:hidden">
                {rows.length === 0 ? (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)] shadow-sm">
                        Aucune livraison carburant.
                    </div>
                ) : (
                    rows.map((row) => (
                        <article key={row.id} className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
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
                                    {row.urgent ? (
                                        <span className="inline-flex items-center gap-1 rounded-full border border-amber-500 bg-amber-100 px-2 py-1 text-xs font-bold text-amber-700">
                                            <AlertTriangle className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            Urgent
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
                    ))
                )}
            </div>
            <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm lg:hidden">
                <PaginationFooter meta={meta} onPageChange={onPageChange} />
            </div>
        </section>
    );
}

export default function TaskFuelIndex({ permissions = {}, deliveries = [], options = { sites: [], product_types: [] }, query = {} }) {
    const canUpdate = Boolean(permissions.can_update);
    const canDelete = Boolean(permissions.can_delete);
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
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showSettingsModal, setShowSettingsModal] = useState(false);
    const [editingDelivery, setEditingDelivery] = useState(null);
    const [confirmDeleteRow, setConfirmDeleteRow] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const searchDebounceReadyRef = useRef(false);

    useEffect(() => {
        const nextDeliveries = normalizeDeliveryPayload(deliveries);
        setRows(nextDeliveries.rows);
        setPaginationMeta(nextDeliveries.meta);
    }, [deliveries]);

    useEffect(() => {
        setFuelOptions(options);
    }, [options]);

    const activeSiteOptions = useMemo(() => (fuelOptions.sites || []).filter((option) => option.active), [fuelOptions.sites]);
    const activeProductTypeOptions = useMemo(() => (fuelOptions.product_types || []).filter((option) => option.active), [fuelOptions.product_types]);
    const filtersActive = hasAnyFilter(filters);

    const loadFuelPage = (overrides = {}) => {
        router.get('/tasks/fuel', {
            search: overrides.search ?? search,
            filters: overrides.filters ?? filters,
            sort: overrides.sort ?? sort,
            page: overrides.page ?? 1,
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
            loadFuelPage({ search, page: 1 });
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
        loadFuelPage({ sort: nextSort, page: 1 });
    };

    const updateFilter = (key, value) => {
        const nextFilters = cloneFilters({ ...filters, [key]: value });
        setFilters(nextFilters);
        loadFuelPage({ filters: nextFilters, page: 1 });
    };

    const clearFilter = (key) => {
        updateFilter(key, EMPTY_FILTERS[key]);
    };

    const resetFilters = () => {
        const nextFilters = cloneFilters();
        setFilters(nextFilters);
        loadFuelPage({ filters: nextFilters, page: 1 });
    };

    const updateDelivery = async (id, payload) => {
        const data = await jsonRequest(`/tasks/fuel/${id}`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
        });

        if (data?.delivery) {
            setRows((current) => current.map((row) => (row.id === id ? data.delivery : row)));
            setEditingDelivery((current) => (current?.id === id ? data.delivery : current));
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
                total: Math.max(0, (current.total || 0) - 1),
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
        setRows((current) => [...newRows, ...current]);
        setPaginationMeta((current) => ({
            ...current,
            total: (current.total || 0) + newRows.length,
            to: Math.min((current.to || 0) + newRows.length, current.per_page || PAGE_SIZE),
        }));
    };

    const pageHeader = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h1 className="text-[22px] leading-none">
                <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">
                    CARBURANT
                </span>
            </h1>

            <div className="flex w-full flex-col gap-2 lg:w-auto lg:flex-row lg:items-center">
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
                onPageChange={(page) => loadFuelPage({ page })}
                onQuickUpdate={updateDelivery}
                onEditRow={setEditingDelivery}
                onDeleteRow={setConfirmDeleteRow}
            />

            <FuelSettingsModal
                show={showSettingsModal}
                onClose={() => setShowSettingsModal(false)}
                options={fuelOptions}
                onOptionsChange={setFuelOptions}
            />

            <FuelDeliveryModal
                show={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                onCreateDeliveries={createDeliveries}
                options={fuelOptions}
            />

            <FuelEditModal
                show={Boolean(editingDelivery)}
                delivery={editingDelivery}
                onClose={() => setEditingDelivery(null)}
                onSave={updateDelivery}
                options={fuelOptions}
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
