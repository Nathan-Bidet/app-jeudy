import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import EntityFilesModal from '@/Pages/Admin/Entities/Components/EntityFilesModal';
import { useForm } from '@inertiajs/react';
import { FileText, Pencil, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function mapsFromGarage(item) {
    const text = [item?.address_line1, item?.address_line2, item?.postal_code, item?.city, item?.country]
        .filter(Boolean)
        .join(', ');
    if (!text) return null;
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(text)}`;
}

function GarageModal({ open, onClose, mode, form, onSubmit }) {
    const fields = [
        ['name', 'Nom du garage'],
        ['address_line1', 'Adresse 1'],
        ['address_line2', 'Adresse 2'],
        ['postal_code', 'Code postal'],
        ['city', 'Ville'],
        ['country', 'Pays'],
        ['phone', 'Téléphone'],
        ['email', 'Email'],
    ];

    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            <form onSubmit={onSubmit}>
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                        {mode === 'create' ? 'Ajouter un garage' : 'Modifier le garage'}
                    </h3>
                </div>

                <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4 sm:grid-cols-2">
                    {fields.map(([field, label]) => (
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

function DeleteGarageModal({ open, onClose, item, onConfirm, processing }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">Supprimer le garage</h3>
            </div>
            <div className="bg-[var(--app-surface)] px-5 py-4">
                <p className="text-sm">Confirmer la suppression de ce garage ?</p>
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

export default function GaragesPanel({ garages = [] }) {
    const [search, setSearch] = useState('');
    const [mode, setMode] = useState('create');
    const [editingItem, setEditingItem] = useState(null);
    const [deleteItem, setDeleteItem] = useState(null);
    const [open, setOpen] = useState(false);
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
            is_active: Boolean(editingItem.is_active),
        });
        form.clearErrors();
    }, [editingItem]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return garages;

        return garages.filter((item) =>
            [item.name, item.city, item.postal_code, item.phone, item.email]
                .some((v) => String(v || '').toLowerCase().includes(q)),
        );
    }, [garages, search]);

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({
            ...data,
            country: (data.country || 'FR').toUpperCase(),
            is_active: Boolean(data.is_active),
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setEditingItem(null);
            },
            onFinish: () => form.transform((data) => data),
        };

        if (mode === 'create') {
            form.post(route('admin.entities.garages.store'), opts);
            return;
        }

        form.put(route('admin.entities.garages.update', editingItem.id), opts);
    };

    const confirmDelete = () => {
        if (!deleteItem) return;

        destroyForm.delete(route('admin.entities.garages.destroy', deleteItem.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteItem(null),
        });
    };

    return (
        <div className="space-y-4">
            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">Garages</h3>
                        <p className="mt-1 text-xs text-[var(--app-muted)]">Base de contacts pour les véhicules en location.</p>
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
                            onClick={() => {
                                setMode('create');
                                setEditingItem(null);
                                setOpen(true);
                            }}
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
                        Aucun garage.
                    </p>
                ) : (
                    filtered.map((item) => (
                        <div key={item.id} className="grid gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3 md:grid-cols-[1.2fr_1fr_auto]">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-bold">{item.name}</p>
                                <p className="mt-1 truncate text-xs text-[var(--app-muted)]">
                                    {item.address_line1 || item.city ? (
                                        <a
                                            href={mapsFromGarage(item) || '#'}
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
                            </div>
                            <div>
                                <p className="text-sm">
                                    {item.phone ? (
                                        <a href={`tel:${item.phone}`} className="underline decoration-dotted underline-offset-2">
                                            {item.phone}
                                        </a>
                                    ) : '—'}
                                </p>
                                <p className="truncate text-xs text-[var(--app-muted)]">
                                    {item.email ? (
                                        <a href={`mailto:${item.email}`} className="underline decoration-dotted underline-offset-2">
                                            {item.email}
                                        </a>
                                    ) : '—'}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <span className={`rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] ${item.is_active ? 'border-green-300 bg-green-50 text-green-700' : 'border-red-300 bg-red-50 text-red-700'}`}>
                                    {item.is_active ? 'Actif' : 'Inactif'}
                                </span>
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
                                    onClick={() => {
                                        setMode('edit');
                                        setEditingItem(item);
                                        setOpen(true);
                                    }}
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

            <GarageModal open={open} onClose={() => !form.processing && setOpen(false)} mode={mode} form={form} onSubmit={submit} />
            <DeleteGarageModal open={Boolean(deleteItem)} onClose={() => setDeleteItem(null)} item={deleteItem} onConfirm={confirmDelete} processing={destroyForm.processing} />
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
