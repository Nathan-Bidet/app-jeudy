import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AdminLayout from '@/Layouts/AdminLayout';
import { abilityLabel } from '@/Support/abilityLabels';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Pencil, Save, Trash2, UserPlus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function getDisplayName(user) {
    const firstName = (user?.first_name ?? '').trim();
    const lastName = (user?.last_name ?? '').trim();
    const fullName = `${firstName} ${lastName}`.trim();

    return fullName || user?.name || user?.email || 'Utilisateur';
}

function getNameParts(user) {
    const displayName = getDisplayName(user);
    const [fallbackFirst = '', ...rest] = displayName.split(' ');

    return {
        firstName: (user?.first_name ?? fallbackFirst).trim(),
        lastName: (user?.last_name ?? rest.join(' ')).trim(),
    };
}

export default function AdminUsersIndex({ users, sectors, roles, abilities = [] }) {
    const [selectedUserId, setSelectedUserId] = useState(users[0]?.id ?? null);
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState(null);
    const [deleteCandidate, setDeleteCandidate] = useState(null);
    const [deleteError, setDeleteError] = useState('');
    const [isDeletingUser, setIsDeletingUser] = useState(false);
    const defaultSectorId = sectors[0]?.id ? String(sectors[0].id) : '';

    const selectedUser = useMemo(
        () => users.find((candidate) => candidate.id === selectedUserId) ?? null,
        [users, selectedUserId],
    );

    const abilityOptions = useMemo(() => {
        const merged = [
            ...abilities,
            ...(selectedUser?.allow_overrides ?? []),
            ...(selectedUser?.deny_overrides ?? []),
        ];

        return Array.from(new Set(merged)).sort((left, right) => left.localeCompare(right));
    }, [abilities, selectedUser]);

    const roleForm = useForm({
        role: selectedUser?.role ?? 'utilisateur',
        sector_id: selectedUser?.sector_id ?? '',
    });

    const exceptionForm = useForm({
        allow_abilities: selectedUser?.allow_overrides ?? [],
        deny_abilities: selectedUser?.deny_overrides ?? [],
    });

    const createForm = useForm({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'utilisateur',
        sector_id: defaultSectorId,
    });

    const editForm = useForm({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        roleForm.setData({
            role: selectedUser?.role ?? 'utilisateur',
            sector_id: selectedUser?.sector_id ?? '',
        });

        exceptionForm.setData({
            allow_abilities: selectedUser?.allow_overrides ?? [],
            deny_abilities: selectedUser?.deny_overrides ?? [],
        });
    }, [selectedUser]);

    useEffect(() => {
        if (users.length === 0) {
            if (selectedUserId !== null) {
                setSelectedUserId(null);
            }

            return;
        }

        if (!users.some((user) => user.id === selectedUserId)) {
            setSelectedUserId(users[0].id);
        }
    }, [users, selectedUserId]);

    const submitRole = (event) => {
        event.preventDefault();

        if (!selectedUser) {
            return;
        }

        roleForm.put(route('admin.users.update', selectedUser.id), {
            preserveScroll: true,
        });
    };

    const submitExceptions = (event) => {
        event.preventDefault();

        if (!selectedUser) {
            return;
        }

        exceptionForm.put(route('admin.users.overrides.update', selectedUser.id), {
            preserveScroll: true,
        });
    };

    const hasAllow = (ability) => (exceptionForm.data.allow_abilities ?? []).includes(ability);
    const hasDeny = (ability) => (exceptionForm.data.deny_abilities ?? []).includes(ability);

    const setAbilityEffect = (ability, effect) => {
        const allowSet = new Set(exceptionForm.data.allow_abilities ?? []);
        const denySet = new Set(exceptionForm.data.deny_abilities ?? []);

        allowSet.delete(ability);
        denySet.delete(ability);

        if (effect === 'allow') {
            allowSet.add(ability);
        }

        if (effect === 'deny') {
            denySet.add(ability);
        }

        exceptionForm.setData({
            ...exceptionForm.data,
            allow_abilities: Array.from(allowSet),
            deny_abilities: Array.from(denySet),
        });
    };

    const clearExceptionSelections = () => {
        exceptionForm.setData({
            ...exceptionForm.data,
            allow_abilities: [],
            deny_abilities: [],
        });
    };

    const openCreateModal = () => {
        createForm.reset();
        createForm.setData({
            first_name: '',
            last_name: '',
            email: '',
            password: '',
            password_confirmation: '',
            role: 'utilisateur',
            sector_id: defaultSectorId,
        });
        createForm.clearErrors();
        setIsCreateModalOpen(true);
    };

    const closeCreateModal = () => {
        if (createForm.processing) {
            return;
        }

        setIsCreateModalOpen(false);
    };

    const submitCreate = (event) => {
        event.preventDefault();

        createForm.post(route('admin.users.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setIsCreateModalOpen(false);
                createForm.reset();
                createForm.setData({
                    first_name: '',
                    last_name: '',
                    email: '',
                    password: '',
                    password_confirmation: '',
                    role: 'utilisateur',
                    sector_id: defaultSectorId,
                });
            },
        });
    };

    const openEditModal = (user) => {
        const { firstName, lastName } = getNameParts(user);

        editForm.reset();
        editForm.setData({
            first_name: firstName,
            last_name: lastName,
            email: user.email ?? '',
            password: '',
            password_confirmation: '',
        });
        editForm.clearErrors();
        setEditingUser(user);
    };

    const closeEditModal = () => {
        if (editForm.processing) {
            return;
        }

        setEditingUser(null);
    };

    const submitEditUser = (event) => {
        event.preventDefault();

        if (!editingUser) {
            return;
        }

        editForm.put(route('admin.users.account.update', editingUser.id), {
            preserveScroll: true,
            onSuccess: () => {
                setEditingUser(null);
                editForm.reset('password', 'password_confirmation');
            },
        });
    };

    const openDeleteModal = (user) => {
        setDeleteError('');
        setDeleteCandidate(user);
    };

    const closeDeleteModal = () => {
        if (isDeletingUser) {
            return;
        }

        setDeleteCandidate(null);
    };

    const confirmDeleteUser = () => {
        if (!deleteCandidate) {
            return;
        }

        setIsDeletingUser(true);
        setDeleteError('');

        router.delete(route('admin.users.destroy', deleteCandidate.id), {
            preserveScroll: true,
            onSuccess: () => {
                setDeleteCandidate(null);
            },
            onError: (errors) => {
                if (errors.delete_user) {
                    setDeleteError(errors.delete_user);
                }

                if (!errors.delete_user) {
                    setDeleteError("La suppression n'a pas pu être effectuée.");
                }
            },
            onFinish: () => {
                setIsDeletingUser(false);
            },
        });
    };

    return (
        <AdminLayout title="Admin - Utilisateurs">
            <Head title="Admin Utilisateurs" />

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                <section className="space-y-6">
                    {!selectedUser && <div className="rounded-lg bg-white p-6 shadow-sm">Aucun utilisateur.</div>}

                    {selectedUser && (
                        <>
                            <div className="rounded-lg bg-white p-6 shadow-sm">
                                <h3 className="text-lg font-semibold text-gray-900">Role + secteur</h3>

                                <form onSubmit={submitRole} className="mt-5 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-gray-700">Rôle</label>
                                        <select
                                            className="w-full rounded border-gray-300"
                                            value={roleForm.data.role}
                                            onChange={(event) => roleForm.setData('role', event.target.value)}
                                        >
                                            {roles.map((role) => (
                                                <option key={role} value={role}>
                                                    {role}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError className="mt-2" message={roleForm.errors.role} />
                                    </div>

                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-gray-700">Secteur</label>
                                        <select
                                            className="w-full rounded border-gray-300"
                                            value={roleForm.data.sector_id}
                                            onChange={(event) => roleForm.setData('sector_id', event.target.value)}
                                        >
                                            <option value="">-- Choisir --</option>
                                            {sectors.map((sector) => (
                                                <option key={sector.id} value={sector.id}>
                                                    {sector.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError className="mt-2" message={roleForm.errors.sector_id} />
                                    </div>

                                    <div className="sm:col-span-2">
                                        <PrimaryButton disabled={roleForm.processing}>
                                            <span className="inline-flex items-center gap-1.5">
                                                <Save className="h-4 w-4" strokeWidth={2.2} />
                                                <span>Enregistrer</span>
                                            </span>
                                        </PrimaryButton>
                                    </div>
                                </form>
                            </div>

                            <div className="rounded-lg bg-white p-6 shadow-sm">
                                <h3 className="text-lg font-semibold text-gray-900">Exceptions explicites</h3>

                                <form onSubmit={submitExceptions} className="mt-5 space-y-4">
                                    <div className="rounded-lg border border-gray-200">
                                        <div className="grid grid-cols-[1fr_auto] gap-3 border-b border-gray-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <span>Permission</span>
                                            <span>Mode</span>
                                        </div>

                                        <div className="max-h-72 divide-y divide-gray-100 overflow-auto">
                                            {abilityOptions.map((ability) => {
                                                const allowed = hasAllow(ability);
                                                const denied = hasDeny(ability);

                                                return (
                                                    <div
                                                        key={ability}
                                                        className="grid grid-cols-[1fr_auto] items-center gap-3 px-3 py-2"
                                                    >
                                                        <div className="min-w-0">
                                                            <p className="truncate text-xs font-semibold text-gray-800">
                                                                {abilityLabel(ability)}
                                                            </p>
                                                            <p className="truncate font-mono text-[10px] text-gray-500">
                                                                {ability}
                                                            </p>
                                                        </div>

                                                        <div className="flex items-center gap-1 text-xs">
                                                            <button
                                                                type="button"
                                                                onClick={() => setAbilityEffect(ability, 'none')}
                                                                className={`rounded px-2 py-1 ${
                                                                    !allowed && !denied
                                                                        ? 'bg-gray-800 text-white'
                                                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                                                }`}
                                                            >
                                                                Défaut
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => setAbilityEffect(ability, 'allow')}
                                                                className={`rounded px-2 py-1 ${
                                                                    allowed
                                                                        ? 'bg-green-600 text-white'
                                                                        : 'bg-green-50 text-green-700 hover:bg-green-100'
                                                                }`}
                                                            >
                                                                Allow
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => setAbilityEffect(ability, 'deny')}
                                                                className={`rounded px-2 py-1 ${
                                                                    denied
                                                                        ? 'bg-red-600 text-white'
                                                                        : 'bg-red-50 text-red-700 hover:bg-red-100'
                                                                }`}
                                                            >
                                                                Deny
                                                            </button>
                                                        </div>
                                                    </div>
                                                );
                                            })}

                                            {abilityOptions.length === 0 && (
                                                <div className="px-3 py-4 text-sm text-gray-500">
                                                    Aucune permission disponible.
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <InputError className="mt-2" message={exceptionForm.errors.allow_abilities} />
                                    <InputError className="mt-2" message={exceptionForm.errors.deny_abilities} />

                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="flex gap-2 text-xs">
                                            <span className="rounded bg-green-100 px-2 py-1 text-green-800">
                                                Allow: {(exceptionForm.data.allow_abilities ?? []).length}
                                            </span>
                                            <span className="rounded bg-red-100 px-2 py-1 text-red-800">
                                                Deny: {(exceptionForm.data.deny_abilities ?? []).length}
                                            </span>
                                        </div>

                                        <div className="flex gap-2">
                                            <SecondaryButton type="button" onClick={clearExceptionSelections}>
                                                Réinitialiser
                                            </SecondaryButton>
                                            <PrimaryButton disabled={exceptionForm.processing}>
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Save className="h-4 w-4" strokeWidth={2.2} />
                                                    <span>Enregistrer</span>
                                                </span>
                                            </PrimaryButton>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </>
                    )}
                </section>

                <section className="rounded-lg bg-white p-4 shadow-sm lg:sticky lg:top-4 lg:flex lg:max-h-[calc(100vh-9.5rem)] lg:min-h-0 lg:flex-col">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Utilisateurs</h3>
                        <PrimaryButton type="button" className="px-2.5 py-1 text-[10px]" onClick={openCreateModal}>
                            <span className="inline-flex items-center gap-1">
                                <UserPlus className="h-3 w-3" strokeWidth={2.2} />
                                <span>Ajouter</span>
                            </span>
                        </PrimaryButton>
                    </div>
                    <div className="space-y-2 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:pr-1">
                        {users.map((user) => (
                            <div
                                key={user.id}
                                className={`rounded border px-3 py-2 text-sm ${
                                    selectedUserId === user.id
                                        ? 'border-gray-900 bg-gray-900 text-white'
                                        : 'border-gray-200 bg-white text-gray-800'
                                }`}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setSelectedUserId(user.id)}
                                        className="min-w-0 flex-1 text-left"
                                    >
                                        <div className="truncate font-medium leading-tight">{getDisplayName(user)}</div>
                                        <div className="truncate text-xs leading-tight opacity-80">{user.email}</div>
                                    </button>

                                    <div className="flex shrink-0 items-center gap-1">
                                        <SecondaryButton
                                            type="button"
                                            className="h-7 px-2 py-0 text-[11px] normal-case tracking-normal"
                                            onClick={() => openEditModal(user)}
                                        >
                                            <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                        </SecondaryButton>
                                        <DangerButton
                                            type="button"
                                            className="h-7 px-2 py-0 text-[11px] normal-case tracking-normal"
                                            onClick={() => openDeleteModal(user)}
                                        >
                                            <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                        </DangerButton>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            </div>

            <Modal show={isCreateModalOpen} onClose={closeCreateModal} maxWidth="lg">
                <form onSubmit={submitCreate} className="space-y-4 p-6">
                    <h3 className="text-lg font-semibold text-gray-900">Ajouter un utilisateur</h3>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Prénom</label>
                            <TextInput
                                className="w-full"
                                value={createForm.data.first_name}
                                onChange={(event) => createForm.setData('first_name', event.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={createForm.errors.first_name} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Nom</label>
                            <TextInput
                                className="w-full"
                                value={createForm.data.last_name}
                                onChange={(event) => createForm.setData('last_name', event.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={createForm.errors.last_name} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Email</label>
                            <TextInput
                                type="email"
                                className="w-full"
                                value={createForm.data.email}
                                onChange={(event) => createForm.setData('email', event.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={createForm.errors.email} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Rôle</label>
                            <select
                                className="w-full rounded border-gray-300"
                                value={createForm.data.role}
                                onChange={(event) => createForm.setData('role', event.target.value)}
                                required
                            >
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </select>
                            <InputError className="mt-2" message={createForm.errors.role} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Secteur</label>
                            <select
                                className="w-full rounded border-gray-300"
                                value={createForm.data.sector_id}
                                onChange={(event) => createForm.setData('sector_id', event.target.value)}
                                required
                            >
                                <option value="">-- Choisir --</option>
                                {sectors.map((sector) => (
                                    <option key={sector.id} value={sector.id}>
                                        {sector.name}
                                    </option>
                                ))}
                            </select>
                            <InputError className="mt-2" message={createForm.errors.sector_id} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Mot de passe</label>
                            <TextInput
                                type="password"
                                className="w-full"
                                value={createForm.data.password}
                                onChange={(event) => createForm.setData('password', event.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={createForm.errors.password} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Confirmer le mot de passe</label>
                            <TextInput
                                type="password"
                                className="w-full"
                                value={createForm.data.password_confirmation}
                                onChange={(event) =>
                                    createForm.setData('password_confirmation', event.target.value)
                                }
                                required
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <SecondaryButton type="button" onClick={closeCreateModal}>
                            Annuler
                        </SecondaryButton>
                        <PrimaryButton disabled={createForm.processing}>Créer</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={Boolean(editingUser)} onClose={closeEditModal} maxWidth="lg">
                <form onSubmit={submitEditUser} className="space-y-4 p-6">
                    <h3 className="text-lg font-semibold text-gray-900">Modifier le compte</h3>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Prénom</label>
                            <TextInput
                                className="w-full"
                                value={editForm.data.first_name}
                                onChange={(event) => editForm.setData('first_name', event.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={editForm.errors.first_name} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Nom</label>
                            <TextInput
                                className="w-full"
                                value={editForm.data.last_name}
                                onChange={(event) => editForm.setData('last_name', event.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={editForm.errors.last_name} />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Email</label>
                            <TextInput
                                type="email"
                                className="w-full"
                                value={editForm.data.email}
                                onChange={(event) => editForm.setData('email', event.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={editForm.errors.email} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Nouveau mot de passe</label>
                            <TextInput
                                type="password"
                                className="w-full"
                                value={editForm.data.password}
                                onChange={(event) => editForm.setData('password', event.target.value)}
                                placeholder="Laisser vide pour conserver"
                            />
                            <InputError className="mt-2" message={editForm.errors.password} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Confirmer le mot de passe</label>
                            <TextInput
                                type="password"
                                className="w-full"
                                value={editForm.data.password_confirmation}
                                onChange={(event) =>
                                    editForm.setData('password_confirmation', event.target.value)
                                }
                                placeholder="Seulement si modifié"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <SecondaryButton type="button" onClick={closeEditModal}>
                            Annuler
                        </SecondaryButton>
                        <PrimaryButton disabled={editForm.processing}>
                            <span className="inline-flex items-center gap-1.5">
                                <Save className="h-4 w-4" strokeWidth={2.2} />
                                <span>Enregistrer</span>
                            </span>
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={Boolean(deleteCandidate)} onClose={closeDeleteModal} maxWidth="md">
                <div className="space-y-4 p-6">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 rounded-full bg-red-100 p-2 text-red-600">
                            <AlertTriangle className="h-4 w-4" strokeWidth={2.2} />
                        </div>
                        <div>
                            <h3 className="text-lg font-semibold text-gray-900">Confirmer la suppression</h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Vous allez supprimer le compte{' '}
                                <span className="font-semibold text-gray-900">
                                    {deleteCandidate ? getDisplayName(deleteCandidate) : ''}
                                </span>
                                . Cette action est irréversible.
                            </p>
                        </div>
                    </div>

                    {deleteError && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {deleteError}
                        </div>
                    )}

                    <div className="flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={closeDeleteModal} disabled={isDeletingUser}>
                            Annuler
                        </SecondaryButton>
                        <DangerButton type="button" onClick={confirmDeleteUser} disabled={isDeletingUser}>
                            {isDeletingUser ? (
                                'Suppression...'
                            ) : (
                                <span className="inline-flex items-center gap-1.5">
                                    <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                                    <span>Supprimer</span>
                                </span>
                            )}
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </AdminLayout>
    );
}
