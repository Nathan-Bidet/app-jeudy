import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import EntityFilesModal from '@/Pages/Admin/Entities/Components/EntityFilesModal';
import { Link, useForm } from '@inertiajs/react';
import { FileText, Pencil, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const EMPTY_FORM = {
    vehicle_mode: 'vehicle',
    vehicle_type_id: '',
    name: '',
    registration: '',
    code_zeendoc: '',
    driver_user_id: '',
    driver_carb_user_id: '',
    depot_id: '',
    is_rental: false,
    garage_id: '',
    tractor_vehicle_id: '',
    benne_ids: [],
    is_active: true,
};

function personLabel(user) {
    const base = user?.name || [user?.first_name, user?.last_name].filter(Boolean).join(' ') || `Utilisateur #${user?.id ?? ''}`;
    if (user?.sector_name) return `${base} (${user.sector_name})`;
    return base;
}

function vehicleRowLabel(item) {
    return item?.name || item?.registration || `Véhicule #${item?.id ?? ''}`;
}

function typeMatches(item, keyword) {
    const haystack = `${item?.vehicle_type_code || ''} ${item?.vehicle_type_label || ''}`.toLowerCase();
    return haystack.includes(keyword);
}

function isSemiRemorqueType(item) {
    return (
        typeMatches(item, 'semi-remorque')
        || typeMatches(item, 'semi remorque')
        || typeMatches(item, 'semi_remorque')
    );
}

function nameRegLabel(name, registration, fallback = '—') {
    const left = name || '—';
    const right = registration || '—';
    if (!name && !registration) return fallback;
    return `${left} • ${right}`;
}

function mapsLinkFromParts(parts) {
    const text = parts.filter(Boolean).join(', ');
    if (!text) return null;
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(text)}`;
}

function PreviewModal({ state, onClose }) {
    if (!state) return null;

    const { type, item } = state;
    const isUser = type === 'user';
    const addressUrl = item.gps_url || mapsLinkFromParts([
        item.address_line1,
        item.address_line2,
        item.postal_code,
        item.city,
        item.country,
    ]);

    return (
        <Modal show={Boolean(state)} onClose={onClose} maxWidth="2xl">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                    {isUser ? 'Fiche chauffeur' : type === 'depot' ? 'Fiche dépôt' : 'Fiche garage'}
                </h3>
            </div>

            <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4">
                <div>
                    <p className="text-base font-bold">
                        {item.name || [item.first_name, item.last_name].filter(Boolean).join(' ') || '—'}
                    </p>
                    <p className="mt-1 text-xs text-[var(--app-muted)]">
                        {isUser
                            ? (item.email || '—')
                            : [item.address_line1, item.address_line2, item.postal_code, item.city, item.country].filter(Boolean).join(' • ') || 'Adresse non renseignée'}
                    </p>
                </div>

                {isUser ? (
                    <>
                        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-sm">
                            <div>Email: {item.email ? <a href={`mailto:${item.email}`} className="underline decoration-dotted underline-offset-2">{item.email}</a> : '—'}</div>
                            <div>Téléphone: {item.phone ? <a href={`tel:${item.phone}`} className="underline decoration-dotted underline-offset-2">{item.phone}</a> : '—'}</div>
                            <div>Mobile: {item.mobile_phone ? <a href={`tel:${item.mobile_phone}`} className="underline decoration-dotted underline-offset-2">{item.mobile_phone}</a> : '—'}</div>
                            <div>Interne: {item.internal_number ? <a href={`tel:${item.internal_number}`} className="underline decoration-dotted underline-offset-2">{item.internal_number}</a> : '—'}</div>
                            <div>
                                Adresse dépôt:{' '}
                                {item.depot_address ? (
                                    <a
                                        href={mapsLinkFromParts([item.depot_address]) || '#'}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline decoration-dotted underline-offset-2"
                                    >
                                        {item.depot_address}
                                    </a>
                                ) : '—'}
                            </div>
                        </div>
                        <div className="flex justify-end">
                            <Link
                                href={route('directory.show', item.id)}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em]"
                            >
                                Ouvrir la fiche annuaire
                            </Link>
                        </div>
                    </>
                ) : (
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-sm">
                        <div>Téléphone: {item.phone ? <a href={`tel:${item.phone}`} className="underline decoration-dotted underline-offset-2">{item.phone}</a> : '—'}</div>
                        <div>Email: {item.email ? <a href={`mailto:${item.email}`} className="underline decoration-dotted underline-offset-2">{item.email}</a> : '—'}</div>
                        <div>
                            Adresse:{' '}
                            {addressUrl ? (
                                <a href={addressUrl} target="_blank" rel="noreferrer" className="underline decoration-dotted underline-offset-2">
                                    {[item.address_line1, item.address_line2, item.postal_code, item.city, item.country].filter(Boolean).join(' • ') || 'Adresse'}
                                </a>
                            ) : '—'}
                        </div>
                    </div>
                )}
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

function VehicleModal({
    open,
    onClose,
    mode,
    form,
    onSubmit,
    vehicleTypes,
    depots,
    garages,
    users,
    tractorCandidates,
    benneCandidates,
}) {
    const selectedDriver = users.find((u) => String(u.id) === String(form.data.driver_user_id || ''));
    const scopedSectorId = selectedDriver?.sector_id ?? null;

    const baseChauffeurUsers = useMemo(() => {
        const allowed = new Set(['chauffeur', 'chauffeur carb']);
        const filtered = users.filter((u) => allowed.has(String(u.sector_name || '').toLowerCase()));
        return filtered.length > 0 ? filtered : users;
    }, [users]);

    const scopedUsers = useMemo(() => {
        if (!scopedSectorId) return baseChauffeurUsers;
        return baseChauffeurUsers.filter((u) => String(u.sector_id ?? '') === String(scopedSectorId));
    }, [baseChauffeurUsers, scopedSectorId]);

    const selectableTypes = useMemo(
        () => vehicleTypes.filter((t) => String(t.code || '').toLowerCase() !== 'ensemble_pl'),
        [vehicleTypes],
    );

    const isVehicleMode = form.data.vehicle_mode === 'vehicle';

    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            <form onSubmit={onSubmit}>
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                            {mode === 'create' ? 'Ajouter un élément' : 'Modifier'}
                        </h3>

                        <div className="flex rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-1">
                            <button
                                type="button"
                                onClick={() => form.setData('vehicle_mode', 'vehicle')}
                                className={`rounded-lg px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] ${
                                    isVehicleMode
                                        ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                        : 'text-[var(--app-text)]'
                                }`}
                            >
                                Véhicule
                            </button>
                            <button
                                type="button"
                                onClick={() => form.setData('vehicle_mode', 'ensemble_pl')}
                                className={`rounded-lg px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] ${
                                    !isVehicleMode
                                        ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                        : 'text-[var(--app-text)]'
                                }`}
                            >
                                Ensemble PL
                            </button>
                        </div>
                    </div>
                    <InputError className="mt-2" message={form.errors.vehicle_mode} />
                </div>

                <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4 sm:grid-cols-2">
                    {isVehicleMode ? (
                        <div>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Type</label>
                            <select
                                value={form.data.vehicle_type_id}
                                onChange={(e) => form.setData('vehicle_type_id', e.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            >
                                <option value="">Sélectionner</option>
                                {selectableTypes.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <InputError className="mt-1" message={form.errors.vehicle_type_id} />
                        </div>
                    ) : (
                        <div>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Camion (type tracteur)</label>
                            <select
                                value={form.data.tractor_vehicle_id}
                                onChange={(e) => form.setData('tractor_vehicle_id', e.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            >
                                <option value="">Sélectionner</option>
                                {tractorCandidates.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {vehicleRowLabel(item)}{item.registration ? ` (${item.registration})` : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError className="mt-1" message={form.errors.tractor_vehicle_id} />
                        </div>
                    )}

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Nom</label>
                        <input
                            type="text"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                        <InputError className="mt-1" message={form.errors.name} />
                    </div>

                    {isVehicleMode ? (
                        <>
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Immatriculation</label>
                                <input
                                    type="text"
                                    value={form.data.registration}
                                    onChange={(e) => form.setData('registration', e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                />
                                <InputError className="mt-1" message={form.errors.registration} />
                            </div>
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Code Zeendoc</label>
                                <input
                                    type="text"
                                    value={form.data.code_zeendoc}
                                    onChange={(e) => form.setData('code_zeendoc', e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                />
                                <InputError className="mt-1" message={form.errors.code_zeendoc} />
                            </div>
                        </>
                    ) : (
                        <div className="sm:col-span-2">
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Semi-remorques (types semi-remorque)</label>
                            <select
                                multiple
                                value={(form.data.benne_ids || []).map(String)}
                                onChange={(e) =>
                                    form.setData(
                                        'benne_ids',
                                        Array.from(e.target.selectedOptions).map((opt) => opt.value),
                                    )
                                }
                                className="mt-1 min-h-28 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            >
                                {benneCandidates.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {vehicleRowLabel(item)}{item.registration ? ` (${item.registration})` : ''}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-1 text-[11px] text-[var(--app-muted)]">Maintenir Ctrl/Cmd pour multi-sélection.</p>
                            <InputError className="mt-1" message={form.errors.benne_ids || form.errors['benne_ids.0']} />
                        </div>
                    )}

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Chauffeur</label>
                        <select
                            value={form.data.driver_user_id}
                            onChange={(e) => form.setData('driver_user_id', e.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        >
                            <option value="">Aucun</option>
                            {scopedUsers.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {personLabel(user)}
                                </option>
                            ))}
                        </select>
                        <InputError className="mt-1" message={form.errors.driver_user_id} />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Dépôt</label>
                        <select
                            value={form.data.depot_id}
                            onChange={(e) => form.setData('depot_id', e.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        >
                            <option value="">Aucun</option>
                            {depots.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                        <InputError className="mt-1" message={form.errors.depot_id} />
                    </div>

                    {isVehicleMode ? (
                        <div className="space-y-3">
                            <label className="inline-flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.data.is_rental)}
                                    onChange={(e) => form.setData('is_rental', e.target.checked)}
                                />
                                <span>Location</span>
                            </label>

                            {form.data.is_rental ? (
                                <div>
                                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Garage</label>
                                    <select
                                        value={form.data.garage_id}
                                        onChange={(e) => form.setData('garage_id', e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                    >
                                        <option value="">Sélectionner</option>
                                        {garages.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError className="mt-1" message={form.errors.garage_id} />
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        <div className="text-xs text-[var(--app-muted)]">
                            Le type est automatiquement fixé à <span className="font-semibold">Ensemble PL</span>.
                        </div>
                    )}

                    <div className="flex items-end">
                        <label className="inline-flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                            <input
                                type="checkbox"
                                checked={Boolean(form.data.is_active)}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                            />
                            <span>Actif</span>
                        </label>
                    </div>
                </div>

                <div className="flex justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-60"
                    >
                        {form.processing ? (
                            'Enregistrement...'
                        ) : (
                            <span className="inline-flex items-center gap-1.5">
                                <Save className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>Enregistrer</span>
                            </span>
                        )}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function DeleteVehicleModal({ open, onClose, item, onConfirm, processing }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">Supprimer</h3>
            </div>
            <div className="bg-[var(--app-surface)] px-5 py-4">
                <p className="text-sm">Confirmer la suppression ?</p>
                <p className="mt-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold">
                    {vehicleRowLabel(item)}
                </p>
            </div>
            <div className="flex justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <button type="button" onClick={onClose} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]">Annuler</button>
                <button type="button" onClick={onConfirm} disabled={processing} className="rounded-xl border border-red-600 bg-red-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-60">
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

export default function VehiclesPanel({
    vehicles = [],
    vehicleTypes = [],
    depots = [],
    garages = [],
    users = [],
    initialDepotFilter = '',
}) {
    const [search, setSearch] = useState('');
    const [filterType, setFilterType] = useState('');
    const [filterDepot, setFilterDepot] = useState(initialDepotFilter || '');
    const [filterActive, setFilterActive] = useState('all');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [mode, setMode] = useState('create');
    const [editingItem, setEditingItem] = useState(null);
    const [deleteItem, setDeleteItem] = useState(null);
    const [previewState, setPreviewState] = useState(null);
    const [copiedZeendocId, setCopiedZeendocId] = useState(null);
    const [filesItem, setFilesItem] = useState(null);

    const form = useForm({ ...EMPTY_FORM });
    const destroyForm = useForm({});

    useEffect(() => {
        if (!editingItem) {
            form.setData({ ...EMPTY_FORM });
            form.clearErrors();
            return;
        }

        form.setData({
            vehicle_mode: editingItem.mode === 'ensemble_pl' ? 'ensemble_pl' : 'vehicle',
            vehicle_type_id: editingItem.mode === 'vehicle' && editingItem.vehicle_type_id ? String(editingItem.vehicle_type_id) : '',
            name: editingItem.name ?? '',
            registration: editingItem.registration ?? '',
            code_zeendoc: editingItem.code_zeendoc ?? '',
            driver_user_id: (editingItem.driver_user_id || editingItem.driver_carb_user_id) ? String(editingItem.driver_user_id || editingItem.driver_carb_user_id) : '',
            driver_carb_user_id: '',
            depot_id: editingItem.depot_id ? String(editingItem.depot_id) : '',
            is_rental: Boolean(editingItem.is_rental),
            garage_id: editingItem.garage_id ? String(editingItem.garage_id) : '',
            tractor_vehicle_id: editingItem.tractor_vehicle_id ? String(editingItem.tractor_vehicle_id) : '',
            benne_ids: (editingItem.benne_ids ?? []).map(String),
            is_active: Boolean(editingItem.is_active),
        });
        form.clearErrors();
    }, [editingItem]);

    useEffect(() => {
        if (initialDepotFilter) {
            setFilterDepot(String(initialDepotFilter));
        }
    }, [initialDepotFilter]);

    const usersById = useMemo(() => new Map(users.map((user) => [Number(user.id), user])), [users]);
    const depotsById = useMemo(() => new Map(depots.map((depot) => [Number(depot.id), depot])), [depots]);
    const garagesById = useMemo(() => new Map(garages.map((garage) => [Number(garage.id), garage])), [garages]);

    const filteredVehicles = useMemo(() => {
        const q = search.trim().toLowerCase();
        return vehicles.filter((item) => {
            if (filterType && String(item.vehicle_type_id) !== filterType) return false;
            if (filterDepot && String(item.depot_id ?? '') !== filterDepot) return false;
            if (filterActive === '1' && !item.is_active) return false;
            if (filterActive === '0' && item.is_active) return false;
            if (!q) return true;

            return [
                item.name,
                item.registration,
                item.code_zeendoc,
                item.vehicle_type_label,
                item.driver_name,
                item.driver_carb_name,
                item.depot_name,
                item.garage_name,
            ].some((v) => String(v || '').toLowerCase().includes(q));
        });
    }, [vehicles, search, filterType, filterDepot, filterActive]);

    const groupedVehicles = useMemo(() => {
        const groups = new Map();
        filteredVehicles.forEach((item) => {
            const label = item.vehicle_type_label || 'Sans type';
            if (!groups.has(label)) groups.set(label, []);
            groups.get(label).push(item);
        });
        return Array.from(groups.entries())
            .sort(([a], [b]) => a.localeCompare(b, 'fr'))
            .map(([label, rows]) => ({ label, rows }));
    }, [filteredVehicles]);

    const tractorCandidates = useMemo(() => {
        return vehicles.filter((item) => {
            if (item.mode === 'ensemble_pl') return false;
            if (editingItem && item.id === editingItem.id) return false;
            return typeMatches(item, 'tracteur');
        });
    }, [vehicles, editingItem]);

    const benneCandidates = useMemo(() => {
        return vehicles.filter((item) => {
            if (item.mode === 'ensemble_pl') return false;
            if (editingItem && item.id === editingItem.id) return false;
            return isSemiRemorqueType(item);
        });
    }, [vehicles, editingItem]);

    const openCreate = () => {
        setMode('create');
        setEditingItem(null);
        setIsModalOpen(true);
    };

    const openEdit = (item) => {
        setMode('edit');
        setEditingItem(item);
        setIsModalOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({
            vehicle_mode: data.vehicle_mode,
            vehicle_type_id: data.vehicle_mode === 'vehicle' && data.vehicle_type_id ? Number(data.vehicle_type_id) : null,
            name: data.name,
            registration: data.registration,
            code_zeendoc: data.code_zeendoc,
            driver_user_id: data.driver_user_id ? Number(data.driver_user_id) : null,
            driver_carb_user_id: null,
            depot_id: data.depot_id ? Number(data.depot_id) : null,
            is_rental: data.vehicle_mode === 'vehicle' ? Boolean(data.is_rental) : false,
            garage_id: data.vehicle_mode === 'vehicle' && data.is_rental && data.garage_id ? Number(data.garage_id) : null,
            tractor_vehicle_id: data.vehicle_mode === 'ensemble_pl' && data.tractor_vehicle_id ? Number(data.tractor_vehicle_id) : null,
            benne_ids: data.vehicle_mode === 'ensemble_pl' ? (data.benne_ids || []).map((id) => Number(id)) : [],
            is_active: Boolean(data.is_active),
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                setIsModalOpen(false);
                setEditingItem(null);
            },
            onFinish: () => form.transform((d) => d),
        };

        if (mode === 'create') {
            form.post(route('admin.entities.vehicles.store'), opts);
            return;
        }

        form.put(route('admin.entities.vehicles.update', editingItem.id), opts);
    };

    const confirmDelete = () => {
        if (!deleteItem) return;

        destroyForm.delete(route('admin.entities.vehicles.destroy', deleteItem.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteItem(null),
        });
    };

    const openUserPreview = (userId) => {
        const item = usersById.get(Number(userId));
        if (item) setPreviewState({ type: 'user', item });
    };

    const openDepotPreview = (depotId) => {
        const item = depotsById.get(Number(depotId));
        if (item) setPreviewState({ type: 'depot', item });
    };

    const openGaragePreview = (garageId) => {
        const item = garagesById.get(Number(garageId));
        if (item) setPreviewState({ type: 'garage', item });
    };

    const copyZeendoc = async (vehicleId, code) => {
        if (!code) return;

        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(code);
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = code;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'absolute';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }

            setCopiedZeendocId(vehicleId);
            window.setTimeout(() => {
                setCopiedZeendocId((current) => (current === vehicleId ? null : current));
            }, 1500);
        } catch {
            // No-op: fallback UX remains readable even if clipboard is blocked.
        }
    };

    return (
        <div className="space-y-4">
            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">Véhicules / Ensembles PL</h3>
                        <p className="mt-1 text-xs text-[var(--app-muted)]">
                            Une seule base, formulaire avec bascule en mode véhicule ou ensemble PL.
                        </p>
                    </div>
                    <button type="button" onClick={openCreate} className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]">
                        <span className="inline-flex items-center gap-1.5">
                            <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                            <span>Ajouter</span>
                        </span>
                    </button>
                </div>

                <div className="mt-4 grid gap-3 md:grid-cols-4">
                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Recherche" className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm" />
                    <select value={filterType} onChange={(e) => setFilterType(e.target.value)} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                        <option value="">Tous types</option>
                        {vehicleTypes.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                    <select value={filterDepot} onChange={(e) => setFilterDepot(e.target.value)} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                        <option value="">Tous dépôts</option>
                        {depots.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                    <select value={filterActive} onChange={(e) => setFilterActive(e.target.value)} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                        <option value="all">Tous</option>
                        <option value="1">Actifs</option>
                        <option value="0">Inactifs</option>
                    </select>
                </div>
            </section>

            <section className="space-y-4 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                {groupedVehicles.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-4 text-sm text-[var(--app-muted)]">Aucun élément.</p>
                ) : groupedVehicles.map((group) => (
                    <div key={group.label} className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <h4 className="text-xs font-black uppercase tracking-[0.12em] text-[var(--app-muted)]">{group.label}</h4>
                            <span className="text-xs text-[var(--app-muted)]">{group.rows.length}</span>
                        </div>
                        {group.rows.map((item) => (
                            <div key={item.id} className="grid gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3 md:grid-cols-[1.4fr_1fr_1fr_auto]">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-bold">{vehicleRowLabel(item)}</p>
                                    </div>
                                    {item.mode === 'vehicle' ? (
                                        <div className="mt-1 space-y-0.5 text-xs text-[var(--app-muted)]">
                                            <p className="truncate">
                                                Immatriculation : {item.registration || '—'}
                                            </p>
                                            <p className="truncate">
                                                Zeendoc :{' '}
                                                {item.code_zeendoc ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => copyZeendoc(item.id, item.code_zeendoc)}
                                                        className="underline decoration-dotted underline-offset-2"
                                                        title="Cliquer pour copier"
                                                    >
                                                        {item.code_zeendoc}
                                                    </button>
                                                ) : '—'}
                                                {copiedZeendocId === item.id ? (
                                                    <span className="ml-2 text-[10px] font-bold text-green-600">Copié</span>
                                                ) : null}
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="mt-1 space-y-0.5 text-xs text-[var(--app-muted)]">
                                            <p className="truncate">
                                                Camion : {nameRegLabel(item.tractor_name, item.tractor_registration)}
                                            </p>
                                            <p className="truncate">
                                                Bennes : {(item.bennes || []).length > 0
                                                    ? item.bennes
                                                        .map((benne) => nameRegLabel(benne.name, benne.registration, benne.label || '—'))
                                                        .join(' | ')
                                                    : '—'}
                                            </p>
                                        </div>
                                    )}
                                </div>

                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Chauffeur</p>
                                    {(item.driver_user_id || item.driver_carb_user_id) ? (
                                        <button
                                            type="button"
                                            onClick={() => openUserPreview(item.driver_user_id || item.driver_carb_user_id)}
                                            className="truncate text-left text-sm underline decoration-dotted underline-offset-2"
                                        >
                                            {item.driver_name || item.driver_carb_name || '—'}
                                        </button>
                                    ) : (
                                        <p className="truncate text-sm">—</p>
                                    )}
                                </div>

                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Dépôt / garage</p>
                                    {item.depot_id ? (
                                        <button
                                            type="button"
                                            onClick={() => openDepotPreview(item.depot_id)}
                                            className="text-sm underline decoration-dotted underline-offset-2"
                                        >
                                            {item.depot_name || '—'}
                                        </button>
                                    ) : (
                                        <p className="text-sm">—</p>
                                    )}
                                    <p className="truncate text-xs text-[var(--app-muted)]">
                                        {item.is_rental && item.garage_id ? (
                                            <>
                                                Location •{' '}
                                                <button
                                                    type="button"
                                                    onClick={() => openGaragePreview(item.garage_id)}
                                                    className="underline decoration-dotted underline-offset-2"
                                                >
                                                    {item.garage_name || 'Sans garage'}
                                                </button>
                                            </>
                                        ) : item.is_rental ? 'Location • Sans garage' : '—'}
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center justify-end gap-2">
                                    <span className={`rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] ${item.is_active ? 'border-green-300 bg-green-50 text-green-700' : 'border-red-300 bg-red-50 text-red-700'}`}>{item.is_active ? 'Actif' : 'Inactif'}</span>
                                    <button type="button" onClick={() => setFilesItem(item)} className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em]">
                                        <span className="inline-flex items-center gap-1">
                                            <FileText className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            <span>Docs ({item.files?.length ?? 0})</span>
                                        </span>
                                    </button>
                                    <button type="button" onClick={() => openEdit(item)} className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em]">
                                        <span className="inline-flex items-center gap-1">
                                            <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            <span>Éditer</span>
                                        </span>
                                    </button>
                                    <button type="button" onClick={() => setDeleteItem(item)} className="rounded-lg border border-red-600 bg-red-600 px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-white">
                                        <span className="inline-flex items-center gap-1">
                                            <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            <span>Supprimer</span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                ))}
            </section>

            <VehicleModal
                open={isModalOpen}
                onClose={() => !form.processing && setIsModalOpen(false)}
                mode={mode}
                form={form}
                onSubmit={submit}
                vehicleTypes={vehicleTypes}
                depots={depots}
                garages={garages}
                users={users}
                tractorCandidates={tractorCandidates}
                benneCandidates={benneCandidates}
            />
            <DeleteVehicleModal open={Boolean(deleteItem)} onClose={() => setDeleteItem(null)} item={deleteItem} onConfirm={confirmDelete} processing={destroyForm.processing} />
            <PreviewModal state={previewState} onClose={() => setPreviewState(null)} />
            <EntityFilesModal
                open={Boolean(filesItem)}
                onClose={() => setFilesItem(null)}
                title={filesItem ? vehicleRowLabel(filesItem) : ''}
                files={filesItem?.files ?? []}
                uploadUrl={filesItem?.files_routes?.upload ?? null}
            />
        </div>
    );
}
