import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import { useForm } from '@inertiajs/react';
import { Pencil, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function TypeModal({ open, onClose, mode, form, onSubmit }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            <form onSubmit={onSubmit}>
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        {mode === 'create' ? 'Ajouter un type véhicule' : 'Modifier le type véhicule'}
                    </h3>
                </div>

                <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4 sm:grid-cols-2">
                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Code
                        </label>
                        <input
                            type="text"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            placeholder="camion"
                        />
                        <InputError className="mt-1" message={form.errors.code} />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Libellé
                        </label>
                        <input
                            type="text"
                            value={form.data.label}
                            onChange={(e) => form.setData('label', e.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            placeholder="Camion"
                        />
                        <InputError className="mt-1" message={form.errors.label} />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Ordre
                        </label>
                        <input
                            type="number"
                            min="0"
                            value={form.data.sort_order}
                            onChange={(e) => form.setData('sort_order', e.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                        <InputError className="mt-1" message={form.errors.sort_order} />
                    </div>

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

                <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
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

function DeleteModal({ open, onClose, item, onConfirm, processing }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                    Supprimer le type véhicule
                </h3>
            </div>
            <div className="bg-[var(--app-surface)] px-5 py-4">
                <p className="text-sm text-[var(--app-text)]">
                    Confirmer la suppression de ce type ?
                </p>
                <p className="mt-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold">
                    {item?.label}
                </p>
                <p className="mt-2 text-xs text-[var(--app-muted)]">
                    Si des véhicules y sont rattachés, la suppression sera refusée.
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

export default function VehicleTypesPanel({ vehicleTypes = [] }) {
    const [search, setSearch] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [modalMode, setModalMode] = useState('create');
    const [editingItem, setEditingItem] = useState(null);
    const [deleteItem, setDeleteItem] = useState(null);

    const form = useForm({
        code: '',
        label: '',
        is_active: true,
        sort_order: 0,
    });

    const destroyForm = useForm({});

    useEffect(() => {
        if (!editingItem) {
            form.reset();
            form.clearErrors();
            form.setData({
                code: '',
                label: '',
                is_active: true,
                sort_order: 0,
            });
            return;
        }

        form.setData({
            code: editingItem.code ?? '',
            label: editingItem.label ?? '',
            is_active: Boolean(editingItem.is_active),
            sort_order: editingItem.sort_order ?? 0,
        });
        form.clearErrors();
    }, [editingItem]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return vehicleTypes;
        return vehicleTypes.filter((item) =>
            [item.code, item.label].some((value) => String(value || '').toLowerCase().includes(q)),
        );
    }, [search, vehicleTypes]);

    const openCreate = () => {
        setModalMode('create');
        setEditingItem(null);
        setIsModalOpen(true);
    };

    const openEdit = (item) => {
        setModalMode('edit');
        setEditingItem(item);
        setIsModalOpen(true);
    };

    const closeModal = () => {
        if (form.processing) return;
        setEditingItem(null);
        setIsModalOpen(false);
    };

    const submitModal = (e) => {
        e.preventDefault();

        const payload = {
            ...form.data,
            sort_order: Number(form.data.sort_order || 0),
            is_active: Boolean(form.data.is_active),
        };

        form.transform(() => payload);

        const done = () => {
            form.transform((data) => data);
            setEditingItem(null);
            setIsModalOpen(false);
        };

        if (modalMode === 'create') {
            form.post(route('admin.entities.vehicle-types.store'), {
                preserveScroll: true,
                onSuccess: done,
                onFinish: () => form.transform((data) => data),
            });
            return;
        }

        form.put(route('admin.entities.vehicle-types.update', editingItem.id), {
            preserveScroll: true,
            onSuccess: done,
            onFinish: () => form.transform((data) => data),
        });
    };

    const confirmDelete = () => {
        if (!deleteItem) return;
        destroyForm.delete(route('admin.entities.vehicle-types.destroy', deleteItem.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteItem(null),
        });
    };

    return (
        <div className="space-y-4">
            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                            Types véhicules
                        </h3>
                        <p className="mt-1 text-xs text-[var(--app-muted)]">
                            Tri par ordre, suppression interdite si utilisé.
                        </p>
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
                        Aucun type véhicule.
                    </p>
                ) : (
                    filtered.map((item) => (
                        <div
                            key={item.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="text-sm font-bold text-[var(--app-text)]">{item.label}</p>
                                    <span className="rounded-full border border-[var(--app-border)] px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em]">
                                        {item.code}
                                    </span>
                                    {!item.is_active ? (
                                        <span className="rounded-full border border-red-300 bg-red-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] text-red-700">
                                            Inactif
                                        </span>
                                    ) : null}
                                </div>
                                <p className="mt-1 text-xs text-[var(--app-muted)]">
                                    Ordre: {item.sort_order} • Véhicules: {item.vehicles_count}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
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

            <TypeModal
                open={isModalOpen}
                onClose={closeModal}
                mode={modalMode}
                form={form}
                onSubmit={submitModal}
            />

            <DeleteModal
                open={Boolean(deleteItem)}
                onClose={() => setDeleteItem(null)}
                item={deleteItem}
                onConfirm={confirmDelete}
                processing={destroyForm.processing}
            />
        </div>
    );
}
