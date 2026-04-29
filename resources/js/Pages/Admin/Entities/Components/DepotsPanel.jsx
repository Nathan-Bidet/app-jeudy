import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import EntityFilesModal from '@/Pages/Admin/Entities/Components/EntityFilesModal';
import { Link, useForm } from '@inertiajs/react';
import { FileText, MapPin, Pencil, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function mapsFromAddress(item) {
    const text = [item?.address_line1, item?.address_line2, item?.postal_code, item?.city, item?.country]
        .filter(Boolean)
        .join(', ');
    if (!text) return null;
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(text)}`;
}

function DepotModal({ open, onClose, mode, form, onSubmit }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            <form onSubmit={onSubmit}>
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                        {mode === 'create' ? 'Ajouter un dépôt' : 'Modifier le dépôt'}
                    </h3>
                </div>

                <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4 sm:grid-cols-2">
                    {[
                        ['name', 'Nom'],
                        ['address_line1', 'Adresse 1'],
                        ['address_line2', 'Adresse 2'],
                        ['postal_code', 'Code postal'],
                        ['city', 'Ville'],
                        ['country', 'Pays'],
                        ['phone', 'Téléphone'],
                        ['email', 'Email'],
                        ['gps_lat', 'GPS lat'],
                        ['gps_lng', 'GPS lng'],
                    ].map(([field, label]) => (
                        <div key={field}>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                {label}
                            </label>
                            <input
                                type={field === 'email' ? 'email' : 'text'}
                                value={form.data[field] ?? ''}
                                onChange={(e) => form.setData(field, e.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            />
                            <InputError className="mt-1" message={form.errors[field]} />
                        </div>
                    ))}

                    <div className="sm:col-span-2">
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

function DeleteDepotModal({ open, onClose, item, onConfirm, processing }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">Supprimer le dépôt</h3>
            </div>
            <div className="bg-[var(--app-surface)] px-5 py-4">
                <p className="text-sm">Confirmer la suppression de ce dépôt ?</p>
                <p className="mt-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold">
                    {item?.name}
                </p>
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
                    type="button"
                    onClick={onConfirm}
                    disabled={processing}
                    className="rounded-xl border border-red-600 bg-red-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-60"
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

export default function DepotsPanel({ depots = [] }) {
    const [search, setSearch] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [mode, setMode] = useState('create');
    const [editingItem, setEditingItem] = useState(null);
    const [deleteItem, setDeleteItem] = useState(null);
    const [filesItem, setFilesItem] = useState(null);

    const form = useForm({
        name: '',
        address_line1: '',
        address_line2: '',
        postal_code: '',
        city: '',
        country: 'FR',
        phone: '',
        email: '',
        gps_lat: '',
        gps_lng: '',
        is_active: true,
    });
    const destroyForm = useForm({});

    useEffect(() => {
        if (!editingItem) {
            form.setData({
                name: '',
                address_line1: '',
                address_line2: '',
                postal_code: '',
                city: '',
                country: 'FR',
                phone: '',
                email: '',
                gps_lat: '',
                gps_lng: '',
                is_active: true,
            });
            form.clearErrors();
            return;
        }

        form.setData({
            name: editingItem.name ?? '',
            address_line1: editingItem.address_line1 ?? '',
            address_line2: editingItem.address_line2 ?? '',
            postal_code: editingItem.postal_code ?? '',
            city: editingItem.city ?? '',
            country: editingItem.country ?? 'FR',
            phone: editingItem.phone ?? '',
            email: editingItem.email ?? '',
            gps_lat: editingItem.gps_lat ?? '',
            gps_lng: editingItem.gps_lng ?? '',
            is_active: Boolean(editingItem.is_active),
        });
        form.clearErrors();
    }, [editingItem]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return depots;
        return depots.filter((item) =>
            [item.name, item.city, item.postal_code, item.phone, item.email]
                .some((v) => String(v || '').toLowerCase().includes(q)),
        );
    }, [depots, search]);

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

        const payload = {
            ...form.data,
            country: (form.data.country || 'FR').toUpperCase(),
            is_active: Boolean(form.data.is_active),
        };

        form.transform(() => payload);

        const request =
            mode === 'create'
                ? () =>
                      form.post(route('admin.entities.depots.store'), {
                          preserveScroll: true,
                          onSuccess: () => {
                              setIsModalOpen(false);
                              setEditingItem(null);
                          },
                          onFinish: () => form.transform((data) => data),
                      })
                : () =>
                      form.put(route('admin.entities.depots.update', editingItem.id), {
                          preserveScroll: true,
                          onSuccess: () => {
                              setIsModalOpen(false);
                              setEditingItem(null);
                          },
                          onFinish: () => form.transform((data) => data),
                      });

        request();
    };

    const confirmDelete = () => {
        if (!deleteItem) return;
        destroyForm.delete(route('admin.entities.depots.destroy', deleteItem.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteItem(null),
        });
    };

    return (
        <div className="space-y-4">
            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">Dépôts</h3>
                        <p className="mt-1 text-xs text-[var(--app-muted)]">Gestion des lieux / dépôts.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Rechercher"
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                        <button
                            type="button"
                            onClick={openCreate}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                        >
                            <span className="inline-flex items-center gap-1.5">
                                <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>Ajouter</span>
                            </span>
                        </button>
                    </div>
                </div>
            </section>

            <section className="space-y-2 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                {filtered.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-4 text-sm text-[var(--app-muted)]">
                        Aucun dépôt.
                    </p>
                ) : (
                    filtered.map((item) => (
                        <div
                            key={item.id}
                            className="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="text-sm font-bold">{item.name}</p>
                                    {!item.is_active ? (
                                        <span className="rounded-full border border-red-300 bg-red-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] text-red-700">
                                            Inactif
                                        </span>
                                    ) : null}
                                </div>
                                <p className="mt-1 text-xs text-[var(--app-muted)]">
                                    {item.address_line1 || item.city ? (
                                        <a
                                            href={item.gps_url || mapsFromAddress(item) || '#'}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline decoration-dotted underline-offset-2"
                                        >
                                            {[item.address_line1, item.address_line2, item.postal_code, item.city, item.country]
                                                .filter(Boolean)
                                                .join(' • ')}
                                        </a>
                                    ) : 'Adresse non renseignée'}
                                </p>
                                <p className="mt-1 text-xs text-[var(--app-muted)]">
                                    {item.phone ? (
                                        <a href={`tel:${item.phone}`} className="underline decoration-dotted underline-offset-2">
                                            {item.phone}
                                        </a>
                                    ) : 'Sans téléphone'}{' '}
                                    •{' '}
                                    {item.email ? (
                                        <a href={`mailto:${item.email}`} className="underline decoration-dotted underline-offset-2">
                                            {item.email}
                                        </a>
                                    ) : 'Sans email'}{' '}
                                    •{' '}
                                    <Link
                                        href={route('admin.entities', { tab: 'vehicles', depot_id: item.id })}
                                        className="underline decoration-dotted underline-offset-2"
                                    >
                                        Véhicules: {item.vehicles_count}
                                    </Link>
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {item.gps_url ? (
                                    <a
                                        href={item.gps_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em]"
                                    >
                                        <span className="inline-flex items-center gap-1">
                                            <MapPin className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            <span>GPS</span>
                                        </span>
                                    </a>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => setFilesItem(item)}
                                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em]"
                                >
                                    <span className="inline-flex items-center gap-1">
                                        <FileText className="h-3.5 w-3.5" strokeWidth={2.2} />
                                        <span>Docs ({item.files?.length ?? 0})</span>
                                    </span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => openEdit(item)}
                                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em]"
                                >
                                    <span className="inline-flex items-center gap-1">
                                        <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                        <span>Éditer</span>
                                    </span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setDeleteItem(item)}
                                    className="rounded-lg border border-red-600 bg-red-600 px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-white"
                                >
                                    <span className="inline-flex items-center gap-1">
                                        <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                        <span>Supprimer</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    ))
                )}
            </section>

            <DepotModal
                open={isModalOpen}
                onClose={() => !form.processing && setIsModalOpen(false)}
                mode={mode}
                form={form}
                onSubmit={submit}
            />

            <DeleteDepotModal
                open={Boolean(deleteItem)}
                onClose={() => setDeleteItem(null)}
                item={deleteItem}
                onConfirm={confirmDelete}
                processing={destroyForm.processing}
            />

            <EntityFilesModal
                open={Boolean(filesItem)}
                onClose={() => setFilesItem(null)}
                title={filesItem?.name}
                files={filesItem?.files ?? []}
                uploadUrl={filesItem?.files_routes?.upload ?? null}
            />
        </div>
    );
}
