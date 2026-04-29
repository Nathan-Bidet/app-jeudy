import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { router, useForm } from '@inertiajs/react';
import { Mail, MapPin, Pencil, Phone, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

function normalizeString(value) {
    return String(value ?? '')
        .toLowerCase()
        .trim();
}

function addressLabel(depot) {
    return [
        depot.address_line1,
        depot.address_line2,
        [depot.postal_code, depot.city].filter(Boolean).join(' '),
        depot.country,
    ]
        .map((chunk) => String(chunk ?? '').trim())
        .filter(Boolean)
        .join(', ');
}

const EMPTY_FORM = {
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
};

export default function DepotsTable({ depots = [], canManage = false }) {
    const [search, setSearch] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingDepot, setEditingDepot] = useState(null);
    const [deleteCandidate, setDeleteCandidate] = useState(null);

    const form = useForm(EMPTY_FORM);

    const rows = useMemo(() => {
        const needle = normalizeString(search);

        if (!needle) {
            return depots;
        }

        return depots.filter((depot) => {
            const haystack = [
                depot.name,
                depot.address_line1,
                depot.address_line2,
                depot.postal_code,
                depot.city,
                depot.country,
                depot.phone,
                depot.email,
            ]
                .map(normalizeString)
                .join(' ');

            return haystack.includes(needle);
        });
    }, [search, depots]);

    const openCreate = () => {
        form.setData(EMPTY_FORM);
        form.clearErrors();
        setEditingDepot(null);
        setIsModalOpen(true);
    };

    const openEdit = (depot) => {
        form.setData({
            name: depot.name ?? '',
            address_line1: depot.address_line1 ?? '',
            address_line2: depot.address_line2 ?? '',
            postal_code: depot.postal_code ?? '',
            city: depot.city ?? '',
            country: depot.country ?? 'FR',
            phone: depot.phone ?? '',
            email: depot.email ?? '',
            gps_lat: depot.gps_lat ?? '',
            gps_lng: depot.gps_lng ?? '',
            is_active: Boolean(depot.is_active),
        });
        form.clearErrors();
        setEditingDepot(depot);
        setIsModalOpen(true);
    };

    const closeModal = () => {
        if (form.processing) {
            return;
        }

        setIsModalOpen(false);
        setEditingDepot(null);
    };

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            gps_lat: data.gps_lat === '' ? null : Number(data.gps_lat),
            gps_lng: data.gps_lng === '' ? null : Number(data.gps_lng),
        }));

        if (editingDepot) {
            form.put(route('task.data.depots.update', editingDepot.id), {
                preserveScroll: true,
                onSuccess: closeModal,
            });
            return;
        }

        form.post(route('task.data.depots.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    };

    const confirmDelete = () => {
        if (!deleteCandidate) {
            return;
        }

        router.delete(route('task.data.depots.destroy', deleteCandidate.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteCandidate(null),
        });
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm">
            <div className="border-b border-[var(--app-border)] px-4 py-3 sm:px-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-[var(--app-text)]">Dépôts</h2>
                        <p className="text-sm text-[var(--app-muted)]">Référentiel partagé pour À Prévoir et Livre du Travail.</p>
                    </div>

                    <div className="flex w-full flex-wrap items-center justify-end gap-2">
                        <div className="relative w-full max-w-sm">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                            <TextInput
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Rechercher un dépôt..."
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
                <table className="min-w-[980px] w-full divide-y divide-[var(--app-border)] text-sm">
                    <thead className="bg-[var(--app-surface-soft)]">
                        <tr className="text-left text-[var(--app-muted)]">
                            <th className="px-4 py-2 font-semibold">Nom</th>
                            <th className="px-4 py-2 font-semibold">Adresse postale</th>
                            <th className="px-4 py-2 font-semibold">Téléphone</th>
                            <th className="px-4 py-2 font-semibold">Email</th>
                            <th className="px-4 py-2 font-semibold text-center">Actif</th>
                            <th className="px-4 py-2 font-semibold text-center">Dépendances</th>
                            {canManage ? <th className="px-4 py-2 font-semibold text-right">Actions</th> : null}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--app-border)]">
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={canManage ? 7 : 6}
                                    className="px-4 py-6 text-center text-[var(--app-muted)]"
                                >
                                    Aucun dépôt.
                                </td>
                            </tr>
                        ) : (
                            rows.map((depot) => (
                                <tr key={depot.id}>
                                    <td className="px-4 py-3 font-semibold">{depot.name}</td>
                                    <td className="px-4 py-3">
                                        {addressLabel(depot) ? (
                                            <a
                                                href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(addressLabel(depot))}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-[var(--app-link)] underline decoration-dotted"
                                            >
                                                <MapPin className="h-3.5 w-3.5" />
                                                {addressLabel(depot)}
                                            </a>
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {depot.phone ? (
                                            <a href={`tel:${String(depot.phone).replace(/\s+/g, '')}`} className="inline-flex items-center gap-1 text-[var(--app-link)] underline decoration-dotted">
                                                <Phone className="h-3.5 w-3.5" />
                                                {depot.phone}
                                            </a>
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {depot.email ? (
                                            <a href={`mailto:${depot.email}`} className="inline-flex items-center gap-1 text-[var(--app-link)] underline decoration-dotted">
                                                <Mail className="h-3.5 w-3.5" />
                                                {depot.email}
                                            </a>
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${depot.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>
                                            {depot.is_active ? 'Oui' : 'Non'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center text-xs text-[var(--app-muted)]">
                                        Véhicules: {depot.vehicles_count} • Utilisateurs: {depot.users_count}
                                    </td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(depot)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold hover:bg-[var(--app-surface-soft)]"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    Éditer
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setDeleteCandidate(depot)}
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
                        {editingDepot ? 'Éditer le dépôt' : 'Ajouter un dépôt'}
                    </h3>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium">Nom</label>
                            <TextInput
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.name} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Adresse ligne 1</label>
                            <TextInput
                                value={form.data.address_line1}
                                onChange={(event) => form.setData('address_line1', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.address_line1} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Adresse ligne 2</label>
                            <TextInput
                                value={form.data.address_line2}
                                onChange={(event) => form.setData('address_line2', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.address_line2} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Code postal</label>
                            <TextInput
                                value={form.data.postal_code}
                                onChange={(event) => form.setData('postal_code', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.postal_code} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Ville</label>
                            <TextInput
                                value={form.data.city}
                                onChange={(event) => form.setData('city', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.city} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Pays (ISO)</label>
                            <TextInput
                                value={form.data.country}
                                onChange={(event) => form.setData('country', event.target.value)}
                                className="w-full sm:w-24"
                            />
                            <InputError className="mt-2" message={form.errors.country} />
                        </div>
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
                            <label className="mb-1 block text-sm font-medium">Email</label>
                            <TextInput
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.email} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Latitude GPS</label>
                            <TextInput
                                value={form.data.gps_lat}
                                onChange={(event) => form.setData('gps_lat', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.gps_lat} />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Longitude GPS</label>
                            <TextInput
                                value={form.data.gps_lng}
                                onChange={(event) => form.setData('gps_lng', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.gps_lng} />
                        </div>
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
                    <h3 className="text-lg font-semibold">Supprimer le dépôt</h3>
                    <p className="text-sm text-[var(--app-muted)]">
                        Confirmer la suppression de{' '}
                        <span className="font-semibold text-[var(--app-text)]">
                            {deleteCandidate?.name}
                        </span>{' '}
                        ?
                    </p>
                    <p className="text-xs text-[var(--app-muted)]">
                        La suppression est refusée si ce dépôt est encore lié à des véhicules ou utilisateurs.
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

