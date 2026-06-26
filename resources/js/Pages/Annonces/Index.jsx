import AppLayout from '@/Layouts/AppLayout';
import RichTextEditor from '@/Components/RichTextEditor';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, Megaphone, MessageSquare, Pencil, Plus, Send, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

function emptyFormState() {
    return {
        body_html: '',
        sector_ids: [],
        user_ids: [],
        excluded_user_ids: [],
        has_poll: false,
        poll_type: 'single',
        poll_allow_other: false,
        poll_options: [''],
        send_mode: 'send_now',
        scheduled_at: '',
    };
}

function formFromAnnouncement(announcement) {
    if (!announcement) return emptyFormState();

    return {
        body_html: announcement.body_html || '',
        sector_ids: announcement.sector_ids || [],
        user_ids: announcement.user_ids || [],
        excluded_user_ids: announcement.excluded_user_ids || [],
        has_poll: Boolean(announcement.poll),
        poll_type: announcement.poll?.poll_type || 'single',
        poll_allow_other: Boolean(announcement.poll?.allow_other),
        poll_options: announcement.poll?.options?.length ? announcement.poll.options : [''],
        send_mode: announcement.status === 'scheduled' ? 'schedule' : 'send_now',
        scheduled_at: announcement.status === 'scheduled' ? (announcement.scheduled_at || '').slice(0, 16) : '',
    };
}

function statusLabel(status) {
    if (status === 'draft') return 'Brouillon';
    if (status === 'scheduled') return 'Programmée';
    if (status === 'sent') return 'Envoyée';
    return status || '';
}

function statusBadgeClass(status) {
    if (status === 'sent') return 'bg-emerald-100 text-emerald-800';
    if (status === 'scheduled') return 'bg-amber-100 text-amber-800';
    return 'bg-gray-200 text-gray-700';
}

function htmlToPlainText(html) {
    if (!html) return '';
    if (typeof document === 'undefined') return html;

    const container = document.createElement('div');
    container.innerHTML = html;

    return (container.innerText || container.textContent || '').trim();
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function resolveRecipientUsers(sectorIds = [], userIds = [], excludedUserIds = [], users = []) {
    const sectorSet = new Set((sectorIds || []).map(Number));
    const userSet = new Set((userIds || []).map(Number));
    const excludedSet = new Set((excludedUserIds || []).map(Number));

    return users.filter((user) => (
        (sectorSet.has(Number(user.sector_id)) || userSet.has(Number(user.id)))
        && !excludedSet.has(Number(user.id))
    ));
}

function buildSmsHref(phones, message) {
    const numbers = Array.from(new Set(phones.filter(Boolean))).join(',');
    const isIos = typeof navigator !== 'undefined' && /iPad|iPhone|iPod/.test(navigator.userAgent || '');
    const separator = isIos ? '&' : '?';

    return `sms:${numbers}${separator}body=${encodeURIComponent(message || '')}`;
}

function recipientsSummary(item, sectorsById, usersById) {
    const sectorNames = (item.sector_ids || []).map((id) => sectorsById[id]).filter(Boolean);
    const userNames = (item.user_ids || []).map((id) => usersById[id]).filter(Boolean);
    const excludedNames = (item.excluded_user_ids || []).map((id) => usersById[id]).filter(Boolean);

    const parts = [];
    if (sectorNames.length) parts.push(`Secteurs : ${sectorNames.join(', ')}`);
    if (userNames.length) parts.push(`Utilisateurs : ${userNames.join(', ')}`);
    if (excludedNames.length) parts.push(`Exclus : ${excludedNames.join(', ')}`);

    return parts.length ? parts : ['Aucun destinataire sélectionné'];
}

function MultiSelectDropdown({ label, options, selectedIds, onChange, placeholder, searchPlaceholder, emptyLabel }) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const wrapperRef = useRef(null);

    useEffect(() => {
        const closeOnOutsideClick = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', closeOnOutsideClick);

        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
        };
    }, []);

    const selectedSet = useMemo(() => new Set((selectedIds || []).map(Number)), [selectedIds]);
    const selectedOptions = useMemo(
        () => options.filter((option) => selectedSet.has(Number(option.id))),
        [options, selectedSet],
    );
    const filteredOptions = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase('fr');
        if (!needle) return options;

        return options.filter((option) => option.label.toLocaleLowerCase('fr').includes(needle));
    }, [options, search]);

    const toggleOption = (id) => {
        const numericId = Number(id);
        const nextIds = selectedSet.has(numericId)
            ? (selectedIds || []).filter((value) => Number(value) !== numericId)
            : [...(selectedIds || []), numericId];

        onChange(nextIds);
    };

    const removeOption = (id) => {
        const numericId = Number(id);
        onChange((selectedIds || []).filter((value) => Number(value) !== numericId));
    };

    return (
        <div ref={wrapperRef} className="relative">
            <span className="mb-2 block text-sm font-semibold">{label}</span>
            <div
                role="button"
                tabIndex={0}
                onClick={() => setOpen((value) => !value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        setOpen((value) => !value);
                    }
                }}
                className="flex min-h-12 w-full cursor-pointer items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-left transition focus:outline-none focus:ring-2 focus:ring-[var(--brand-yellow-dark)]"
            >
                <div className="flex min-w-0 flex-1 flex-wrap gap-1.5">
                    {selectedOptions.length ? selectedOptions.map((option) => (
                        <span
                            key={option.id}
                            className="inline-flex max-w-full items-center gap-1 rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-xs font-semibold"
                        >
                            <span className="truncate">{option.label}</span>
                            <button
                                type="button"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    removeOption(option.id);
                                }}
                                aria-label={`Retirer ${option.label}`}
                                className="rounded-full text-[var(--app-muted)] hover:text-red-600"
                            >
                                <X className="h-3 w-3" />
                            </button>
                        </span>
                    )) : (
                        <span className="text-sm text-[var(--app-muted)]">{placeholder}</span>
                    )}
                </div>
                <ChevronDown className={`h-4 w-4 shrink-0 text-[var(--app-muted)] transition ${open ? 'rotate-180' : ''}`} />
            </div>

            {open ? (
                <div className="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl">
                    <div className="border-b border-[var(--app-border)] p-2">
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onClick={(event) => event.stopPropagation()}
                            placeholder={searchPlaceholder}
                            className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                    </div>
                    <div className="max-h-56 overflow-y-auto p-2">
                        {filteredOptions.map((option) => (
                            <label key={option.id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-[var(--app-surface-soft)]">
                                <input
                                    type="checkbox"
                                    checked={selectedSet.has(Number(option.id))}
                                    onChange={() => toggleOption(option.id)}
                                />
                                <span className="min-w-0 flex-1 truncate">{option.label}</span>
                            </label>
                        ))}
                        {filteredOptions.length === 0 ? (
                            <p className="px-2 py-2 text-xs text-[var(--app-muted)]">{emptyLabel}</p>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function RecipientPicker({ sectors, users, formState, setFormState }) {
    const sectorOptions = useMemo(
        () => sectors.map((sector) => ({ id: sector.id, label: sector.name })),
        [sectors],
    );
    const userOptions = useMemo(
        () => users.map((user) => ({ id: user.id, label: user.name })),
        [users],
    );
    const excludeOptions = useMemo(() => {
        const selectedSectors = new Set((formState.sector_ids || []).map(Number));

        return users
            .filter((user) => selectedSectors.has(Number(user.sector_id)))
            .map((user) => ({ id: user.id, label: user.name }));
    }, [users, formState.sector_ids]);

    const setIds = (key, ids) => {
        setFormState((prev) => ({
            ...prev,
            [key]: ids,
        }));
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <MultiSelectDropdown
                label="Secteurs destinataires"
                options={sectorOptions}
                selectedIds={formState.sector_ids}
                onChange={(ids) => setIds('sector_ids', ids)}
                placeholder="Sélectionner un ou plusieurs secteurs"
                searchPlaceholder="Rechercher un secteur..."
                emptyLabel="Aucun secteur trouvé."
            />

            <MultiSelectDropdown
                label="Utilisateurs destinataires"
                options={userOptions}
                selectedIds={formState.user_ids}
                onChange={(ids) => setIds('user_ids', ids)}
                placeholder="Ajouter des utilisateurs en plus des secteurs"
                searchPlaceholder="Rechercher un utilisateur..."
                emptyLabel="Aucun utilisateur trouvé."
            />

            {formState.sector_ids.length > 0 ? (
                <div className="lg:col-span-2">
                    <MultiSelectDropdown
                        label="Exclure des secteurs sélectionnés"
                        options={excludeOptions}
                        selectedIds={formState.excluded_user_ids}
                        onChange={(ids) => setIds('excluded_user_ids', ids)}
                        placeholder="Sélectionner les utilisateurs à exclure"
                        searchPlaceholder="Rechercher un utilisateur à exclure..."
                        emptyLabel="Aucun utilisateur dans ces secteurs."
                    />
                </div>
            ) : null}
        </div>
    );
}

function RecipientGroups({ groups, formState, setFormState, onSaveGroup, onUpdateGroup, onDeleteGroup }) {
    const [newGroupName, setNewGroupName] = useState('');
    const [editingGroupId, setEditingGroupId] = useState(null);
    const [editingGroupName, setEditingGroupName] = useState('');

    const applyGroup = (group) => {
        setFormState((prev) => ({
            ...prev,
            sector_ids: group.sector_ids || [],
            user_ids: group.user_ids || [],
            excluded_user_ids: group.excluded_user_ids || [],
        }));
    };

    const saveCurrentSelectionAsGroup = () => {
        const name = newGroupName.trim();
        if (!name) return;

        onSaveGroup({
            name,
            sector_ids: formState.sector_ids,
            user_ids: formState.user_ids,
            excluded_user_ids: formState.excluded_user_ids,
        });
        setNewGroupName('');
    };

    const startEditGroup = (group) => {
        applyGroup(group);
        setEditingGroupId(group.id);
        setEditingGroupName(group.name);
    };

    const updateCurrentGroup = () => {
        const name = editingGroupName.trim();
        if (!editingGroupId || !name) return;

        onUpdateGroup(editingGroupId, {
            name,
            sector_ids: formState.sector_ids,
            user_ids: formState.user_ids,
            excluded_user_ids: formState.excluded_user_ids,
        });
        setEditingGroupId(null);
        setEditingGroupName('');
    };

    return (
        <div className="space-y-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
            <span className="block text-sm font-semibold">Groupes de destinataires enregistrés</span>

            {groups.length === 0 ? (
                <p className="text-xs text-[var(--app-muted)]">Aucun groupe enregistré pour le moment.</p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {groups.map((group) => (
                        <div key={group.id} className="inline-flex items-center gap-1 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-sm">
                            <button type="button" onClick={() => applyGroup(group)} className="font-semibold hover:underline">
                                {group.name}
                            </button>
                            <button
                                type="button"
                                onClick={() => startEditGroup(group)}
                                aria-label={`Modifier le groupe ${group.name}`}
                                className="ml-1 text-[var(--app-muted)] hover:text-[var(--brand-brown)]"
                            >
                                <Pencil className="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                onClick={() => onDeleteGroup(group.id)}
                                aria-label={`Supprimer le groupe ${group.name}`}
                                className="ml-1 text-[var(--app-muted)] hover:text-red-600"
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <input
                    type="text"
                    value={newGroupName}
                    onChange={(event) => setNewGroupName(event.target.value)}
                    placeholder="Nom du nouveau groupe (ex: Tous les chauffeurs)"
                    className="w-full max-w-sm rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                />
                <button
                    type="button"
                    onClick={saveCurrentSelectionAsGroup}
                    disabled={!newGroupName.trim()}
                    className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em] disabled:opacity-50"
                >
                    <Plus className="h-3.5 w-3.5" />
                    Enregistrer la sélection actuelle comme groupe
                </button>
            </div>

            {editingGroupId ? (
                <div className="flex flex-wrap items-center gap-2 border-t border-[var(--app-border)] pt-3">
                    <input
                        type="text"
                        value={editingGroupName}
                        onChange={(event) => setEditingGroupName(event.target.value)}
                        className="w-full max-w-sm rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                    />
                    <button
                        type="button"
                        onClick={updateCurrentGroup}
                        disabled={!editingGroupName.trim()}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)] disabled:opacity-50"
                    >
                        Mettre à jour ce groupe
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setEditingGroupId(null);
                            setEditingGroupName('');
                        }}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                    >
                        Annuler
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function PollEditor({ formState, setFormState }) {
    const setOption = (index, value) => {
        setFormState((prev) => ({
            ...prev,
            poll_options: prev.poll_options.map((option, optionIndex) => (optionIndex === index ? value : option)),
        }));
    };

    const addOption = () => {
        setFormState((prev) => ({ ...prev, poll_options: [...prev.poll_options, ''] }));
    };

    const removeOption = (index) => {
        setFormState((prev) => ({
            ...prev,
            poll_options: prev.poll_options.length > 1
                ? prev.poll_options.filter((_, optionIndex) => optionIndex !== index)
                : prev.poll_options,
        }));
    };

    return (
        <div className="space-y-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
            <div className="flex flex-wrap gap-2">
                <label className="inline-flex items-center gap-2 text-sm font-semibold">
                    <input
                        type="radio"
                        name="poll_type"
                        checked={formState.poll_type === 'single'}
                        onChange={() => setFormState((prev) => ({ ...prev, poll_type: 'single' }))}
                    />
                    Réponse unique
                </label>
                <label className="inline-flex items-center gap-2 text-sm font-semibold">
                    <input
                        type="radio"
                        name="poll_type"
                        checked={formState.poll_type === 'multiple'}
                        onChange={() => setFormState((prev) => ({ ...prev, poll_type: 'multiple' }))}
                    />
                    Réponses multiples
                </label>
            </div>

            <div className="space-y-2">
                {formState.poll_options.map((option, index) => (
                    <div key={index} className="flex items-center gap-2">
                        <input
                            type="text"
                            value={option}
                            onChange={(event) => setOption(index, event.target.value)}
                            placeholder={`Réponse ${index + 1}`}
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                        />
                        <button
                            type="button"
                            onClick={() => removeOption(index)}
                            disabled={formState.poll_options.length <= 1}
                            aria-label="Supprimer cette réponse"
                            className="text-[var(--app-muted)] hover:text-red-600 disabled:opacity-30"
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    </div>
                ))}
            </div>

            <button
                type="button"
                onClick={addOption}
                className="inline-flex items-center gap-1.5 rounded-xl border border-dashed border-[var(--app-border)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--brand-brown)]"
            >
                <Plus className="h-3.5 w-3.5" />
                Ajouter une réponse
            </button>

            <label className="flex items-center gap-2 text-sm font-semibold">
                <input
                    type="checkbox"
                    checked={formState.poll_allow_other}
                    onChange={(event) => setFormState((prev) => ({ ...prev, poll_allow_other: event.target.checked }))}
                />
                Ajouter l'option "Autre" (réponse libre)
            </label>
        </div>
    );
}

export default function AnnoncesIndex({
    permissions = {},
    sectors = [],
    users = [],
    recipientGroups = [],
    announcements = [],
    openDraft = null,
    highlightId = null,
}) {
    const page = usePage();
    const { errors = {}, auth = {} } = page.props;
    const currentUserId = auth?.user?.id;
    const canCreate = Boolean(permissions?.can_create);
    const canManage = Boolean(permissions?.can_manage);
    const formRef = useRef(null);

    const [formState, setFormState] = useState(() => formFromAnnouncement(openDraft));
    const [editingId, setEditingId] = useState(openDraft?.id ?? null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (openDraft) {
            setFormState(formFromAnnouncement(openDraft));
            setEditingId(openDraft.id);
            formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [openDraft?.id]);

    const sectorsById = useMemo(() => Object.fromEntries(sectors.map((sector) => [sector.id, sector.name])), [sectors]);
    const usersById = useMemo(() => Object.fromEntries(users.map((user) => [user.id, user.name])), [users]);

    const resetForm = () => {
        setFormState(emptyFormState());
        setEditingId(null);
    };

    const startEdit = (announcement) => {
        setFormState(formFromAnnouncement(announcement));
        setEditingId(announcement.id);
        formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const submitAnnouncement = (mode) => {
        const payload = {
            mode,
            body_html: formState.body_html,
            sector_ids: formState.sector_ids,
            user_ids: formState.user_ids,
            excluded_user_ids: formState.excluded_user_ids,
            scheduled_at: mode === 'schedule' ? formState.scheduled_at : null,
            has_poll: formState.has_poll,
            poll: formState.has_poll ? {
                poll_type: formState.poll_type,
                allow_other: formState.poll_allow_other,
                options: formState.poll_options.map((option) => option.trim()).filter(Boolean),
            } : null,
        };

        setProcessing(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => resetForm(),
            onFinish: () => setProcessing(false),
        };

        if (editingId) {
            router.put(route('annonces.update', editingId), payload, options);
        } else {
            router.post(route('annonces.store'), payload, options);
        }
    };

    const deleteAnnouncement = (id) => {
        if (!window.confirm('Supprimer cette annonce ?')) return;
        router.delete(route('annonces.destroy', id), { preserveScroll: true });
    };

    const duplicateAnnouncement = (id) => {
        router.post(route('annonces.duplicate', id), {}, { preserveScroll: true });
    };

    const saveGroup = (group) => {
        router.post(route('annonces.groups.store'), group, { preserveScroll: true });
    };

    const updateGroup = (id, group) => {
        router.put(route('annonces.groups.update', id), group, { preserveScroll: true });
    };

    const deleteGroup = (id) => {
        if (!window.confirm('Supprimer ce groupe de destinataires ?')) return;
        router.delete(route('annonces.groups.destroy', id), { preserveScroll: true });
    };

    const sendSmsForForm = () => {
        const recipients = resolveRecipientUsers(formState.sector_ids, formState.user_ids, formState.excluded_user_ids, users);
        const phones = recipients.map((user) => user.phone).filter(Boolean);
        const message = htmlToPlainText(formState.body_html);
        window.location.href = buildSmsHref(phones, message);
    };

    const sendSmsForAnnouncement = (item) => {
        const recipients = resolveRecipientUsers(item.sector_ids, item.user_ids, item.excluded_user_ids, users);
        const phones = recipients.map((user) => user.phone).filter(Boolean);
        window.location.href = buildSmsHref(phones, item.body_text || '');
    };

    const canEditAnnouncement = (item) => (
        item.status !== 'sent' && (canManage || Number(item.created_by_user_id) === Number(currentUserId))
    );

    return (
        <AppLayout
            title="Annonces"
            header={(
                <h1 className="flex items-center gap-2 text-[22px] leading-none font-black uppercase tracking-[0.06em]">
                    <Megaphone className="h-5 w-5" strokeWidth={2.3} />
                    Annonces
                </h1>
            )}
        >
            <Head title="Annonces" />

            <div className="space-y-6">
                {canCreate ? (
                    <section ref={formRef} className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-black uppercase tracking-[0.08em]">
                                {editingId ? 'Modifier l\'annonce' : 'Nouvelle annonce'}
                            </h2>
                            {editingId ? (
                                <button
                                    type="button"
                                    onClick={resetForm}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Annuler / Nouvelle annonce
                                </button>
                            ) : null}
                        </div>

                        <div className="space-y-5">
                            <RecipientPicker
                                sectors={sectors}
                                users={users}
                                formState={formState}
                                setFormState={setFormState}
                            />

                            <RecipientGroups
                                groups={recipientGroups}
                                formState={formState}
                                setFormState={setFormState}
                                onSaveGroup={saveGroup}
                                onUpdateGroup={updateGroup}
                                onDeleteGroup={deleteGroup}
                            />

                            {errors.sector_ids ? <p className="text-xs text-red-600">{errors.sector_ids}</p> : null}

                            <div>
                                <span className="mb-2 block text-sm font-semibold">Message</span>
                                <RichTextEditor
                                    value={formState.body_html}
                                    onChange={(html) => setFormState((prev) => ({ ...prev, body_html: html }))}
                                    placeholder="Rédigez votre annonce..."
                                />
                                {errors.body_html ? <p className="mt-1 text-xs text-red-600">{errors.body_html}</p> : null}
                            </div>

                            <div>
                                <label className="flex items-center gap-2 text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={formState.has_poll}
                                        onChange={(event) => setFormState((prev) => ({ ...prev, has_poll: event.target.checked }))}
                                    />
                                    Ajouter un sondage
                                </label>
                                {formState.has_poll ? (
                                    <div className="mt-3">
                                        <PollEditor formState={formState} setFormState={setFormState} />
                                        {errors['poll.options'] ? <p className="mt-1 text-xs text-red-600">{errors['poll.options']}</p> : null}
                                    </div>
                                ) : null}
                            </div>

                            <div>
                                <span className="mb-2 block text-sm font-semibold">Envoi</span>
                                <div className="flex flex-wrap items-center gap-3">
                                    <label className="inline-flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="send_mode"
                                            checked={formState.send_mode === 'send_now'}
                                            onChange={() => setFormState((prev) => ({ ...prev, send_mode: 'send_now' }))}
                                        />
                                        Envoyer immédiatement
                                    </label>
                                    <label className="inline-flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="send_mode"
                                            checked={formState.send_mode === 'schedule'}
                                            onChange={() => setFormState((prev) => ({ ...prev, send_mode: 'schedule' }))}
                                        />
                                        Programmer l'envoi
                                    </label>
                                    {formState.send_mode === 'schedule' ? (
                                        <input
                                            type="datetime-local"
                                            value={formState.scheduled_at}
                                            onChange={(event) => setFormState((prev) => ({ ...prev, scheduled_at: event.target.value }))}
                                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                        />
                                    ) : null}
                                </div>
                                {errors.scheduled_at ? <p className="mt-1 text-xs text-red-600">{errors.scheduled_at}</p> : null}
                            </div>

                            <div className="flex flex-wrap gap-2 border-t border-[var(--app-border)] pt-4">
                                <button
                                    type="button"
                                    onClick={() => submitAnnouncement('draft')}
                                    disabled={processing}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.08em] disabled:opacity-60"
                                >
                                    Enregistrer le brouillon
                                </button>
                                <button
                                    type="button"
                                    onClick={() => submitAnnouncement(formState.send_mode)}
                                    disabled={processing}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)] disabled:opacity-60"
                                >
                                    <Send className="h-3.5 w-3.5" />
                                    {formState.send_mode === 'schedule' ? 'Programmer' : 'Envoyer'}
                                </button>
                                <button
                                    type="button"
                                    onClick={sendSmsForForm}
                                    disabled={processing}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 py-2 text-xs font-black uppercase tracking-[0.08em] disabled:opacity-60"
                                >
                                    <MessageSquare className="h-3.5 w-3.5" />
                                    SMS
                                </button>
                            </div>
                        </div>
                    </section>
                ) : null}

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                    <h2 className="mb-4 text-base font-black uppercase tracking-[0.08em]">Historique</h2>

                    {announcements.length === 0 ? (
                        <p className="text-sm text-[var(--app-muted)]">Aucune annonce pour le moment.</p>
                    ) : (
                        <div className="space-y-3">
                            {announcements.map((item) => (
                                <article
                                    key={item.id}
                                    className={`rounded-xl border p-3 ${highlightId === item.id ? 'border-[var(--brand-yellow-dark)] ring-2 ring-[var(--brand-yellow-dark)]' : 'border-[var(--app-border)]'}`}
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-black uppercase tracking-[0.06em] ${statusBadgeClass(item.status)}`}>
                                                {statusLabel(item.status)}
                                            </span>
                                            <span className="text-xs text-[var(--app-muted)]">
                                                {item.status === 'scheduled'
                                                    ? `Programmée pour le ${formatDateTime(item.scheduled_at)}`
                                                    : item.status === 'sent'
                                                        ? `Envoyée le ${formatDateTime(item.sent_at)}`
                                                        : `Créée le ${formatDateTime(item.created_at)}`}
                                            </span>
                                        </div>
                                        {canManage && item.created_by ? (
                                            <span className="text-xs text-[var(--app-muted)]">Par {item.created_by}</span>
                                        ) : null}
                                    </div>

                                    <p className="mt-2 whitespace-pre-line text-sm">{item.body_text || '(message vide)'}</p>

                                    <div className="mt-2 space-y-0.5">
                                        {recipientsSummary(item, sectorsById, usersById).map((line) => (
                                            <p key={line} className="text-xs text-[var(--app-muted)]">{line}</p>
                                        ))}
                                    </div>

                                    {item.poll ? (
                                        <div className="mt-2 rounded-lg bg-[var(--app-surface-soft)] p-2 text-xs">
                                            <span className="font-semibold">
                                                Sondage ({item.poll.poll_type === 'multiple' ? 'réponses multiples' : 'réponse unique'}) :
                                            </span>
                                            {' '}
                                            {item.poll.options.join(', ')}
                                            {item.poll.allow_other ? ' + Autre' : ''}
                                        </div>
                                    ) : null}

                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {canCreate && canEditAnnouncement(item) ? (
                                            <button
                                                type="button"
                                                onClick={() => startEdit(item)}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold"
                                            >
                                                <Pencil className="h-3.5 w-3.5" />
                                                Modifier
                                            </button>
                                        ) : null}
                                        {canCreate ? (
                                            <button
                                                type="button"
                                                onClick={() => duplicateAnnouncement(item.id)}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold"
                                            >
                                                Reprendre cette annonce
                                            </button>
                                        ) : null}
                                        <button
                                            type="button"
                                            onClick={() => sendSmsForAnnouncement(item)}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold"
                                        >
                                            <MessageSquare className="h-3.5 w-3.5" />
                                            SMS
                                        </button>
                                        {canCreate && canEditAnnouncement(item) ? (
                                            <button
                                                type="button"
                                                onClick={() => deleteAnnouncement(item.id)}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-transparent px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:border-red-200 hover:bg-red-50"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                                Supprimer
                                            </button>
                                        ) : null}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
