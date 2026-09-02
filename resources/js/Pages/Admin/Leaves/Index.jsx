import DeleteValidationGroupModal from '@/Components/Admin/DeleteValidationGroupModal';
import ValidationGroupModal from '@/Components/Admin/ValidationGroupModal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const EMPTY_VALIDATION_GROUP = {
    name: '',
    validator_1_id: '',
    validator_2_id: '',
    member_user_ids: [],
};

export default function AdminLeavesIndex({
    users = [],
    validationGroups = [],
    validationGroupByUser = {},
    hrUserIds = [],
    allowedCreatorTargetsByCreator = {},
    hasAllowedCreatorPairConfig = false,
    leaveTypes = [],
}) {
    const validationGroupForm = useForm({ ...EMPTY_VALIDATION_GROUP });
    const [validationGroupModal, setValidationGroupModal] = useState(null);
    const [validationGroupToDelete, setValidationGroupToDelete] = useState(null);
    const [isDeletingValidationGroup, setIsDeletingValidationGroup] = useState(false);
    const rhForm = useForm({
        hr_user_ids: hrUserIds.map((id) => Number(id)),
    });
    const creatorsForm = useForm({
        creator_permissions: users.map((creator) => ({
            creator_user_id: Number(creator.id),
            is_enabled: hasAllowedCreatorPairConfig
                ? ((allowedCreatorTargetsByCreator[creator.id] ?? []).length > 0)
                : false,
            target_user_ids: hasAllowedCreatorPairConfig
                ? (allowedCreatorTargetsByCreator[creator.id] ?? []).map((id) => Number(id))
                : [],
        })),
    });
    const [openCreatorUserId, setOpenCreatorUserId] = useState(() => {
        const firstEnabled = users.find((creator) => (
            hasAllowedCreatorPairConfig
                ? ((allowedCreatorTargetsByCreator[creator.id] ?? []).length > 0)
                : false
        ));

        return firstEnabled ? Number(firstEnabled.id) : null;
    });
    const leaveTypeCreateForm = useForm({
        name: '',
        max_days: 1,
        sort_order: 0,
        is_unlimited: false,
        is_active: true,
        visibility_mode: 'all',
        visible_user_ids: [],
    });
    const [leaveTypeEdits, setLeaveTypeEdits] = useState(
        leaveTypes.reduce((carry, leaveType) => ({
            ...carry,
            [leaveType.id]: {
                name: leaveType.name,
                max_days: leaveType.max_days ?? '',
                sort_order: leaveType.sort_order ?? 0,
                is_unlimited: leaveType.max_days === null,
                is_active: leaveType.is_active,
                visibility_mode: leaveType.visibility_mode ?? 'all',
                visible_user_ids: Array.isArray(leaveType.visible_user_ids)
                    ? leaveType.visible_user_ids.map((id) => Number(id))
                    : [],
            },
        }), {}),
    );

    const formatUserWithSectors = (user) => {
        const baseLabel = user?.label || '';
        const sectorLabels = Array.isArray(user?.sector_labels)
            ? user.sector_labels.filter(Boolean)
            : [];

        if (sectorLabels.length === 0) {
            return baseLabel;
        }

        return `${baseLabel} (${sectorLabels.join(', ')})`;
    };

    // Le secteur reste affiché à côté du nom : c'est ce qui permettait de
    // distinguer deux homonymes dans l'ancien écran des valideurs.
    const validationGroupUsers = users.map((user) => ({
        id: Number(user.id),
        label: formatUserWithSectors(user),
    }));

    const openValidationGroupCreate = () => {
        validationGroupForm.clearErrors();
        validationGroupForm.setData({ ...EMPTY_VALIDATION_GROUP });
        setValidationGroupModal({ mode: 'create', group: null });
    };

    const openValidationGroupEdit = (group) => {
        validationGroupForm.clearErrors();
        validationGroupForm.setData({
            name: group.name ?? '',
            validator_1_id: group.validator_1_id ?? '',
            validator_2_id: group.validator_2_id ?? '',
            member_user_ids: (group.member_user_ids ?? []).map((id) => Number(id)),
        });
        setValidationGroupModal({ mode: 'edit', group });
    };

    const closeValidationGroupModal = () => {
        setValidationGroupModal(null);
        validationGroupForm.clearErrors();
    };

    const submitValidationGroup = (event) => {
        event.preventDefault();

        if (!validationGroupModal) {
            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                closeValidationGroupModal();
                validationGroupForm.setData({ ...EMPTY_VALIDATION_GROUP });
            },
        };

        if (validationGroupModal.mode === 'edit') {
            validationGroupForm.put(
                route('admin.leaves.validation-groups.update', validationGroupModal.group.id),
                options,
            );

            return;
        }

        validationGroupForm.post(route('admin.leaves.validation-groups.store'), options);
    };

    const confirmValidationGroupDelete = () => {
        if (!validationGroupToDelete || isDeletingValidationGroup) {
            return;
        }

        setIsDeletingValidationGroup(true);

        router.delete(route('admin.leaves.validation-groups.destroy', validationGroupToDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setIsDeletingValidationGroup(false);
                setValidationGroupToDelete(null);
            },
        });
    };

    const toggleRhUser = (userId) => {
        const current = new Set(rhForm.data.hr_user_ids ?? []);

        if (current.has(userId)) {
            current.delete(userId);
        } else {
            current.add(userId);
        }

        rhForm.setData('hr_user_ids', Array.from(current));
    };

    const submitRh = (event) => {
        event.preventDefault();

        rhForm.put(route('admin.leaves.rh.update'), {
            preserveScroll: true,
        });
    };

    const toggleAllowedCreatorEnabled = (creatorUserId) => {
        const currentRow = creatorsForm.data.creator_permissions.find(
            (row) => Number(row.creator_user_id) === Number(creatorUserId),
        );
        const nextEnabled = !Boolean(currentRow?.is_enabled);

        creatorsForm.setData(
            'creator_permissions',
            creatorsForm.data.creator_permissions.map((row) => {
                if (Number(row.creator_user_id) !== Number(creatorUserId)) {
                    return row;
                }

                if (!nextEnabled && Number(openCreatorUserId) === Number(creatorUserId)) {
                    setOpenCreatorUserId(null);
                }

                return {
                    ...row,
                    is_enabled: nextEnabled,
                    target_user_ids: nextEnabled ? row.target_user_ids : [],
                };
            }),
        );

        if (nextEnabled) {
            setOpenCreatorUserId(Number(creatorUserId));
        }
    };

    const toggleAllowedCreatorTarget = (creatorUserId, targetUserId) => {
        creatorsForm.setData(
            'creator_permissions',
            creatorsForm.data.creator_permissions.map((row) => {
                if (Number(row.creator_user_id) !== Number(creatorUserId)) {
                    return row;
                }

                const current = new Set((row.target_user_ids ?? []).map((id) => Number(id)));
                if (current.has(Number(targetUserId))) {
                    current.delete(Number(targetUserId));
                } else {
                    current.add(Number(targetUserId));
                }

                return {
                    ...row,
                    target_user_ids: Array.from(current),
                };
            }),
        );
    };

    const toggleCreatorPanel = (creatorUserId) => {
        setOpenCreatorUserId((current) => (
            Number(current) === Number(creatorUserId) ? null : Number(creatorUserId)
        ));
    };

    const submitAllowedCreators = (event) => {
        event.preventDefault();

        creatorsForm.put(route('admin.leaves.allowed-creator-pairs.update'), {
            preserveScroll: true,
        });
    };

    const submitLeaveTypeCreate = (event) => {
        event.preventDefault();

        leaveTypeCreateForm.post(route('admin.leaves.types.store'), {
            preserveScroll: true,
            onSuccess: () => {
                leaveTypeCreateForm.reset();
                leaveTypeCreateForm.setData({
                    name: '',
                    max_days: 1,
                    sort_order: 0,
                    is_unlimited: false,
                    is_active: true,
                    visibility_mode: 'all',
                    visible_user_ids: [],
                });
            },
        });
    };

    const updateLeaveTypeEdit = (leaveTypeId, patch) => {
        setLeaveTypeEdits((prev) => ({
            ...prev,
            [leaveTypeId]: {
                ...(prev[leaveTypeId] ?? {}),
                ...patch,
            },
        }));
    };

    const submitLeaveTypeUpdate = (event, leaveTypeId) => {
        event.preventDefault();

        const payload = leaveTypeEdits[leaveTypeId];
        if (!payload) {
            return;
        }

        router.put(route('admin.leaves.types.update', leaveTypeId), payload, {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout title="Admin - Congés">
            <Head title="Admin - Congés" />

            <div className="space-y-4">
                <header className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h1 className="text-xl font-black tracking-tight text-[var(--app-text)] sm:text-2xl">Administration Congés</h1>
                    <p className="mt-1 text-sm text-[var(--app-muted)]">
                        Page préparatoire des réglages du module Congés.
                    </p>
                </header>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 className="text-base font-semibold text-[var(--app-text)]">Groupes de validation</h2>
                            <p className="mt-1 text-sm text-[var(--app-muted)]">
                                Chaque groupe réunit des utilisateurs derrière deux valideurs. Un utilisateur n'appartient qu'à un seul groupe.
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={openValidationGroupCreate}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-sm font-semibold text-[var(--app-text)]"
                        >
                            + Créer un groupe
                        </button>
                    </div>

                    {validationGroups.length === 0 ? (
                        <p className="mt-3 rounded-xl border border-dashed border-[var(--app-border)] px-3 py-6 text-center text-sm text-[var(--app-muted)]">
                            Aucun groupe de validation pour le moment.
                        </p>
                    ) : (
                        <div className="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                            {validationGroups.map((group) => (
                                <div key={group.id} className="flex flex-col gap-3 rounded-xl border border-[var(--app-border)] p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <p className="text-sm font-semibold text-[var(--app-text)]">{group.name}</p>
                                        <span className="shrink-0 rounded-full border border-[var(--app-border)] px-2 py-0.5 text-xs font-semibold text-[var(--app-muted)]">
                                            {group.member_count} utilisateur{group.member_count > 1 ? 's' : ''}
                                        </span>
                                    </div>

                                    <dl className="grid gap-1 text-sm">
                                        <div className="flex flex-wrap items-baseline gap-1">
                                            <dt className="text-[var(--app-muted)]">Valideur 1 :</dt>
                                            <dd className="text-[var(--app-text)]">
                                                {group.validator_1_label ?? 'Non défini'}
                                            </dd>
                                        </div>
                                        <div className="flex flex-wrap items-baseline gap-1">
                                            <dt className="text-[var(--app-muted)]">Valideur 2 :</dt>
                                            <dd className="text-[var(--app-text)]">
                                                {group.validator_2_label ?? 'Non défini'}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            onClick={() => openValidationGroupEdit(group)}
                                            className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-xs font-semibold text-[var(--app-text)]"
                                        >
                                            Modifier
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setValidationGroupToDelete(group)}
                                            className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-xs font-semibold text-red-600"
                                        >
                                            Supprimer
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                    <h2 className="text-base font-semibold text-[var(--app-text)]">RH</h2>
                    <form className="mt-3 space-y-3" onSubmit={submitRh}>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {users.map((user) => (
                                <label
                                    key={user.id}
                                    className="flex items-center gap-2 rounded-xl border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)]"
                                >
                                    <input
                                        type="checkbox"
                                        checked={(rhForm.data.hr_user_ids ?? []).includes(user.id)}
                                        onChange={() => toggleRhUser(user.id)}
                                        className="h-4 w-4 rounded border-[var(--app-border)]"
                                    />
                                    <span>{user.label}</span>
                                </label>
                            ))}
                        </div>

                        <div className="pt-1">
                            <button
                                type="submit"
                                disabled={rhForm.processing}
                                className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Enregistrer les RH
                            </button>
                        </div>
                    </form>
                </section>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                    <h2 className="text-base font-semibold text-[var(--app-text)]">Création pour autrui</h2>
                    <form className="mt-3 space-y-3" onSubmit={submitAllowedCreators}>
                        <div className="space-y-3">
                            {creatorsForm.data.creator_permissions.map((row) => {
                                const creator = users.find((candidate) => Number(candidate.id) === Number(row.creator_user_id));
                                const isEnabled = Boolean(row.is_enabled);
                                const isOpen = isEnabled && Number(openCreatorUserId) === Number(row.creator_user_id);

                                return (
                                    <div key={row.creator_user_id} className="rounded-xl border border-[var(--app-border)] p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <label className="flex items-center gap-2 text-sm font-semibold text-[var(--app-text)]">
                                                <input
                                                    type="checkbox"
                                                    checked={isEnabled}
                                                    onChange={() => toggleAllowedCreatorEnabled(row.creator_user_id)}
                                                    className="h-4 w-4 rounded border-[var(--app-border)]"
                                                />
                                                <span>{creator?.label ?? `Utilisateur #${row.creator_user_id}`}</span>
                                            </label>

                                            {isEnabled ? (
                                                <button
                                                    type="button"
                                                    onClick={() => toggleCreatorPanel(row.creator_user_id)}
                                                    className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-xs font-semibold text-[var(--app-text)]"
                                                >
                                                    {isOpen ? 'Masquer les cibles' : 'Afficher les cibles'}
                                                </button>
                                            ) : null}
                                        </div>

                                        {isOpen ? (
                                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                {users
                                                    .filter((target) => Number(target.id) !== Number(row.creator_user_id))
                                                    .map((target) => (
                                                        <label
                                                            key={`${row.creator_user_id}-${target.id}`}
                                                            className="flex items-center gap-2 rounded-lg border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)]"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={(row.target_user_ids ?? []).includes(Number(target.id))}
                                                                onChange={() => toggleAllowedCreatorTarget(row.creator_user_id, target.id)}
                                                                className="h-4 w-4 rounded border-[var(--app-border)]"
                                                            />
                                                            <span>{target.label}</span>
                                                        </label>
                                                    ))}
                                            </div>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>

                        <div className="pt-1">
                            <button
                                type="submit"
                                disabled={creatorsForm.processing}
                                className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Enregistrer les autorisations
                            </button>
                        </div>
                    </form>
                </section>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                    <h2 className="text-base font-semibold text-[var(--app-text)]">Types de congés</h2>

                    <form className="mt-3 grid gap-2 rounded-xl border border-[var(--app-border)] p-3 md:grid-cols-4" onSubmit={submitLeaveTypeCreate}>
                        <input
                            type="text"
                            value={leaveTypeCreateForm.data.name}
                            onChange={(event) => leaveTypeCreateForm.setData('name', event.target.value)}
                            placeholder="Nom du type"
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] md:col-span-2"
                        />
                        <input
                            type="number"
                            value={leaveTypeCreateForm.data.sort_order}
                            onChange={(event) => leaveTypeCreateForm.setData('sort_order', Number(event.target.value || 0))}
                            placeholder="Ordre"
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                        />
                        <input
                            type="number"
                            min={1}
                            value={leaveTypeCreateForm.data.max_days}
                            onChange={(event) => leaveTypeCreateForm.setData('max_days', Number(event.target.value || 1))}
                            placeholder="Durée max (jours)"
                            disabled={leaveTypeCreateForm.data.is_unlimited}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                        />
                        <label className="flex items-center gap-2 rounded-lg border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)]">
                            <input
                                type="checkbox"
                                checked={leaveTypeCreateForm.data.is_unlimited}
                                onChange={(event) => leaveTypeCreateForm.setData({
                                    ...leaveTypeCreateForm.data,
                                    is_unlimited: event.target.checked,
                                    max_days: event.target.checked ? '' : (leaveTypeCreateForm.data.max_days || 1),
                                })}
                                className="h-4 w-4 rounded border-[var(--app-border)]"
                            />
                            <span>Sans limite</span>
                        </label>
                        <label className="flex items-center gap-2 rounded-lg border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)]">
                            <input
                                type="checkbox"
                                checked={leaveTypeCreateForm.data.is_active}
                                onChange={(event) => leaveTypeCreateForm.setData('is_active', event.target.checked)}
                                className="h-4 w-4 rounded border-[var(--app-border)]"
                            />
                            <span>Actif</span>
                        </label>
                        <div className="rounded-lg border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)] md:col-span-4">
                            <p className="mb-2 font-semibold">Visibilité</p>
                            <div className="flex flex-wrap gap-3">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="create_visibility_mode"
                                        value="all"
                                        checked={leaveTypeCreateForm.data.visibility_mode === 'all'}
                                        onChange={() => leaveTypeCreateForm.setData('visibility_mode', 'all')}
                                        className="h-4 w-4"
                                    />
                                    <span>Visible par tous</span>
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="create_visibility_mode"
                                        value="selected"
                                        checked={leaveTypeCreateForm.data.visibility_mode === 'selected'}
                                        onChange={() => leaveTypeCreateForm.setData('visibility_mode', 'selected')}
                                        className="h-4 w-4"
                                    />
                                    <span>Visible uniquement par certains utilisateurs</span>
                                </label>
                            </div>
                            {leaveTypeCreateForm.data.visibility_mode === 'selected' ? (
                                <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {users.map((user) => (
                                        <label key={`create-leave-type-visible-${user.id}`} className="flex items-center gap-2 rounded-lg border border-[var(--app-border)] px-2 py-1.5">
                                            <input
                                                type="checkbox"
                                                checked={(leaveTypeCreateForm.data.visible_user_ids ?? []).includes(Number(user.id))}
                                                onChange={() => {
                                                    const current = new Set((leaveTypeCreateForm.data.visible_user_ids ?? []).map((id) => Number(id)));
                                                    if (current.has(Number(user.id))) {
                                                        current.delete(Number(user.id));
                                                    } else {
                                                        current.add(Number(user.id));
                                                    }
                                                    leaveTypeCreateForm.setData('visible_user_ids', Array.from(current));
                                                }}
                                                className="h-4 w-4"
                                            />
                                            <span>{user.label}</span>
                                        </label>
                                    ))}
                                </div>
                            ) : null}
                        </div>
                        <div className="md:col-span-4">
                            <button
                                type="submit"
                                disabled={leaveTypeCreateForm.processing}
                                className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Ajouter un type
                            </button>
                        </div>
                    </form>

                    <div className="mt-3 space-y-3">
                        {leaveTypes.map((leaveType) => {
                            const edit = leaveTypeEdits[leaveType.id] ?? {
                                name: leaveType.name,
                                max_days: leaveType.max_days ?? '',
                                is_unlimited: leaveType.max_days === null,
                                is_active: leaveType.is_active,
                                visibility_mode: leaveType.visibility_mode ?? 'all',
                                visible_user_ids: Array.isArray(leaveType.visible_user_ids) ? leaveType.visible_user_ids : [],
                            };

                            return (
                                <form
                                    key={leaveType.id}
                                    className="grid gap-2 rounded-xl border border-[var(--app-border)] p-3 md:grid-cols-4"
                                    onSubmit={(event) => submitLeaveTypeUpdate(event, leaveType.id)}
                                >
                                    <input
                                        type="text"
                                        value={edit.name}
                                        onChange={(event) => updateLeaveTypeEdit(leaveType.id, { name: event.target.value })}
                                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)] md:col-span-2"
                                    />
                                    <input
                                        type="number"
                                        value={edit.sort_order}
                                        onChange={(event) => updateLeaveTypeEdit(leaveType.id, { sort_order: Number(event.target.value || 0) })}
                                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                                    />
                                    <input
                                        type="number"
                                        min={1}
                                        value={edit.max_days}
                                        onChange={(event) => updateLeaveTypeEdit(leaveType.id, { max_days: Number(event.target.value || 1) })}
                                        disabled={Boolean(edit.is_unlimited)}
                                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                                    />
                                    <label className="flex items-center gap-2 rounded-lg border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)]">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(edit.is_unlimited)}
                                            onChange={(event) => updateLeaveTypeEdit(leaveType.id, {
                                                is_unlimited: event.target.checked,
                                                max_days: event.target.checked ? '' : (edit.max_days || 1),
                                            })}
                                            className="h-4 w-4 rounded border-[var(--app-border)]"
                                        />
                                        <span>Sans limite</span>
                                    </label>
                                    <label className="flex items-center gap-2 rounded-lg border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)]">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(edit.is_active)}
                                            onChange={(event) => updateLeaveTypeEdit(leaveType.id, { is_active: event.target.checked })}
                                            className="h-4 w-4 rounded border-[var(--app-border)]"
                                        />
                                        <span>Actif</span>
                                    </label>
                                    <div className="rounded-lg border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)] md:col-span-4">
                                        <p className="mb-2 font-semibold">Visibilité</p>
                                        <div className="flex flex-wrap gap-3">
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="radio"
                                                    name={`visibility_mode_${leaveType.id}`}
                                                    value="all"
                                                    checked={(edit.visibility_mode ?? 'all') === 'all'}
                                                    onChange={() => updateLeaveTypeEdit(leaveType.id, { visibility_mode: 'all' })}
                                                    className="h-4 w-4"
                                                />
                                                <span>Visible par tous</span>
                                            </label>
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="radio"
                                                    name={`visibility_mode_${leaveType.id}`}
                                                    value="selected"
                                                    checked={(edit.visibility_mode ?? 'all') === 'selected'}
                                                    onChange={() => updateLeaveTypeEdit(leaveType.id, { visibility_mode: 'selected' })}
                                                    className="h-4 w-4"
                                                />
                                                <span>Visible uniquement par certains utilisateurs</span>
                                            </label>
                                        </div>

                                        {(edit.visibility_mode ?? 'all') === 'selected' ? (
                                            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                {users.map((user) => (
                                                    <label key={`edit-leave-type-${leaveType.id}-visible-${user.id}`} className="flex items-center gap-2 rounded-lg border border-[var(--app-border)] px-2 py-1.5">
                                                        <input
                                                            type="checkbox"
                                                            checked={(edit.visible_user_ids ?? []).includes(Number(user.id))}
                                                            onChange={() => {
                                                                const current = new Set((edit.visible_user_ids ?? []).map((id) => Number(id)));
                                                                if (current.has(Number(user.id))) {
                                                                    current.delete(Number(user.id));
                                                                } else {
                                                                    current.add(Number(user.id));
                                                                }
                                                                updateLeaveTypeEdit(leaveType.id, { visible_user_ids: Array.from(current) });
                                                            }}
                                                            className="h-4 w-4"
                                                        />
                                                        <span>{user.label}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        ) : null}
                                    </div>
                                    <div className="md:col-span-4">
                                        <button
                                            type="submit"
                                            className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)]"
                                        >
                                            Enregistrer
                                        </button>
                                    </div>
                                </form>
                            );
                        })}
                    </div>
                </section>
            </div>

            <ValidationGroupModal
                show={Boolean(validationGroupModal)}
                mode={validationGroupModal?.mode ?? 'create'}
                form={validationGroupForm}
                users={validationGroupUsers}
                groupByUser={validationGroupByUser}
                editingGroupId={validationGroupModal?.group?.id ?? null}
                onClose={closeValidationGroupModal}
                onSubmit={submitValidationGroup}
            />

            <DeleteValidationGroupModal
                group={validationGroupToDelete}
                processing={isDeletingValidationGroup}
                onClose={() => setValidationGroupToDelete(null)}
                onConfirm={confirmValidationGroupDelete}
            />
        </AdminLayout>
    );
}
