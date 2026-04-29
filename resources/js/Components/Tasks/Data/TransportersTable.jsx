import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

function normalizeString(value) {
    return String(value ?? '')
        .toLowerCase()
        .trim();
}

function displayName(transporter) {
    const first = String(transporter?.first_name ?? '').trim();
    const last = String(transporter?.last_name ?? '').trim();
    const full = `${first} ${last}`.trim();

    return full || transporter?.company_name || 'Transporteur';
}

const EMPTY_FORM = {
    first_name: '',
    last_name: '',
    phone: '',
    email: '',
    company_name: '',
    comment: '',
    display_order: '',
    is_active: true,
};

export default function TransportersTable({ transporters = [], canManage = false }) {
    const [search, setSearch] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingTransporter, setEditingTransporter] = useState(null);
    const [deleteCandidate, setDeleteCandidate] = useState(null);

    const form = useForm(EMPTY_FORM);

    const rows = useMemo(() => {
        const needle = normalizeString(search);

        if (!needle) {
            return transporters;
        }

        return transporters.filter((transporter) => {
            const haystack = [
                transporter.first_name,
                transporter.last_name,
                transporter.phone,
                transporter.email,
                transporter.company_name,
                transporter.comment,
            ]
                .map(normalizeString)
                .join(' ');

            return haystack.includes(needle);
        });
    }, [search, transporters]);

    const openCreate = () => {
        form.setData(EMPTY_FORM);
        form.clearErrors();
        setEditingTransporter(null);
        setIsModalOpen(true);
    };

    const openEdit = (transporter) => {
        form.setData({
            first_name: transporter.first_name ?? '',
            last_name: transporter.last_name ?? '',
            phone: transporter.phone ?? '',
            email: transporter.email ?? '',
            company_name: transporter.company_name ?? '',
            comment: transporter.comment ?? '',
            display_order:
                transporter.display_order === null || transporter.display_order === undefined
                    ? ''
                    : String(transporter.display_order),
            is_active: Boolean(transporter.is_active),
        });
        form.clearErrors();
        setEditingTransporter(transporter);
        setIsModalOpen(true);
    };

    const closeModal = () => {
        if (form.processing) {
            return;
        }

        setIsModalOpen(false);
        setEditingTransporter(null);
    };

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            display_order: data.display_order === '' ? null : Number(data.display_order),
        }));

        if (editingTransporter) {
            form.put(route('task.data.transporters.update', editingTransporter.id), {
                preserveScroll: true,
                onSuccess: closeModal,
            });
            return;
        }

        form.post(route('task.data.transporters.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    };

    const confirmDelete = () => {
        if (!deleteCandidate) {
            return;
        }

        router.delete(route('task.data.transporters.destroy', deleteCandidate.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteCandidate(null),
        });
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm">
            <div className="border-b border-[var(--app-border)] px-4 py-3 sm:px-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-[var(--app-text)]">Transporteurs</h2>
                        <p className="text-sm text-[var(--app-muted)]">Tri par ordre puis nom/entreprise.</p>
                    </div>

                    <div className="flex w-full flex-wrap items-center justify-end gap-2">
                        <div className="relative w-full max-w-sm">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                            <TextInput
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Rechercher un transporteur..."
                                className="w-full pl-9"
                            />
                        </div>

                        {canManage ? (
                            <button
                                type="button"
                                onClick={openCreate}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-sm font-semibold text-[var(--color-black)]"
                            >
                                <Plus className="h-4 w-4" strokeWidth={2.2} />
                                Ajouter
                            </button>
                        ) : null}
                    </div>
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-[920px] w-full divide-y divide-[var(--app-border)] text-sm">
                    <thead className="bg-[var(--app-surface-soft)]">
                        <tr className="text-left text-[var(--app-muted)]">
                            <th className="px-4 py-2 font-semibold">Ordre</th>
                            <th className="px-4 py-2 font-semibold">Nom / Prénom</th>
                            <th className="px-4 py-2 font-semibold">Entreprise</th>
                            <th className="px-4 py-2 font-semibold">Téléphone</th>
                            <th className="px-4 py-2 font-semibold">Email</th>
                            <th className="px-4 py-2 font-semibold">Commentaire</th>
                            <th className="px-4 py-2 font-semibold">Actif</th>
                            {canManage ? <th className="px-4 py-2 font-semibold text-right">Actions</th> : null}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--app-border)]">
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    className="px-4 py-6 text-center text-[var(--app-muted)]"
                                    colSpan={canManage ? 8 : 7}
                                >
                                    Aucun transporteur.
                                </td>
                            </tr>
                        ) : (
                            rows.map((transporter) => (
                                <tr key={transporter.id}>
                                    <td className="px-4 py-3 font-semibold">
                                        {transporter.display_order ?? <span className="text-[var(--app-muted)]">-</span>}
                                    </td>
                                    <td className="px-4 py-3 font-semibold">{displayName(transporter)}</td>
                                    <td className="px-4 py-3">{transporter.company_name || <span className="text-[var(--app-muted)]">-</span>}</td>
                                    <td className="px-4 py-3">
                                        {transporter.phone ? (
                                            <a href={`tel:${String(transporter.phone).replace(/\s+/g, '')}`} className="text-[var(--app-link)] underline decoration-dotted">
                                                {transporter.phone}
                                            </a>
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {transporter.email ? (
                                            <a href={`mailto:${transporter.email}`} className="text-[var(--app-link)] underline decoration-dotted">
                                                {transporter.email}
                                            </a>
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">{transporter.comment || <span className="text-[var(--app-muted)]">-</span>}</td>
                                    <td className="px-4 py-3">
                                        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${transporter.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>
                                            {transporter.is_active ? 'Oui' : 'Non'}
                                        </span>
                                    </td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(transporter)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold hover:bg-[var(--app-surface-soft)]"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    Éditer
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setDeleteCandidate(transporter)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    Supprimer
                                                </button>
                                            </div>
                                        </td>
                                    ) : null}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Modal show={isModalOpen} onClose={closeModal} maxWidth="2xl">
                <form onSubmit={submit} className="space-y-5 p-5">
                    <h3 className="text-lg font-semibold">
                        {editingTransporter ? 'Éditer le transporteur' : 'Ajouter un transporteur'}
                    </h3>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium">Prénom</label>
                            <TextInput
                                value={form.data.first_name}
                                onChange={(event) => form.setData('first_name', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.first_name} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Nom</label>
                            <TextInput
                                value={form.data.last_name}
                                onChange={(event) => form.setData('last_name', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.last_name} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium">Téléphone</label>
                            <TextInput
                                value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.phone} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Adresse mail</label>
                            <TextInput
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.email} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-[1fr_180px]">
                        <div>
                            <label className="mb-1 block text-sm font-medium">Nom d’entreprise</label>
                            <TextInput
                                value={form.data.company_name}
                                onChange={(event) => form.setData('company_name', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.company_name} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Ordre</label>
                            <TextInput
                                type="number"
                                min="0"
                                value={form.data.display_order}
                                onChange={(event) => form.setData('display_order', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.display_order} />
                        </div>
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium">Commentaire</label>
                        <textarea
                            rows={3}
                            value={form.data.comment}
                            onChange={(event) => form.setData('comment', event.target.value)}
                            className="w-full rounded-lg border-[var(--app-border)] bg-[var(--app-surface)] text-sm"
                        />
                        <InputError className="mt-2" message={form.errors.comment} />
                    </div>

                    <label className="inline-flex items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={Boolean(form.data.is_active)}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                            className="rounded border-[var(--app-border)]"
                        />
                        Actif
                    </label>

                    <div className="flex justify-end gap-2 border-t border-[var(--app-border)] pt-4">
                        <SecondaryButton type="button" onClick={closeModal}>
                            Annuler
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Enregistrer</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={Boolean(deleteCandidate)} onClose={() => setDeleteCandidate(null)} maxWidth="md">
                <div className="space-y-4 p-5">
                    <h3 className="text-lg font-semibold">Supprimer le transporteur</h3>
                    <p className="text-sm text-[var(--app-muted)]">
                        Confirmer la suppression de{' '}
                        <span className="font-semibold text-[var(--app-text)]">
                            {deleteCandidate ? displayName(deleteCandidate) : 'ce transporteur'}
                        </span>{' '}
                        ?
                    </p>
                    <div className="flex justify-end gap-2 border-t border-[var(--app-border)] pt-4">
                        <SecondaryButton type="button" onClick={() => setDeleteCandidate(null)}>
                            Annuler
                        </SecondaryButton>
                        <button
                            type="button"
                            onClick={confirmDelete}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700"
                        >
                            <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                            Supprimer
                        </button>
                    </div>
                </div>
            </Modal>
        </section>
    );
}

