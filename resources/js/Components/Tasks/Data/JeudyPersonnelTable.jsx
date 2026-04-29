import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, Pencil, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

function formatDate(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(`${value}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('fr-FR').format(date);
}

function validationCell(value) {
    if (!value) {
        return <span className="text-[var(--app-muted)]">-</span>;
    }

    return (
        <span className="inline-flex flex-col items-center text-xs">
            <CheckCircle2 className="h-4 w-4 text-emerald-600" strokeWidth={2.2} />
            <span className="mt-1 font-semibold text-emerald-700">{formatDate(value)}</span>
        </span>
    );
}

function normalizeString(value) {
    return String(value ?? '')
        .toLowerCase()
        .trim();
}

function getDisplayName(user) {
    const first = String(user?.first_name ?? '').trim();
    const last = String(user?.last_name ?? '').trim();
    const full = `${first} ${last}`.trim();

    return full || user?.name || user?.email || 'Personnel';
}

export default function JeudyPersonnelTable({ users = [], depots = [], canManage = false }) {
    const [search, setSearch] = useState('');
    const [editingUser, setEditingUser] = useState(null);

    const form = useForm({
        phone: '',
        mobile_phone: '',
        depot_ids: [],
        operations_comment: '',
        display_order: '',
    });

    const rows = useMemo(() => {
        const needle = normalizeString(search);

        if (!needle) {
            return users;
        }

        return users.filter((user) => {
            const haystack = [
                getDisplayName(user),
                user.email,
                user.phone,
                user.mobile_phone,
                user.sector_name,
                (user.depots ?? []).map((depot) => depot.name).join(' '),
                user.operations_comment,
            ]
                .map(normalizeString)
                .join(' ');

            return haystack.includes(needle);
        });
    }, [search, users]);

    const openEdit = (user) => {
        form.setData({
            phone: user.phone ?? '',
            mobile_phone: user.mobile_phone ?? '',
            depot_ids: (user.depot_ids ?? []).map((id) => Number(id)),
            operations_comment: user.operations_comment ?? '',
            display_order:
                user.display_order === null || user.display_order === undefined
                    ? ''
                    : String(user.display_order),
        });
        form.clearErrors();
        setEditingUser(user);
    };

    const closeEdit = () => {
        if (form.processing) {
            return;
        }

        setEditingUser(null);
    };

    const toggleDepot = (depotId) => {
        const depotIds = new Set(form.data.depot_ids ?? []);

        if (depotIds.has(depotId)) {
            depotIds.delete(depotId);
        } else {
            depotIds.add(depotId);
        }

        form.setData('depot_ids', Array.from(depotIds));
    };

    const submit = (event) => {
        event.preventDefault();

        if (!editingUser) {
            return;
        }

        form.transform((data) => ({
            ...data,
            display_order: data.display_order === '' ? null : Number(data.display_order),
        }));

        form.put(route('task.data.jeudy.update', editingUser.id), {
            preserveScroll: true,
            onSuccess: () => setEditingUser(null),
        });
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm">
            <div className="border-b border-[var(--app-border)] px-4 py-3 sm:px-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-[var(--app-text)]">Personnels Jeudy</h2>
                        <p className="text-sm text-[var(--app-muted)]">
                            Tri par ordre, puis nom/prénom. Les dates habilitation/éco-conduite sont affichées en lecture.
                        </p>
                    </div>
                    <div className="relative w-full max-w-sm">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <TextInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Rechercher un personnel..."
                            className="w-full pl-9"
                        />
                    </div>
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-[980px] w-full divide-y divide-[var(--app-border)] text-sm">
                    <thead className="bg-[var(--app-surface-soft)]">
                        <tr className="text-left text-[var(--app-muted)]">
                            <th className="px-4 py-2 font-semibold">Ordre</th>
                            <th className="px-4 py-2 font-semibold">Nom / Prénom</th>
                            <th className="px-4 py-2 font-semibold">Fonction</th>
                            <th className="px-4 py-2 font-semibold">Téléphone</th>
                            <th className="px-4 py-2 font-semibold text-center">Habilitation nacelle</th>
                            <th className="px-4 py-2 font-semibold text-center">Éco-conduite</th>
                            <th className="px-4 py-2 font-semibold">Dépôt(s)</th>
                            <th className="px-4 py-2 font-semibold">Commentaire</th>
                            {canManage ? <th className="px-4 py-2 font-semibold text-right">Actions</th> : null}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--app-border)]">
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={canManage ? 9 : 8}
                                    className="px-4 py-6 text-center text-[var(--app-muted)]"
                                >
                                    Aucun résultat.
                                </td>
                            </tr>
                        ) : (
                            rows.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-4 py-3 font-semibold">
                                        {user.display_order ?? <span className="text-[var(--app-muted)]">-</span>}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2.5">
                                            <span className="inline-flex h-9 w-9 shrink-0 overflow-hidden rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)]">
                                                {user.photo_url ? (
                                                    <img src={user.photo_url} alt={getDisplayName(user)} className="h-full w-full object-cover" />
                                                ) : (
                                                    <span className="inline-flex h-full w-full items-center justify-center text-xs font-bold text-[var(--app-muted)]">
                                                        {(user.first_name?.[0] || user.name?.[0] || '?').toUpperCase()}
                                                    </span>
                                                )}
                                            </span>
                                            <div>
                                                <p className="font-semibold">{getDisplayName(user)}</p>
                                                <p className="text-xs text-[var(--app-muted)]">{user.email}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{user.sector_name || <span className="text-[var(--app-muted)]">-</span>}</td>
                                    <td className="px-4 py-3">
                                        {user.mobile_phone || user.phone ? (
                                            <div className="space-y-1">
                                                {user.phone ? (
                                                    <a href={`tel:${String(user.phone).replace(/\s+/g, '')}`} className="text-[var(--app-link)] underline decoration-dotted">
                                                        {user.phone}
                                                    </a>
                                                ) : null}
                                                {user.mobile_phone ? (
                                                    <a href={`tel:${String(user.mobile_phone).replace(/\s+/g, '')}`} className="block text-[var(--app-link)] underline decoration-dotted">
                                                        {user.mobile_phone}
                                                    </a>
                                                ) : null}
                                            </div>
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-center">{validationCell(user.nacelle_valid_until)}</td>
                                    <td className="px-4 py-3 text-center">{validationCell(user.eco_conduite_valid_until)}</td>
                                    <td className="px-4 py-3">
                                        {(user.depots ?? []).length ? (
                                            <span>{user.depots.map((depot) => depot.name).join(', ')}</span>
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">{user.operations_comment || <span className="text-[var(--app-muted)]">-</span>}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => openEdit(user)}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold hover:bg-[var(--app-surface-soft)]"
                                            >
                                                <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                Éditer
                                            </button>
                                        </td>
                                    ) : null}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Modal show={Boolean(editingUser)} onClose={closeEdit} maxWidth="2xl">
                <form onSubmit={submit} className="space-y-5 p-5">
                    <div>
                        <h3 className="text-lg font-semibold">Éditer {editingUser ? getDisplayName(editingUser) : ''}</h3>
                        <p className="text-sm text-[var(--app-muted)]">
                            Les dates habilitation nacelle et éco-conduite sont reprises depuis la base existante.
                        </p>
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
                            <label className="mb-1 block text-sm font-medium">Mobile</label>
                            <TextInput
                                value={form.data.mobile_phone}
                                onChange={(event) => form.setData('mobile_phone', event.target.value)}
                                className="w-full"
                            />
                            <InputError className="mt-2" message={form.errors.mobile_phone} />
                        </div>
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium">Ordre d’affichage</label>
                        <TextInput
                            type="number"
                            min="0"
                            value={form.data.display_order}
                            onChange={(event) => form.setData('display_order', event.target.value)}
                            className="w-full sm:w-48"
                        />
                        <InputError className="mt-2" message={form.errors.display_order} />
                    </div>

                    <div>
                        <label className="mb-2 block text-sm font-medium">Dépôt(s) de rattachement</label>
                        <div className="max-h-40 space-y-2 overflow-auto rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            {depots.map((depot) => {
                                const checked = (form.data.depot_ids ?? []).includes(depot.id);

                                return (
                                    <label key={depot.id} className="flex cursor-pointer items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            onChange={() => toggleDepot(depot.id)}
                                            className="rounded border-[var(--app-border)]"
                                        />
                                        <span>{depot.name}</span>
                                    </label>
                                );
                            })}
                        </div>
                        <InputError className="mt-2" message={form.errors.depot_ids} />
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium">Commentaire</label>
                        <textarea
                            value={form.data.operations_comment}
                            onChange={(event) => form.setData('operations_comment', event.target.value)}
                            rows={3}
                            className="w-full rounded-lg border-[var(--app-border)] bg-[var(--app-surface)] text-sm"
                        />
                        <InputError className="mt-2" message={form.errors.operations_comment} />
                    </div>

                    <div className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-sm">
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div>
                                <p className="font-semibold">Habilitation nacelle</p>
                                <p>{editingUser?.nacelle_valid_until ? formatDate(editingUser.nacelle_valid_until) : '-'}</p>
                            </div>
                            <div>
                                <p className="font-semibold">Éco-conduite</p>
                                <p>{editingUser?.eco_conduite_valid_until ? formatDate(editingUser.eco_conduite_valid_until) : '-'}</p>
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-[var(--app-border)] pt-4">
                        <SecondaryButton type="button" onClick={closeEdit}>
                            Annuler
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Enregistrer</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
