import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import { useMemo, useState } from 'react';

const FIELD_CLASS =
    'w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]';

/**
 * Création et modification d'un groupe de validation.
 *
 * Le même composant sert aux deux modes : seul le titre, le libellé du bouton
 * et le groupe en cours d'édition changent.
 */
export default function ValidationGroupModal({
    show,
    mode = 'create',
    form,
    users = [],
    groupByUser = {},
    editingGroupId = null,
    onClose,
    onSubmit,
}) {
    const [search, setSearch] = useState('');

    const isEdit = mode === 'edit';
    const selectedIds = useMemo(
        () => new Set((form.data.member_user_ids ?? []).map((id) => Number(id))),
        [form.data.member_user_ids],
    );

    /**
     * Un utilisateur n'appartient qu'à un seul groupe. En édition, les membres
     * du groupe courant restent évidemment disponibles.
     */
    const membershipOf = (userId) => {
        const membership = groupByUser?.[userId] ?? groupByUser?.[String(userId)] ?? null;

        if (!membership) {
            return null;
        }

        if (isEdit && Number(membership.group_id) === Number(editingGroupId)) {
            return null;
        }

        return membership;
    };

    const visibleUsers = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return users;
        }

        return users.filter((user) => String(user.label ?? '').toLowerCase().includes(needle));
    }, [users, search]);

    const selectedCount = selectedIds.size;
    const availableCount = users.filter((user) => !membershipOf(user.id) && !selectedIds.has(Number(user.id))).length;
    const takenCount = users.filter((user) => Boolean(membershipOf(user.id))).length;

    const toggleMember = (userId) => {
        const next = new Set(selectedIds);

        if (next.has(Number(userId))) {
            next.delete(Number(userId));
        } else {
            next.add(Number(userId));
        }

        form.setData('member_user_ids', Array.from(next));
    };

    const close = () => {
        setSearch('');
        onClose();
    };

    return (
        <Modal show={show} onClose={close} maxWidth="3xl">
            <form onSubmit={onSubmit}>
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-lg font-semibold text-[var(--app-text)]">
                        {isEdit ? 'Modifier le groupe de validation' : 'Créer un groupe de validation'}
                    </h3>
                    <p className="mt-1 text-xs text-[var(--app-muted)]">
                        Un utilisateur ne peut appartenir qu'à un seul groupe. Un valideur, lui, peut couvrir plusieurs groupes.
                    </p>
                </div>

                <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4">
                    <div>
                        <label className="block text-sm font-medium text-[var(--app-text)]" htmlFor="validation-group-name">
                            Nom du groupe
                        </label>
                        <input
                            id="validation-group-name"
                            type="text"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="Administration, Atelier, Chauffeurs…"
                            className={`mt-1 ${FIELD_CLASS}`}
                            required
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="block text-sm font-medium text-[var(--app-text)]" htmlFor="validation-group-validator-1">
                                Valideur 1
                            </label>
                            <select
                                id="validation-group-validator-1"
                                value={form.data.validator_1_id ?? ''}
                                onChange={(event) => form.setData('validator_1_id', event.target.value === '' ? '' : Number(event.target.value))}
                                className={`mt-1 ${FIELD_CLASS}`}
                                required
                            >
                                <option value="">Sélectionner un valideur</option>
                                {users
                                    .filter((user) => Number(user.id) !== Number(form.data.validator_2_id))
                                    .map((user) => (
                                        <option key={`v1-${user.id}`} value={user.id}>
                                            {user.label}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={form.errors.validator_1_id} className="mt-1" />
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-[var(--app-text)]" htmlFor="validation-group-validator-2">
                                Valideur 2
                            </label>
                            <select
                                id="validation-group-validator-2"
                                value={form.data.validator_2_id ?? ''}
                                onChange={(event) => form.setData('validator_2_id', event.target.value === '' ? '' : Number(event.target.value))}
                                className={`mt-1 ${FIELD_CLASS}`}
                                required
                            >
                                <option value="">Sélectionner un valideur</option>
                                {users
                                    .filter((user) => Number(user.id) !== Number(form.data.validator_1_id))
                                    .map((user) => (
                                        <option key={`v2-${user.id}`} value={user.id}>
                                            {user.label}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={form.errors.validator_2_id} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-sm font-medium text-[var(--app-text)]">Utilisateurs du groupe</span>
                            <span className="text-xs text-[var(--app-muted)]">
                                {selectedCount} sélectionné{selectedCount > 1 ? 's' : ''} · {availableCount} disponible{availableCount > 1 ? 's' : ''} · {takenCount} dans un autre groupe
                            </span>
                        </div>

                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Rechercher un utilisateur…"
                            className={`mt-2 ${FIELD_CLASS}`}
                        />

                        <div className="mt-2 max-h-72 space-y-1.5 overflow-y-auto rounded-xl border border-[var(--app-border)] p-2">
                            {visibleUsers.length === 0 ? (
                                <p className="px-2 py-3 text-sm text-[var(--app-muted)]">Aucun utilisateur ne correspond.</p>
                            ) : null}

                            {visibleUsers.map((user) => {
                                const membership = membershipOf(user.id);
                                const isTaken = Boolean(membership);
                                const isChecked = selectedIds.has(Number(user.id));

                                return (
                                    <label
                                        key={user.id}
                                        title={isTaken ? `Déjà dans « ${membership.group_name} »` : undefined}
                                        className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                                            isTaken
                                                ? 'cursor-not-allowed border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)] opacity-70'
                                                : 'cursor-pointer border-[var(--app-border)] text-[var(--app-text)]'
                                        }`}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={isChecked}
                                            disabled={isTaken}
                                            onChange={() => toggleMember(user.id)}
                                            className="h-4 w-4 rounded border-[var(--app-border)] disabled:cursor-not-allowed"
                                        />
                                        <span className="min-w-0 flex-1 truncate">{user.label}</span>
                                        {isTaken ? (
                                            <span className="shrink-0 rounded-full border border-[var(--app-border)] px-2 py-0.5 text-[11px] font-semibold">
                                                Déjà dans « {membership.group_name} »
                                            </span>
                                        ) : null}
                                    </label>
                                );
                            })}
                        </div>

                        <InputError message={form.errors.member_user_ids} className="mt-1" />
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={close}
                        disabled={form.processing}
                        className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-sm font-semibold text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isEdit ? 'Enregistrer les modifications' : 'Créer le groupe'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
