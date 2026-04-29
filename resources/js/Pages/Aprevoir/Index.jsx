import DesktopTable from '@/Components/Aprevoir/DesktopTable';
import FormattedText from '@/Components/FormattedText';
import GroupCard from '@/Components/Aprevoir/GroupCard';
import TaskModal from '@/Components/Aprevoir/TaskModal';
import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { ArrowUp, Filter, Plus, Search, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const EMPTY_TASK_FORM = {
    date: '',
    fin_date: '',
    assignee_type: '',
    assignee_id: '',
    assignee_label_free: '',
    vehicle_id: '',
    remorque_id: '',
    task: '',
    loading_place: '',
    delivery_place: '',
    comment: '',
    is_direct: false,
    is_boursagri: false,
    boursagri_contract_number: '',
    indicators: {},
};

const EMPTY_FILTER_STATE = {
    date_from: '',
    date_to: '',
    search: '',
    assignee_type: '',
    assignee_id: '',
    assignee_label_free: '',
    vehicle_id: '',
    only_boursagri: false,
    boursagri_contract_number: '',
    pointed_filter: 'unpointed',
    color_filter: 'all',
};

function buildFilterState(raw = {}) {
    return {
        ...EMPTY_FILTER_STATE,
        ...raw,
        assignee_id: String(raw?.assignee_id || ''),
        vehicle_id: String(raw?.vehicle_id || ''),
        only_boursagri: Boolean(raw?.only_boursagri),
        pointed_filter: raw?.pointed_filter || 'unpointed',
        color_filter: raw?.color_filter || 'all',
    };
}

function toBool(value) {
    return Boolean(value);
}

function toDayMonth(value) {
    if (!value) return '';
    const str = String(value);
    const isoMatch = str.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (isoMatch) {
        return `${isoMatch[3]}/${isoMatch[2]}`;
    }
    const dmMatch = str.match(/^(\d{2})\/(\d{2})$/);
    if (dmMatch) return str;
    return str;
}

function finDayMonthToIso(finValue, baseDate) {
    if (!finValue) return null;
    const raw = String(finValue).trim();
    if (!raw) return null;

    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        return raw;
    }

    const match = raw.match(/^(\d{2})\/(\d{2})$/);
    if (!match) return raw;

    const [, dd, mm] = match;
    const base = /^\d{4}-\d{2}-\d{2}$/.test(baseDate || '') ? new Date(`${baseDate}T00:00:00`) : null;
    const year = base ? base.getFullYear() : new Date().getFullYear();
    const candidate = new Date(year, Number(mm) - 1, Number(dd));

    if (
        Number.isNaN(candidate.getTime()) ||
        candidate.getMonth() !== Number(mm) - 1 ||
        candidate.getDate() !== Number(dd)
    ) {
        return raw;
    }

    if (base && candidate < base) {
        candidate.setFullYear(candidate.getFullYear() + 1);
    }

    const y = candidate.getFullYear();
    const m = String(candidate.getMonth() + 1).padStart(2, '0');
    const d = String(candidate.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function formatDateLabel(value) {
    if (!value) return '';
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('fr-FR', {
        weekday: 'long',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}

function normalizeTaskFormPayload(data) {
    const hasFreeLabel = Boolean((data.assignee_label_free || '').trim());
    const hasAssignee = Boolean(data.assignee_type) && Boolean(data.assignee_id);

    return {
        date: data.date || '',
        fin_date: finDayMonthToIso(data.fin_date, data.date),
        assignee_type: hasFreeLabel ? 'free' : (hasAssignee ? data.assignee_type : null),
        assignee_id: hasFreeLabel ? null : (hasAssignee ? Number(data.assignee_id) : null),
        assignee_label_free: hasFreeLabel ? String(data.assignee_label_free).trim() : null,
        vehicle_id: data.vehicle_id ? Number(data.vehicle_id) : null,
        remorque_id: data.remorque_id ? Number(data.remorque_id) : null,
        task: data.task || '',
        loading_place: data.loading_place || '',
        delivery_place: data.delivery_place || '',
        comment: data.comment || '',
        is_direct: toBool(data.is_direct),
        is_boursagri: toBool(data.is_boursagri),
        boursagri_contract_number: data.boursagri_contract_number || null,
        indicators: {},
    };
}

function buildAssigneeMetaFromPayload(payload, reference) {
    const type = payload.assignee_type;
    if (type === 'free') {
        const label = String(payload.assignee_label_free || '').trim();
        return {
            id: 0,
            type: 'free',
            name: label !== '' ? label : 'Chauffeur libre',
            display_order: null,
            phone: null,
            mobile_phone: null,
            email: null,
            internal_number: null,
            depot_address: null,
        };
    }

    if (!type || payload.assignee_id == null) {
        return {
            id: 0,
            type: 'none',
            name: 'Sans chauffeur',
            display_order: null,
            phone: null,
            mobile_phone: null,
            email: null,
            internal_number: null,
            depot_address: null,
        };
    }

    if (type === 'user') {
        const user = (reference?.assignee_users || []).find((item) => Number(item.id) === Number(payload.assignee_id));
        return {
            id: Number(payload.assignee_id),
            type,
            name: user?.name || `Utilisateur #${payload.assignee_id}`,
            display_order: null,
            phone: null,
            mobile_phone: null,
            email: null,
            internal_number: null,
            depot_address: null,
        };
    }

    if (type === 'transporter') {
        const transporter = (reference?.assignee_transporters || []).find((item) => Number(item.id) === Number(payload.assignee_id));
        const fullName = transporter ? `${transporter.first_name || ''} ${transporter.last_name || ''}`.trim() : '';
        const company = transporter ? String(transporter.company_name || '').trim() : '';
        let label = `Transporteur #${payload.assignee_id}`;
        if (fullName && company) {
            label = `${fullName} (${company})`;
        } else if (company) {
            label = company;
        } else if (fullName) {
            label = fullName;
        }
        return {
            id: Number(payload.assignee_id),
            type,
            name: label,
            display_order: transporter?.display_order ?? null,
            phone: null,
            mobile_phone: null,
            email: null,
            internal_number: null,
            depot_address: null,
        };
    }

    if (type === 'depot') {
        const depot = (reference?.assignee_depots || []).find((item) => Number(item.id) === Number(payload.assignee_id));
        return {
            id: Number(payload.assignee_id),
            type,
            name: depot?.name || `Dépôt #${payload.assignee_id}`,
            display_order: null,
            phone: null,
            mobile_phone: null,
            email: null,
            internal_number: null,
            depot_address: null,
        };
    }

    return {
        id: 0,
        type: 'none',
        name: 'Sans chauffeur',
        display_order: null,
        phone: null,
        mobile_phone: null,
        email: null,
        internal_number: null,
        depot_address: null,
    };
}

function buildGroupKeyFromPayload(payload) {
    const date = payload.date || '';
    if (payload.assignee_type === 'free') {
        return [date, 'free', String(payload.assignee_label_free || '')].join('|');
    }
    return [date, payload.assignee_type || 'none', payload.assignee_id ?? 'none'].join('|');
}

function lookupVehicle(reference, id) {
    if (!id) return null;
    const match = (reference?.vehicles || []).find((item) => Number(item.id) === Number(id));
    if (!match) return null;
    return {
        id: match.id,
        name: match.name,
        registration: match.registration,
    };
}

function lookupRemorque(reference, id) {
    if (!id) return null;
    const match = (reference?.remorques || []).find((item) => Number(item.id) === Number(id));
    if (!match) return null;
    return {
        id: match.id,
        name: match.name,
        registration: match.registration,
    };
}

function taskToForm(task, fallbackGroup) {
    const normalizedTaskAssigneeType = ['user', 'transporter', 'depot', 'free'].includes(task?.assignee_type) ? task.assignee_type : '';
    const normalizedFallbackAssigneeType = ['user', 'transporter', 'depot'].includes(fallbackGroup?.assignee?.type)
        ? fallbackGroup.assignee.type
        : '';

    if (!task) {
        return {
            ...EMPTY_TASK_FORM,
            date: fallbackGroup?.date || '',
            fin_date: '',
            assignee_type: normalizedFallbackAssigneeType,
            assignee_id: fallbackGroup?.assignee?.id ? String(fallbackGroup.assignee.id) : '',
            assignee_label_free: fallbackGroup?.assignee?.type === 'free' ? fallbackGroup.assignee.name : '',
        };
    }

    return {
        date: task.date || fallbackGroup?.date || '',
        fin_date: toDayMonth(task.fin_date || ''),
        assignee_type: normalizedTaskAssigneeType || normalizedFallbackAssigneeType,
        assignee_id: String(task.assignee_id || fallbackGroup?.assignee?.id || ''),
        assignee_label_free: task.assignee_type === 'free' ? (task.assignee_label_free || task.assignee_label || '') : '',
        vehicle_id: task.vehicle_id ? String(task.vehicle_id) : '',
        remorque_id: task.remorque_id ? String(task.remorque_id) : '',
        task: task.task || '',
        loading_place: task.loading_place || '',
        delivery_place: task.delivery_place || '',
        comment: task.comment || '',
        is_direct: Boolean(task.is_direct),
        is_boursagri: Boolean(task.is_boursagri),
        boursagri_contract_number: task.boursagri_contract_number || '',
        indicators: task.indicators || {},
    };
}

function buildFormTaskFromRow(row, group) {
    return {
        ...row,
        fin_date: toDayMonth(row?.fin_date || ''),
        assignee_type: ['user', 'transporter', 'depot', 'free'].includes(group?.assignee?.type) ? group.assignee.type : '',
        assignee_id: group?.assignee?.id,
        assignee_label_free: group?.assignee?.type === 'free' ? group.assignee.name : '',
        remorque_id: row?.remorque_id || '',
        loading_place: row?.loading_place || '',
        delivery_place: row?.delivery_place || '',
    };
}

function assigneeFilterOptions(reference) {
    const users = (reference?.assignee_users || []).map((item) => ({
        value: `user:${item.id}`,
        label: `${item.name} (Ets Jeudy)`,
    }));

    const transporters = (reference?.assignee_transporters || []).map((item) => {
        const fullName = `${item.first_name || ''} ${item.last_name || ''}`.trim();
        const company = String(item.company_name || '').trim();
        let label = `Transporteur #${item.id}`;

        if (fullName && company) {
            label = `${fullName} (${company})`;
        } else if (company) {
            label = company;
        } else if (fullName) {
            label = fullName;
        }

        return {
            value: `transporter:${item.id}`,
            label,
        };
    });

    const depots = (reference?.assignee_depots || []).map((item) => ({
        value: `depot:${item.id}`,
        label: item.name,
    }));

    return [...users, ...transporters, ...depots].sort((a, b) => a.label.localeCompare(b.label, 'fr'));
}

function DeleteTaskModal({ task, onClose, onConfirm, processing }) {
    return (
        <Modal show={Boolean(task)} onClose={onClose} maxWidth="md">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">Supprimer une tâche</h3>
            </div>
            <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4">
                <p className="text-sm">Confirmer la suppression ?</p>
                <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-sm">
                    <FormattedText as="p" className="font-semibold" text={task?.task || '—'} multiline />
                    {task?.comment ? (
                        <FormattedText as="p" className="mt-1 text-xs text-[var(--app-muted)]" text={task.comment} multiline />
                    ) : null}
                </div>
            </div>
            <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <button
                    type="button"
                    onClick={onClose}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] sm:w-auto"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={onConfirm}
                    disabled={processing}
                    className="w-full rounded-xl border border-red-600 bg-red-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-60 sm:w-auto"
                >
                    {processing ? (
                        'Suppression...'
                    ) : (
                        <span className="inline-flex items-center gap-1.5">
                            <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                            <span>Supprimer</span>
                        </span>
                    )}
                </button>
            </div>
        </Modal>
    );
}

function MobileFiltersModal({
    open,
    onClose,
    filters,
    setFilters,
    searchValue,
    onSearchChange,
    onApply,
    onReset,
    assigneeOptions,
    vehicles,
}) {
    const [showAssigneePicker, setShowAssigneePicker] = useState(false);
    const [assigneeSearch, setAssigneeSearch] = useState('');

    const selectedAssigneeValue =
        filters.assignee_type && filters.assignee_id ? `${filters.assignee_type}:${filters.assignee_id}` : '';

    const selectedAssigneeLabel = useMemo(() => {
        if (!selectedAssigneeValue) return 'Tous';
        return assigneeOptions.find((option) => option.value === selectedAssigneeValue)?.label || 'Tous';
    }, [assigneeOptions, selectedAssigneeValue]);

    const filteredAssigneeOptions = useMemo(() => {
        const needle = String(assigneeSearch || '')
            .toLocaleLowerCase('fr')
            .trim();

        if (!needle) return assigneeOptions;

        return assigneeOptions.filter((option) =>
            String(option.label || '')
                .toLocaleLowerCase('fr')
                .includes(needle),
        );
    }, [assigneeOptions, assigneeSearch]);

    const selectAssignee = (raw) => {
        const value = String(raw || '');
        if (!value) {
            setFilters((prev) => ({ ...prev, assignee_type: '', assignee_id: '' }));
            setShowAssigneePicker(false);
            return;
        }

        const [type, id] = value.split(':');
        setFilters((prev) => ({
            ...prev,
            assignee_type: ['user', 'transporter', 'depot'].includes(type) ? type : 'user',
            assignee_id: id || '',
        }));
        setShowAssigneePicker(false);
    };

    useEffect(() => {
        if (!open) {
            setShowAssigneePicker(false);
            setAssigneeSearch('');
        }
    }, [open]);

    if (!open) return null;

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">Filtres</h3>
            </div>

            <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Recherche
                    </label>
                    <input
                        type="text"
                        value={searchValue}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder="Tâche, commentaire, contrat, chauffeur, camion, date..."
                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                    />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:col-span-2">
                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Du</label>
                        <input
                            type="date"
                            value={filters.date_from}
                            onChange={(e) => setFilters((prev) => ({ ...prev, date_from: e.target.value }))}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Au</label>
                        <input
                            type="date"
                            value={filters.date_to}
                            onChange={(e) => setFilters((prev) => ({ ...prev, date_to: e.target.value }))}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                    </div>
                </div>

                <div className="sm:col-span-2">
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Chauffeur / transporteur / dépôt
                    </label>
                    <button
                        type="button"
                        onClick={() => setShowAssigneePicker((prev) => !prev)}
                        className="mt-1 flex w-full items-center justify-between rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-left text-sm"
                    >
                        <span className="truncate">{selectedAssigneeLabel}</span>
                        <span className="ml-2 text-xs text-[var(--app-muted)]">{showAssigneePicker ? '▲' : '▼'}</span>
                    </button>

                    {showAssigneePicker ? (
                        <div className="mt-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                                <input
                                    type="text"
                                    value={assigneeSearch}
                                    onChange={(e) => setAssigneeSearch(e.target.value)}
                                    placeholder="Rechercher chauffeur / transporteur / dépôt..."
                                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                                    autoFocus
                                />
                            </div>

                            <div className="mt-2 max-h-52 overflow-y-auto space-y-1">
                                <button
                                    type="button"
                                    onClick={() => selectAssignee('')}
                                    className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                        !selectedAssigneeValue
                                            ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                            : 'hover:bg-[var(--app-surface-soft)]'
                                    }`}
                                >
                                    <span>Tous</span>
                                </button>

                                {filteredAssigneeOptions.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => selectAssignee(option.value)}
                                        className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm ${
                                            selectedAssigneeValue === option.value
                                                ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                                : 'hover:bg-[var(--app-surface-soft)]'
                                        }`}
                                    >
                                        <span>{option.label}</span>
                                    </button>
                                ))}

                                {filteredAssigneeOptions.length === 0 ? (
                                    <div className="px-2.5 py-2 text-xs text-[var(--app-muted)]">Aucun résultat</div>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </div>

                <div>
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Véhicule
                    </label>
                    <select
                        value={filters.vehicle_id}
                        onChange={(e) => setFilters((prev) => ({ ...prev, vehicle_id: e.target.value }))}
                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                    >
                        <option value="">Tous</option>
                        {vehicles.map((vehicle) => (
                            <option key={vehicle.id} value={vehicle.id}>
                                {vehicle.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Pointé
                    </label>
                    <select
                        value={filters.pointed_filter}
                        onChange={(e) => setFilters((prev) => ({ ...prev, pointed_filter: e.target.value }))}
                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                    >
                        <option value="all">Tous</option>
                        <option value="pointed">Pointés</option>
                        <option value="unpointed">Non pointés</option>
                    </select>
                </div>

                <div>
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Couleur
                    </label>
                    <select
                        value={filters.color_filter}
                        onChange={(e) => setFilters((prev) => ({ ...prev, color_filter: e.target.value }))}
                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                    >
                        <option value="all">Toutes</option>
                        <option value="matched">Avec couleur</option>
                        <option value="unmatched">Sans couleur</option>
                    </select>
                </div>

                <div className="sm:col-span-2">
                    <label className="inline-flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                        <input
                            type="checkbox"
                            checked={Boolean(filters.only_boursagri)}
                            onChange={(e) => setFilters((prev) => ({ ...prev, only_boursagri: e.target.checked }))}
                        />
                        Boursagri uniquement
                    </label>
                </div>

                {filters.only_boursagri ? (
                    <div className="sm:col-span-2">
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Contrat Boursagri
                        </label>
                        <input
                            type="text"
                            value={filters.boursagri_contract_number}
                            onChange={(e) => setFilters((prev) => ({ ...prev, boursagri_contract_number: e.target.value }))}
                            placeholder="Numéro de contrat"
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                    </div>
                ) : null}
            </div>

            <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <button
                    type="button"
                    onClick={onClose}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] sm:w-auto"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={onReset}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] sm:w-auto"
                >
                    Réinitialiser
                </button>
                <button
                    type="button"
                    onClick={onApply}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] sm:w-auto"
                >
                    Appliquer
                </button>
            </div>
        </Modal>
    );
}

export default function AprevoirIndex({
    groups = [],
    meta = {},
    filters = {},
    reference = {},
    permissions = {},
    focus_task_id = null,
}) {
    const [localGroups, setLocalGroups] = useState(groups);
    const [modalOpen, setModalOpen] = useState(false);
    const [taskModalMode, setTaskModalMode] = useState('create');
    const [editingTaskId, setEditingTaskId] = useState(null);
    const [deleteTask, setDeleteTask] = useState(null);
    const [modalFallbackGroup, setModalFallbackGroup] = useState(null);
    const [modalTemplateTask, setModalTemplateTask] = useState(null);
    const [dragState, setDragState] = useState(null);
    const [dropPreview, setDropPreview] = useState(null);
    const [showFloatingActions, setShowFloatingActions] = useState(false);
    const [highlightedTaskId, setHighlightedTaskId] = useState(null);
    const [showMobileFilters, setShowMobileFilters] = useState(false);
    const [showQuickSearch, setShowQuickSearch] = useState(false);
    const [lastHandledFocusId, setLastHandledFocusId] = useState(null);
    const quickSearchPanelRef = useRef(null);
    const quickSearchButtonRef = useRef(null);
    const searchDebounceRef = useRef(null);
    const searchEffectReadyRef = useRef(false);
    const optimisticBackupRef = useRef(new Map());
    const pendingMovesRef = useRef(new Map());

    const [savingTaskIds, setSavingTaskIds] = useState({});
    const pendingMutationIdsRef = useRef(new Set());

    const [filterState, setFilterState] = useState(buildFilterState(filters));
    const [mobileFilterDraft, setMobileFilterDraft] = useState(buildFilterState(filters));

    const taskForm = useForm({ ...EMPTY_TASK_FORM });
    const deleteForm = useForm({});

    useEffect(() => {
        setLocalGroups(groups);
    }, [groups]);

    useEffect(() => {
        const onScroll = () => {
            setShowFloatingActions(window.scrollY > 180);
        };

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        if (!showQuickSearch) return undefined;

        const handlePointerDown = (event) => {
            const panel = quickSearchPanelRef.current;
            const button = quickSearchButtonRef.current;

            if (panel?.contains(event.target) || button?.contains(event.target)) {
                return;
            }

            setShowQuickSearch(false);
        };

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                setShowQuickSearch(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);
        document.addEventListener('keydown', handleEscape);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [showQuickSearch]);

    useEffect(() => {
        if (!searchEffectReadyRef.current) {
            searchEffectReadyRef.current = true;
            return undefined;
        }

        if (searchDebounceRef.current) {
            window.clearTimeout(searchDebounceRef.current);
        }

        searchDebounceRef.current = window.setTimeout(() => {
            submitFilters(filterState);
        }, 300);

        return () => {
            if (searchDebounceRef.current) {
                window.clearTimeout(searchDebounceRef.current);
            }
        };
    }, [filterState.search]);

    useEffect(() => {
        const targetId = Number(focus_task_id || 0);
        if (!targetId || Number(lastHandledFocusId || 0) === targetId) return;

        const node = document.querySelector(`[data-task-id="${targetId}"]`);
        if (!node) return;

        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setHighlightedTaskId(targetId);
        setLastHandledFocusId(targetId);

        if (typeof window !== 'undefined') {
            const nextUrl = new URL(window.location.href);
            nextUrl.searchParams.delete('focus_task_id');
            window.history.replaceState({}, '', nextUrl.toString());
        }

        const timeout = window.setTimeout(() => setHighlightedTaskId(null), 3600);
        return () => window.clearTimeout(timeout);
    }, [focus_task_id, localGroups, lastHandledFocusId]);

    useEffect(() => {
        if (typeof window === 'undefined' || !window.Echo) {
            return undefined;
        }

        const channel = window.Echo.channel('aprevoir.global');
        const onTaskUpdated = (event) => {
            const clientMutationId = event?.client_mutation_id;
            if (clientMutationId && pendingMutationIdsRef.current.has(clientMutationId)) {
                pendingMutationIdsRef.current.delete(clientMutationId);
                return;
            }
            const updatedId = Number(event?.task_id || 0);
            if (updatedId && savingTaskIds[updatedId]) {
                const action = String(event?.action || '');
                if (action !== 'deleted') {
                    setSavingTaskIds((prev) => {
                        const next = { ...prev };
                        delete next[updatedId];
                        return next;
                    });
                    return;
                }
            }

            router.reload({
                only: ['groups', 'meta'],
                preserveScroll: true,
                preserveState: true,
            });
        };

        channel.listen('.aprevoir.task.updated', onTaskUpdated);

        return () => {
            channel.stopListening('.aprevoir.task.updated');
        };
    }, [savingTaskIds]);

    const allTasksById = useMemo(() => {
        const map = new Map();
        localGroups.forEach((group) => {
            (group.tasks || []).forEach((task) => {
                map.set(task.id, buildFormTaskFromRow(task, group));
            });
        });
        return map;
    }, [localGroups]);

    const editingTask = editingTaskId ? allTasksById.get(editingTaskId) || null : null;

    useEffect(() => {
        if (!modalOpen) return;
        const next = modalTemplateTask
            ? taskToForm(modalTemplateTask, modalFallbackGroup)
            : taskToForm(editingTask, modalFallbackGroup);
        taskForm.setData(next);
        taskForm.clearErrors();
    }, [modalOpen, editingTaskId, editingTask, modalFallbackGroup, modalTemplateTask]);

    const mobileAssigneeOptions = useMemo(() => assigneeFilterOptions(reference), [reference]);

    const submitFilters = (nextState) => {
        const data = nextState ?? filterState;
        router.get(
            route('a_prevoir.index'),
            {
                date_from: data.date_from || undefined,
                date_to: data.date_to || undefined,
                search: data.search || undefined,
                assignee_type: data.assignee_type || undefined,
                assignee_id: data.assignee_id || undefined,
                vehicle_id: data.vehicle_id || undefined,
                only_boursagri: data.only_boursagri ? 1 : undefined,
                boursagri_contract_number: data.boursagri_contract_number || undefined,
                pointed_filter: data.pointed_filter !== 'all' ? data.pointed_filter : undefined,
                color_filter: data.color_filter !== 'all' ? data.color_filter : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const onFilterChange = (patch) => {
        setFilterState((prev) => {
            const next = { ...prev, ...patch };
            if (Object.prototype.hasOwnProperty.call(patch, 'assignee_type')) {
                next.assignee_id = '';
            }
            return next;
        });
    };

    const onSearchChange = (value) => {
        const nextValue = String(value ?? '');
        setFilterState((prev) => ({ ...prev, search: nextValue }));
        setMobileFilterDraft((prev) => ({ ...prev, search: nextValue }));
    };

    const onDesktopDateFiltersChange = (patch) => {
        setFilterState((prev) => ({ ...prev, ...patch }));
    };

    const applyDesktopDateFilters = (patch) => {
        setFilterState((prev) => {
            const next = { ...prev, ...patch };
            submitFilters(next);
            return next;
        });
    };

    const resetDesktopDateFilters = () => {
        setFilterState((prev) => {
            const next = { ...prev, date_from: '', date_to: '' };
            submitFilters(next);
            return next;
        });
    };

    const openMobileFilters = () => {
        setShowQuickSearch(false);
        setMobileFilterDraft({ ...filterState });
        setShowMobileFilters(true);
    };

    const closeMobileFilters = () => {
        setShowMobileFilters(false);
    };

    const applyMobileFilters = () => {
        const next = { ...mobileFilterDraft };
        setFilterState(next);
        submitFilters(next);
        setShowMobileFilters(false);
    };

    const resetMobileFilters = () => {
        const next = { ...EMPTY_FILTER_STATE };
        setMobileFilterDraft(next);
        setFilterState(next);
        submitFilters(next);
        setShowMobileFilters(false);
    };

    const openCreate = (group = null) => {
        setTaskModalMode('create');
        setEditingTaskId(null);
        setModalTemplateTask(null);
        setModalFallbackGroup(group ? { date: group.date, assignee: group.assignee } : null);
        setModalOpen(true);
    };

    const openEdit = (task) => {
        setTaskModalMode('edit');
        setEditingTaskId(task.id);
        setModalTemplateTask(null);
        setModalFallbackGroup(null);
        setModalOpen(true);
    };

    const openDuplicate = (task) => {
        setTaskModalMode('create');
        setEditingTaskId(null);
        setModalTemplateTask(task);
        setModalFallbackGroup(null);
        setModalOpen(true);
    };

    const closeTaskModal = () => {
        setModalOpen(false);
        setEditingTaskId(null);
        setModalTemplateTask(null);
        setModalFallbackGroup(null);
        taskForm.clearErrors();
    };

    const moveTaskToTarget = (prevGroups, taskId, payload) => {
        let nextGroups = prevGroups.map((group) => ({
            ...group,
            tasks: (group.tasks || []).map((task) => ({ ...task })),
            assignee: group.assignee ? { ...group.assignee } : group.assignee,
        }));

        let sourceGroupIndex = -1;
        let sourceTaskIndex = -1;
        nextGroups.forEach((group, gIndex) => {
            const idx = (group.tasks || []).findIndex((task) => Number(task.id) === Number(taskId));
            if (idx !== -1) {
                sourceGroupIndex = gIndex;
                sourceTaskIndex = idx;
            }
        });

        if (sourceGroupIndex === -1) {
            return prevGroups;
        }

        const sourceGroup = nextGroups[sourceGroupIndex];
        const existingTask = sourceGroup.tasks[sourceTaskIndex];
        const currentGroupDate = sourceGroup.date || existingTask.date || '';
        const currentAssigneeType =
            sourceGroup.assignee?.type === 'none' || !sourceGroup.assignee?.type
                ? null
                : sourceGroup.assignee.type;
        const currentAssigneeId =
            sourceGroup.assignee?.type && sourceGroup.assignee.type !== 'none'
                ? sourceGroup.assignee.id ?? null
                : null;
        const currentAssigneeLabelFree =
            sourceGroup.assignee?.type === 'free'
                ? String(sourceGroup.assignee?.name || existingTask.assignee_label_free || '').trim()
                : null;

        const rawDate = typeof payload.date === 'string' ? payload.date.trim() : '';
        const nextDate = rawDate !== '' ? rawDate : currentGroupDate;

        const rawAssigneeType =
            payload.assignee_type === '' || payload.assignee_type === undefined
                ? null
                : payload.assignee_type;
        const nextAssigneeType =
            rawAssigneeType !== null ? rawAssigneeType : currentAssigneeType;

        const rawAssigneeId =
            payload.assignee_id === '' || payload.assignee_id === undefined
                ? null
                : payload.assignee_id;
        const nextAssigneeId =
            nextAssigneeType === 'free'
                ? null
                : (rawAssigneeId !== null ? rawAssigneeId : currentAssigneeId ?? null);

        const nextAssigneeLabelFree =
            nextAssigneeType === 'free'
                ? String(payload.assignee_label_free || '').trim()
                : currentAssigneeLabelFree;

        const shouldMoveGroup = Boolean(
            nextDate !== currentGroupDate
            || nextAssigneeType !== currentAssigneeType
            || (nextAssigneeId ?? null) !== (currentAssigneeId ?? null)
            || (nextAssigneeLabelFree ?? null) !== (currentAssigneeLabelFree ?? null),
        );

        const updatedTask = { ...existingTask };

        const nextGroupKey = shouldMoveGroup
            ? buildGroupKeyFromPayload({
                ...payload,
                date: nextDate,
                assignee_type: nextAssigneeType,
                assignee_id: nextAssigneeId,
                assignee_label_free: nextAssigneeLabelFree,
            })
            : sourceGroup.key;
        const nextAssignee = shouldMoveGroup
            ? buildAssigneeMetaFromPayload(
                {
                    ...payload,
                    assignee_type: nextAssigneeType,
                    assignee_id: nextAssigneeId,
                    assignee_label_free: nextAssigneeLabelFree,
                },
                reference,
            )
            : sourceGroup.assignee;
        const nextDateLabel = shouldMoveGroup ? formatDateLabel(nextDate) : sourceGroup.date_label;

        if (sourceGroup.key === nextGroupKey) {
            sourceGroup.tasks[sourceTaskIndex] = updatedTask;
            if (shouldMoveGroup) {
                sourceGroup.assignee = nextAssignee;
                if (nextDateLabel) {
                    sourceGroup.date_label = nextDateLabel;
                }
                sourceGroup.date = nextDate || sourceGroup.date;
            }
            return nextGroups;
        }

        sourceGroup.tasks.splice(sourceTaskIndex, 1);
        if (sourceGroup.tasks.length === 0) {
            nextGroups.splice(sourceGroupIndex, 1);
        }

        const targetGroupIndex = nextGroups.findIndex((group) => group.key === nextGroupKey);
        if (targetGroupIndex !== -1) {
            const targetTasks = nextGroups[targetGroupIndex].tasks || [];
            const insertIndex = targetTasks.findIndex(
                (task) => (task.position ?? 0) > (updatedTask.position ?? 0),
            );
            if (insertIndex === -1) {
                targetTasks.push(updatedTask);
            } else {
                targetTasks.splice(insertIndex, 0, updatedTask);
            }
            nextGroups[targetGroupIndex].tasks = targetTasks;
        } else {
            nextGroups.push({
                key: nextGroupKey,
                date: nextDate,
                date_label: nextDateLabel || nextDate || '',
                assignee: nextAssignee,
                tasks: [updatedTask],
            });
        }

        return nextGroups;
    };

    const applyOptimisticUpdateInPlace = (prevGroups, taskId, payload) => {
        const nextGroups = prevGroups.map((group) => ({
            ...group,
            tasks: (group.tasks || []).map((task) => ({ ...task })),
            assignee: group.assignee ? { ...group.assignee } : group.assignee,
        }));

        let sourceGroupIndex = -1;
        let sourceTaskIndex = -1;
        nextGroups.forEach((group, gIndex) => {
            const idx = (group.tasks || []).findIndex((task) => Number(task.id) === Number(taskId));
            if (idx !== -1) {
                sourceGroupIndex = gIndex;
                sourceTaskIndex = idx;
            }
        });

        if (sourceGroupIndex === -1) {
            return nextGroups;
        }

        const sourceGroup = nextGroups[sourceGroupIndex];
        const existingTask = sourceGroup.tasks[sourceTaskIndex];
        const updatedTask = {
            ...existingTask,
            fin_date: payload.fin_date,
            fin_label: payload.fin_date ? toDayMonth(payload.fin_date) : existingTask.fin_label,
            task: payload.task,
            loading_place: payload.loading_place,
            delivery_place: payload.delivery_place,
            comment: payload.comment,
            is_direct: payload.is_direct,
            is_boursagri: payload.is_boursagri,
            boursagri_contract_number: payload.boursagri_contract_number,
            vehicle_id: payload.vehicle_id,
            remorque_id: payload.remorque_id,
            vehicle: lookupVehicle(reference, payload.vehicle_id),
            remorque: lookupRemorque(reference, payload.remorque_id),
        };

        sourceGroup.tasks[sourceTaskIndex] = updatedTask;
        return nextGroups;
    };

    const submitTask = (e) => {
        e.preventDefault();
        const optimisticPayload = normalizeTaskFormPayload(taskForm.data);
        taskForm.transform(() => optimisticPayload);
        const updateId = editingTaskId;
        const mode = taskModalMode;
        const mutationId =
            updateId && typeof crypto !== 'undefined' && crypto.randomUUID
                ? crypto.randomUUID()
                : updateId
                    ? `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
                    : null;

        if (mutationId) {
            optimisticPayload.client_mutation_id = mutationId;
        }

        const options = {
            preserveScroll: true,
            onError: () => {
                if (updateId) {
                    const backup = optimisticBackupRef.current.get(updateId);
                    if (backup) {
                        setLocalGroups(backup);
                    }
                }
                if (mutationId) {
                    pendingMutationIdsRef.current.delete(mutationId);
                }
                if (updateId) {
                    pendingMovesRef.current.delete(updateId);
                }
                window.dispatchEvent(
                    new CustomEvent('app:toast', {
                        detail: {
                            type: 'error',
                            message:
                                "Échec de l'enregistrement. La modification a été annulée.",
                        },
                    }),
                );
            },
            onSuccess: () => {
                if (updateId) {
                    optimisticBackupRef.current.delete(updateId);
                }
            },
            onFinish: () => taskForm.transform((d) => d),
        };

        closeTaskModal();

        if (mode === 'create') {
            taskForm.post(route('a_prevoir.tasks.store'), options);
            return;
        }

        if (updateId) {
            optimisticBackupRef.current.set(updateId, localGroups);
            setSavingTaskIds((prev) => ({ ...prev, [updateId]: true }));
            if (mutationId) {
                pendingMutationIdsRef.current.add(mutationId);
                window.setTimeout(() => {
                    pendingMutationIdsRef.current.delete(mutationId);
                }, 10000);
            }

            const currentGroup = localGroups.find((group) => (group.tasks || []).some((t) => Number(t.id) === Number(updateId)));
            const currentTask = currentGroup?.tasks?.find((t) => Number(t.id) === Number(updateId)) || null;
            const currentGroupDate = currentGroup?.date || currentTask?.date || '';
            const currentAssigneeType =
                currentGroup?.assignee?.type === 'none' || !currentGroup?.assignee?.type
                    ? null
                    : currentGroup.assignee.type;
            const currentAssigneeId =
                currentGroup?.assignee?.type && currentGroup.assignee.type !== 'none'
                    ? currentGroup.assignee.id ?? null
                    : null;
            const currentAssigneeLabelFree =
                currentGroup?.assignee?.type === 'free'
                    ? String(currentGroup.assignee?.name || currentTask?.assignee_label_free || '').trim()
                    : null;

            const rawDate = typeof optimisticPayload.date === 'string' ? optimisticPayload.date.trim() : '';
            const nextDate = rawDate !== '' ? rawDate : currentGroupDate;
            const rawAssigneeType =
                optimisticPayload.assignee_type === '' || optimisticPayload.assignee_type === undefined
                    ? null
                    : optimisticPayload.assignee_type;
            const nextAssigneeType =
                rawAssigneeType !== null ? rawAssigneeType : currentAssigneeType;
            const rawAssigneeId =
                optimisticPayload.assignee_id === '' || optimisticPayload.assignee_id === undefined
                    ? null
                    : optimisticPayload.assignee_id;
            const nextAssigneeId =
                nextAssigneeType === 'free'
                    ? null
                    : (rawAssigneeId !== null ? rawAssigneeId : currentAssigneeId ?? null);
            const nextAssigneeLabelFree =
                nextAssigneeType === 'free'
                    ? String(optimisticPayload.assignee_label_free || '').trim()
                    : currentAssigneeLabelFree;

            const shouldMoveGroup = Boolean(
                nextDate !== currentGroupDate
                || nextAssigneeType !== currentAssigneeType
                || (nextAssigneeId ?? null) !== (currentAssigneeId ?? null)
                || (nextAssigneeLabelFree ?? null) !== (currentAssigneeLabelFree ?? null),
            );

            if (shouldMoveGroup) {
                pendingMovesRef.current.set(updateId, {
                    payload: {
                        ...optimisticPayload,
                        date: nextDate,
                        assignee_type: nextAssigneeType,
                        assignee_id: nextAssigneeId,
                        assignee_label_free: nextAssigneeLabelFree,
                    },
                });
            }

            setLocalGroups((prev) => applyOptimisticUpdateInPlace(prev, updateId, optimisticPayload));
        }

        taskForm.put(route('a_prevoir.tasks.update', updateId), {
            ...options,
            onFinish: () => {
                taskForm.transform((d) => d);
                if (updateId) {
                    setSavingTaskIds((prev) => {
                        const next = { ...prev };
                        delete next[updateId];
                        return next;
                    });
                }
                if (updateId && pendingMovesRef.current.has(updateId)) {
                    const pending = pendingMovesRef.current.get(updateId);
                    pendingMovesRef.current.delete(updateId);
                    if (pending?.payload) {
                        setLocalGroups((prev) => moveTaskToTarget(prev, updateId, pending.payload));
                    }
                }
            },
        });
    };

    const confirmDelete = () => {
        if (!deleteTask) return;
        deleteForm.delete(route('a_prevoir.tasks.destroy', deleteTask.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteTask(null),
        });
    };

    const togglePoint = (task, pointed) => {
        router.patch(
            route('a_prevoir.tasks.point', task.id),
            {
                pointed: Boolean(pointed),
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const buildReorderedRows = (rows, draggedId, targetId, position = 'before') => {
        const from = rows.findIndex((row) => Number(row.id) === Number(draggedId));
        const to = rows.findIndex((row) => Number(row.id) === Number(targetId));
        if (from === -1 || to === -1 || draggedId === targetId) return rows;

        const nextRows = [...rows];
        const [moved] = nextRows.splice(from, 1);
        const targetIndex = nextRows.findIndex((row) => Number(row.id) === Number(targetId));
        if (targetIndex === -1) return rows;
        const insertIndex = position === 'after' ? targetIndex + 1 : targetIndex;
        nextRows.splice(insertIndex, 0, moved);
        return nextRows;
    };

    const reorderLocalGroup = (groupKey, draggedId, targetId, position = 'before') => {
        setLocalGroups((prev) =>
            prev.map((group) => {
                if (group.key !== groupKey) return group;

                const currentRows = group.tasks || [];
                const rows = buildReorderedRows(currentRows, draggedId, targetId, position);
                if (rows === currentRows) return group;

                return { ...group, tasks: rows };
            }),
        );
    };

    const onDragStartTask = (event, group, task) => {
        if (!permissions?.can_update) return;
        setDragState({
            groupKey: group.key,
            taskId: task.id,
            date: group.date,
            assigneeType: group.assignee?.type,
            assigneeId: group.assignee?.id,
        });
        setDropPreview(null);
        event.dataTransfer.effectAllowed = 'move';
    };

    const onDragEndTask = () => {
        setDropPreview(null);
        setDragState(null);
    };

    const onDragOverTask = (event, group, targetTask) => {
        if (!dragState) return;
        if (dragState.groupKey !== group.key) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const rect = event.currentTarget.getBoundingClientRect();
        const midpoint = rect.top + (rect.height / 2);
        const position = event.clientY >= midpoint ? 'after' : 'before';

        setDropPreview((prev) => {
            const next = {
                groupKey: group.key,
                targetTaskId: targetTask.id,
                position,
            };

            if (
                prev
                && prev.groupKey === next.groupKey
                && prev.targetTaskId === next.targetTaskId
                && prev.position === next.position
            ) {
                return prev;
            }

            return next;
        });
    };

    const onDropTask = (event, group, targetTask) => {
        event.preventDefault();
        if (!dragState) return;
        if (dragState.groupKey !== group.key) {
            setDragState(null);
            setDropPreview(null);
            return;
        }
        if (dragState.taskId === targetTask.id) {
            setDragState(null);
            setDropPreview(null);
            return;
        }

        const rect = event.currentTarget?.getBoundingClientRect?.();
        const fallbackPosition = rect && typeof event.clientY === 'number'
            ? (event.clientY >= rect.top + (rect.height / 2) ? 'after' : 'before')
            : 'before';
        const dropPosition = dropPreview
            && dropPreview.groupKey === group.key
            && Number(dropPreview.targetTaskId) === Number(targetTask.id)
            ? dropPreview.position
            : fallbackPosition;

        reorderLocalGroup(group.key, dragState.taskId, targetTask.id, dropPosition);

        const currentGroup = localGroups.find((g) => g.key === group.key) || group;
        const currentRows = currentGroup.tasks || [];
        const rows = buildReorderedRows(currentRows, dragState.taskId, targetTask.id, dropPosition);
        if (rows !== currentRows) {

            router.patch(
                route('a_prevoir.tasks.position', dragState.taskId),
                { ordered_ids: rows.map((row) => row.id) },
                { preserveScroll: true, preserveState: true },
            );
        }

        setDragState(null);
        setDropPreview(null);
    };

    const pageHeader = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h1 className="text-[22px] leading-none">
                <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">À Prévoir</span>
            </h1>

            <div className="flex w-full flex-col gap-2 lg:w-auto lg:flex-row lg:items-center">
                <div className="w-full lg:w-[420px]">
                    <label className="sr-only">Recherche globale</label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={filterState.search}
                            onChange={(e) => onSearchChange(e.target.value)}
                            placeholder="Tâche, commentaire, contrat, chauffeur, camion, date..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                        />
                    </div>
                </div>

                {permissions?.can_create ? (
                    <button
                        type="button"
                        onClick={() => openCreate(null)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] lg:shrink-0"
                    >
                        <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                        <span>Ajouter</span>
                    </button>
                ) : null}

                <button
                    type="button"
                    onClick={openMobileFilters}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] lg:hidden"
                >
                    <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                    <span>Filtres</span>
                </button>

            </div>
        </div>
    );

    return (
        <AppLayout title="À Prévoir" header={pageHeader}>
            <Head title="À Prévoir" />

            <div className="space-y-4 -mx-2.5 px-0.5 sm:mx-0 sm:px-0 sm:space-y-5">
                <DesktopTable
                    groups={localGroups}
                    depotPlaceMap={reference?.depot_place_map || {}}
                    highlightedTaskId={highlightedTaskId}
                    focusTaskId={focus_task_id}
                    savingTaskIds={savingTaskIds}
                    dateFrom={filterState.date_from}
                    dateTo={filterState.date_to}
                    canUpdate={Boolean(permissions?.can_update)}
                    canDelete={Boolean(permissions?.can_delete)}
                    canPoint={Boolean(permissions?.can_point)}
                    onEditTask={openEdit}
                    onDuplicateTask={openDuplicate}
                    onDeleteTask={setDeleteTask}
                    onTogglePoint={togglePoint}
                    onDragStartTask={onDragStartTask}
                    onDragEndTask={onDragEndTask}
                    onDragOverTask={onDragOverTask}
                    onDropTask={onDropTask}
                    dropPreview={dropPreview}
                    onDateFiltersChange={onDesktopDateFiltersChange}
                    onApplyDateFilters={applyDesktopDateFilters}
                    onResetDateFilters={resetDesktopDateFilters}
                />

                {localGroups.length === 0 ? (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)] lg:hidden">
                        Aucune tâche pour les filtres sélectionnés.
                    </div>
                ) : (
                    <div className="grid gap-4 lg:hidden">
                        {localGroups.map((group) => (
                            <GroupCard
                                key={group.key}
                                group={group}
                                depotPlaceMap={reference?.depot_place_map || {}}
                                highlightedTaskId={highlightedTaskId}
                                savingTaskIds={savingTaskIds}
                                canCreate={Boolean(permissions?.can_create)}
                                canUpdate={Boolean(permissions?.can_update)}
                                canDelete={Boolean(permissions?.can_delete)}
                                canPoint={Boolean(permissions?.can_point)}
                                onCreateInGroup={openCreate}
                                onEditTask={openEdit}
                                onDuplicateTask={openDuplicate}
                                onDeleteTask={setDeleteTask}
                                onTogglePoint={togglePoint}
                                onDragStartTask={onDragStartTask}
                                onDragOverTask={onDragOverTask}
                                onDropTask={onDropTask}
                            />
                        ))}
                    </div>
                )}
            </div>

            <TaskModal
                open={modalOpen}
                mode={taskModalMode}
                form={taskForm}
                reference={reference}
                onClose={closeTaskModal}
                onSubmit={submitTask}
            />

            <DeleteTaskModal
                task={deleteTask}
                onClose={() => setDeleteTask(null)}
                onConfirm={confirmDelete}
                processing={deleteForm.processing}
            />

            <MobileFiltersModal
                open={showMobileFilters}
                onClose={closeMobileFilters}
                filters={mobileFilterDraft}
                setFilters={setMobileFilterDraft}
                searchValue={filterState.search}
                onSearchChange={onSearchChange}
                onApply={applyMobileFilters}
                onReset={resetMobileFilters}
                assigneeOptions={mobileAssigneeOptions}
                vehicles={reference?.vehicles || []}
            />

            <div
                ref={quickSearchPanelRef}
                className={`fixed bottom-[calc(env(safe-area-inset-bottom)+8.4rem)] right-3 z-20 w-[min(calc(100vw-2rem),22rem)] rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-2xl transition-all duration-200 lg:hidden ${
                    showQuickSearch ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-2 opacity-0'
                }`}
                aria-hidden={!showQuickSearch}
            >
                <div className="space-y-2">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={filterState.search}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Recherche rapide..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                            autoFocus={showQuickSearch}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setShowQuickSearch(false)}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                        >
                            Fermer
                        </button>
                    </div>
                </div>
            </div>

            {showFloatingActions || showQuickSearch || showMobileFilters ? (
                <div className="fixed bottom-[calc(env(safe-area-inset-bottom)+5.1rem)] right-3 z-20 flex items-center gap-2 md:bottom-4 md:right-4">
                    {permissions?.can_create ? (
                        <button
                            type="button"
                            onClick={() => openCreate(null)}
                            className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-2.5 text-[11px] font-black uppercase tracking-[0.1em] text-[var(--color-black)] shadow-lg shadow-black/10"
                        >
                            <Plus className="h-3.5 w-3.5" strokeWidth={2.4} />
                            <span>Ajouter</span>
                        </button>
                    ) : null}

                    <button
                        ref={quickSearchButtonRef}
                        type="button"
                        onClick={() => setShowQuickSearch((current) => !current)}
                        className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 text-[11px] font-black uppercase tracking-[0.1em] shadow-lg shadow-black/10 lg:hidden"
                    >
                        <Search className="h-3.5 w-3.5" strokeWidth={2.4} />
                        <span>Recherche</span>
                    </button>

                    <button
                        type="button"
                        onClick={openMobileFilters}
                        className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 text-[11px] font-black uppercase tracking-[0.1em] shadow-lg shadow-black/10 lg:hidden"
                    >
                        <Filter className="h-3.5 w-3.5" strokeWidth={2.4} />
                        <span>Filtres</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] shadow-lg shadow-black/10"
                        title="Remonter en haut"
                        aria-label="Remonter en haut"
                    >
                        <ArrowUp className="h-4.5 w-4.5" strokeWidth={2.4} />
                    </button>
                </div>
            ) : null}
        </AppLayout>
    );
}
