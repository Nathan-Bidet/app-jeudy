import AppLayout from '@/Layouts/AppLayout';
import Modal from '@/Components/Modal';
import AnnouncementBody from '@/Components/Announcements/AnnouncementBody';
import PollDisplay from '@/Components/Announcements/PollDisplay';
import RichTextEditor from '@/Components/RichTextEditor';
import { Head, router, usePage } from '@inertiajs/react';
import { Bell, ChevronDown, Megaphone, MessageSquare, Pencil, Plus, Send, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

function emptyFormState() {
    return {
        title: '',
        body_html: '',
        sector_ids: [],
        user_ids: [],
        excluded_user_ids: [],
        has_poll: false,
        poll_type: 'single',
        poll_title: '',
        poll_allow_other: false,
        poll_other_label: 'Autre',
        poll_options: [''],
        send_mode: 'send_now',
        scheduled_at: '',
        show_on_dashboard: false,
        dashboard_expires_at: '',
    };
}

function formFromAnnouncement(announcement) {
    if (!announcement) return emptyFormState();
    const pollOptions = announcement.poll?.options?.map((option) => (
        typeof option === 'string' ? option : option.label
    )).filter(Boolean);

    return {
        title: announcement.title || '',
        body_html: announcement.body_html || '',
        sector_ids: announcement.sector_ids || [],
        user_ids: announcement.user_ids || [],
        excluded_user_ids: announcement.excluded_user_ids || [],
        has_poll: Boolean(announcement.poll),
        poll_type: announcement.poll?.poll_type || 'single',
        poll_title: announcement.poll?.title || '',
        poll_allow_other: Boolean(announcement.poll?.allow_other),
        poll_other_label: announcement.poll?.other_label || 'Autre',
        poll_options: pollOptions?.length ? pollOptions : [''],
        send_mode: announcement.status === 'scheduled' ? 'schedule' : 'send_now',
        scheduled_at: announcement.status === 'scheduled' ? (announcement.scheduled_at || '').slice(0, 16) : '',
        show_on_dashboard: Boolean(announcement.show_on_dashboard),
        dashboard_expires_at: announcement.dashboard_expires_at ? announcement.dashboard_expires_at.slice(0, 10) : '',
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

function htmlNodeToSmsText(node) {
    if (!node) return '';

    if (node.nodeType === Node.TEXT_NODE) {
        return node.nodeValue || '';
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        return '';
    }

    const tagName = node.tagName.toLowerCase();
    const content = Array.from(node.childNodes).map(htmlNodeToSmsText).join('');

    if (tagName === 'br') return '\n';
    if (tagName === 'p' || tagName === 'div') return `${content}\n`;

    return content;
}

function htmlToSmsText(html) {
    if (!html) return '';
    if (typeof document === 'undefined') return html;

    const container = document.createElement('div');
    container.innerHTML = html;
    const text = Array.from(container.childNodes).map(htmlNodeToSmsText).join('');

    return text.replace(/\n{3,}/g, '\n\n').trim();
}

function smsMessageWithTitle(title, message) {
    const cleanTitle = String(title || '').trim();
    const cleanMessage = String(message || '').trim();

    if (!cleanTitle) return cleanMessage;
    if (!cleanMessage) return cleanTitle;

    return `${cleanTitle}\n\n${cleanMessage}`;
}

function pollToSmsText(poll) {
    if (!poll) return '';

    const options = (poll.options || [])
        .map((option) => (typeof option === 'string' ? option : option?.label))
        .map((label) => String(label || '').trim())
        .filter(Boolean);

    if (poll.allow_other) {
        const otherLabel = String(poll.other_label || 'Autre').trim();
        if (otherLabel) options.push(otherLabel);
    }

    if (options.length === 0) return '';

    const heading = poll.poll_type === 'multiple'
        ? 'Sondage — plusieurs réponses possibles :'
        : 'Sondage — une seule réponse possible :';

    const titleLine = String(poll.title || '').trim();
    const lines = titleLine ? [titleLine, heading] : [heading];

    return [...lines, ...options.map((option) => `• ${option}`)].join('\n');
}

function smsMessageWithTitleAndPoll(title, message, poll) {
    const baseMessage = smsMessageWithTitle(title, message);
    const pollText = pollToSmsText(poll);

    if (!pollText) return baseMessage;
    if (!baseMessage) return pollText;

    return `${baseMessage}\n\n${pollText}`;
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
    const recipientsById = new Map();

    (users || []).forEach((user) => {
        const userId = Number(user?.id);
        if (!userId || excludedSet.has(userId)) return;

        if (sectorSet.has(Number(user?.sector_id)) || userSet.has(userId)) {
            recipientsById.set(userId, user);
        }
    });

    return Array.from(recipientsById.values());
}

function normalizeSmsPhone(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';

    const withoutSpaces = raw.replace(/\s+/g, '');
    const normalized = withoutSpaces.startsWith('+')
        ? `+${withoutSpaces.slice(1).replace(/\D/g, '')}`
        : withoutSpaces.replace(/\D/g, '');

    const national = normalized.startsWith('+33')
        ? `0${normalized.slice(3)}`
        : normalized;

    return /^0[67]\d{8}$/.test(national) ? normalized : '';
}

function firstSmsMobileForUser(user) {
    const primaryMobile = normalizeSmsPhone(user?.mobile_phone);
    if (primaryMobile) return primaryMobile;

    const extraPhones = Array.isArray(user?.directory_phones) ? user.directory_phones : [];
    for (const row of extraPhones) {
        const mobile = normalizeSmsPhone(row?.number);
        if (mobile) return mobile;
    }

    return '';
}

function smsPlatform() {
    if (typeof navigator === 'undefined') return 'other';

    const userAgent = navigator.userAgent || '';
    const platform = navigator.platform || '';

    if (/iPad|iPhone|iPod|Macintosh|MacIntel|MacPPC|Mac68K/i.test(`${userAgent} ${platform}`)) {
        return 'apple';
    }

    return 'other';
}

function buildSmsHref(phones, message) {
    const numbers = Array.from(new Set((phones || []).map(normalizeSmsPhone).filter(Boolean)));
    if (numbers.length === 0) {
        return null;
    }

    const isApple = smsPlatform() === 'apple';
    if (isApple) {
        return `sms:/open?addresses=${numbers.map(encodeURIComponent).join(',')}&body=${encodeURIComponent(message || '')}`;
    }

    return `sms:${numbers.join(';')}?body=${encodeURIComponent(message || '')}`;
}

function smsPhonesForRecipients(recipients) {
    return Array.from(new Set((recipients || []).map(firstSmsMobileForUser).filter(Boolean)));
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
    const [selectedGroupId, setSelectedGroupId] = useState('');
    const [showSaveModal, setShowSaveModal] = useState(false);
    const [groupModalMode, setGroupModalMode] = useState('create');
    const [groupName, setGroupName] = useState('');

    const applyGroup = (group) => {
        if (!group) return;

        setFormState((prev) => ({
            ...prev,
            sector_ids: group.sector_ids || [],
            user_ids: group.user_ids || [],
            excluded_user_ids: group.excluded_user_ids || [],
        }));
    };

    const selectedGroup = groups.find((group) => Number(group.id) === Number(selectedGroupId));

    const loadSelectedGroup = () => {
        applyGroup(selectedGroup);
    };

    const openSaveModal = () => {
        setGroupModalMode('create');
        setGroupName('');
        setShowSaveModal(true);
    };

    const openEditModal = () => {
        if (!selectedGroup) return;
        setGroupModalMode('update');
        setGroupName(selectedGroup.name || '');
        setShowSaveModal(true);
    };

    const saveCurrentSelectionAsGroup = () => {
        const name = groupName.trim();
        if (!name) return;

        const payload = {
            name,
            sector_ids: formState.sector_ids,
            user_ids: formState.user_ids,
            excluded_user_ids: formState.excluded_user_ids,
        };

        if (groupModalMode === 'update' && selectedGroup) {
            onUpdateGroup(selectedGroup.id, payload);
        } else {
            onSaveGroup(payload);
        }

        setGroupName('');
        setShowSaveModal(false);
    };

    const deleteSelectedGroup = () => {
        if (!selectedGroup) return;
        onDeleteGroup(selectedGroup.id);
        setSelectedGroupId('');
    };

    return (
        <>
            <div className="flex flex-wrap items-center gap-2">
                <select
                    value={selectedGroupId}
                    onChange={(event) => setSelectedGroupId(event.target.value)}
                    className="h-10 min-w-48 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 text-sm"
                >
                    <option value="">Groupe</option>
                    {groups.map((group) => (
                        <option key={group.id} value={group.id}>{group.name}</option>
                    ))}
                </select>
                <button
                    type="button"
                    onClick={loadSelectedGroup}
                    disabled={!selectedGroup}
                    className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 text-xs font-black uppercase tracking-[0.08em] disabled:opacity-50"
                >
                    Charger
                </button>
                <button
                    type="button"
                    onClick={openSaveModal}
                    className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 text-xs font-black uppercase tracking-[0.08em]"
                >
                    <Plus className="h-3.5 w-3.5" />
                    Enregistrer la sélection
                </button>
                {selectedGroup ? (
                    <>
                        <button
                            type="button"
                            onClick={openEditModal}
                            aria-label={`Modifier le groupe ${selectedGroup.name}`}
                            className="inline-flex h-10 items-center rounded-xl border border-transparent px-2 text-[var(--app-muted)] hover:border-[var(--app-border)] hover:bg-[var(--app-surface-soft)] hover:text-[var(--brand-brown)]"
                        >
                            <Pencil className="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            onClick={deleteSelectedGroup}
                            aria-label={`Supprimer le groupe ${selectedGroup.name}`}
                            className="inline-flex h-10 items-center rounded-xl border border-transparent px-2 text-[var(--app-muted)] hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    </>
                ) : null}
            </div>

            <Modal show={showSaveModal} onClose={() => setShowSaveModal(false)} maxWidth="md">
                <div className="border-b border-[var(--app-border)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                        {groupModalMode === 'update' ? 'Modifier le groupe' : 'Enregistrer un groupe'}
                    </h3>
                </div>
                <div className="space-y-4 px-5 py-4">
                    <div>
                        <span className="mb-2 block text-sm font-semibold">Nom du groupe</span>
                        <input
                            type="text"
                            value={groupName}
                            onChange={(event) => setGroupName(event.target.value)}
                            placeholder="Ex: Tous les chauffeurs"
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            autoFocus
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setShowSaveModal(false)}
                            className="rounded-xl border border-[var(--app-border)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            onClick={saveCurrentSelectionAsGroup}
                            disabled={!groupName.trim()}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)] disabled:opacity-50"
                        >
                            {groupModalMode === 'update' ? 'Mettre à jour' : 'Enregistrer'}
                        </button>
                    </div>
                </div>
            </Modal>
        </>
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

    const toggleOtherOption = (checked) => {
        setFormState((prev) => ({
            ...prev,
            poll_allow_other: checked,
            poll_other_label: checked && !prev.poll_other_label.trim() ? 'Autre' : prev.poll_other_label,
        }));
    };

    return (
        <div className="space-y-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
            <div>
                <span className="mb-1.5 block text-sm font-semibold">Titre du sondage</span>
                <input
                    type="text"
                    value={formState.poll_title}
                    onChange={(event) => setFormState((prev) => ({ ...prev, poll_title: event.target.value }))}
                    placeholder="Ex. Présence repas de fin d'année"
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                />
            </div>

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
                {formState.poll_allow_other ? (
                    <div className="flex items-center gap-2">
                        <input
                            type="text"
                            value={formState.poll_other_label}
                            onChange={(event) => setFormState((prev) => ({ ...prev, poll_other_label: event.target.value }))}
                            placeholder="Libellé de la réponse libre"
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                        />
                    </div>
                ) : null}
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
                    onChange={(event) => toggleOtherOption(event.target.checked)}
                />
                Ajouter l'option "Autre" (réponse libre)
            </label>

        </div>
    );
}

function AnnouncementDetailModal({ announcement, onClose, onSubmitPollResponse, pollResponseProcessing, errors = {} }) {
    const poll = announcement?.poll;

    if (!announcement) return null;

    const submitPollResponse = (payload) => {
        onSubmitPollResponse(announcement, payload);
    };

    return (
        <Modal show={Boolean(announcement)} onClose={onClose} maxWidth="2xl">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h3 className="text-base font-black uppercase tracking-[0.08em]">Détail de l'annonce</h3>
                        <p className="mt-1 text-xs text-[var(--app-muted)]">
                            Envoyée le {formatDateTime(announcement.sent_at || announcement.created_at)}
                            {announcement.created_by ? ` par ${announcement.created_by}` : ''}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-[var(--app-border)] px-2 py-1 text-xs font-semibold"
                    >
                        Fermer
                    </button>
                </div>
            </div>

            <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4 text-sm">
                {announcement.title ? (
                    <h4 className="text-lg font-black text-[var(--app-text)]">{announcement.title}</h4>
                ) : null}

                <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                    <AnnouncementBody html={announcement.body_html} />
                </div>

                <PollDisplay
                    poll={poll}
                    variant="full"
                    onSubmitResponse={submitPollResponse}
                    responseProcessing={pollResponseProcessing}
                    errors={errors}
                />
            </div>
        </Modal>
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
    highlightAnnouncement = null,
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
    const [detailAnnouncement, setDetailAnnouncement] = useState(null);
    const [pollResponseProcessing, setPollResponseProcessing] = useState(false);
    const [pendingSmsHref, setPendingSmsHref] = useState(null);

    useEffect(() => {
        if (openDraft) {
            setFormState(formFromAnnouncement(openDraft));
            setEditingId(openDraft.id);
            formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [openDraft?.id]);

    useEffect(() => {
        if (!highlightId) return;
        const highlighted = announcements.find((item) => Number(item.id) === Number(highlightId));
        if (highlighted || highlightAnnouncement) {
            setDetailAnnouncement(highlighted || highlightAnnouncement);
        }
    }, [announcements, highlightId, highlightAnnouncement]);

    useEffect(() => {
        if (!detailAnnouncement) return;
        const refreshed = announcements.find((item) => Number(item.id) === Number(detailAnnouncement.id))
            || (Number(highlightAnnouncement?.id) === Number(detailAnnouncement.id) ? highlightAnnouncement : null);

        if (refreshed && refreshed !== detailAnnouncement) {
            setDetailAnnouncement(refreshed);
        }
    }, [announcements, highlightAnnouncement, detailAnnouncement]);

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
            title: formState.title,
            body_html: formState.body_html,
            sector_ids: formState.sector_ids,
            user_ids: formState.user_ids,
            excluded_user_ids: formState.excluded_user_ids,
            scheduled_at: mode === 'schedule' ? formState.scheduled_at : null,
            show_on_dashboard: formState.show_on_dashboard,
            dashboard_expires_at: formState.show_on_dashboard && formState.dashboard_expires_at ? formState.dashboard_expires_at : null,
            has_poll: formState.has_poll,
            poll: formState.has_poll ? {
                poll_type: formState.poll_type,
                title: formState.poll_title.trim(),
                allow_other: formState.poll_allow_other,
                other_label: formState.poll_other_label,
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

    const [pushSending, setPushSending] = useState(null);

    const sendPushForAnnouncement = (id) => {
        if (pushSending === id) return;
        setPushSending(id);
        router.post(
            route('annonces.send-push', id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setPushSending(null),
            },
        );
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

    const submitPollResponse = (announcement, payload) => {
        setPollResponseProcessing(true);
        router.post(route('annonces.poll-response', announcement.id), payload, {
            preserveScroll: true,
            onFinish: () => setPollResponseProcessing(false),
        });
    };

    const sendSmsForForm = () => {
        const recipients = resolveRecipientUsers(formState.sector_ids, formState.user_ids, formState.excluded_user_ids, users);
        const phones = smsPhonesForRecipients(recipients);
        const poll = formState.has_poll ? {
            poll_type: formState.poll_type,
            title: formState.poll_title,
            allow_other: formState.poll_allow_other,
            other_label: formState.poll_other_label,
            options: formState.poll_options,
        } : null;
        const message = smsMessageWithTitleAndPoll(formState.title, htmlToSmsText(formState.body_html), poll);
        const href = buildSmsHref(phones, message);
        if (href) setPendingSmsHref(href);
    };

    const sendSmsForAnnouncement = (item) => {
        const recipients = resolveRecipientUsers(item.sector_ids, item.user_ids, item.excluded_user_ids, users);
        const phones = smsPhonesForRecipients(recipients);
        const message = smsMessageWithTitleAndPoll(
            item.title,
            htmlToSmsText(item.body_html || item.body_text || ''),
            item.poll,
        );
        const href = buildSmsHref(phones, message);
        if (href) setPendingSmsHref(href);
    };

    const confirmOpenSms = () => {
        if (!pendingSmsHref) return;
        const href = pendingSmsHref;
        setPendingSmsHref(null);
        window.location.href = href;
    };

    const canEditAnnouncement = (item) => (
        item.status !== 'sent' && (canManage || Number(item.created_by_user_id) === Number(currentUserId))
    );

    const [dashboardModal, setDashboardModal] = useState(null);

    const openDashboardModal = (item) => {
        setDashboardModal({
            id: item.id,
            show_on_dashboard: item.show_on_dashboard,
            dashboard_expires_at: item.dashboard_expires_at ? item.dashboard_expires_at.slice(0, 10) : '',
        });
    };

    const submitDashboardUpdate = (show, expiresAt) => {
        if (!dashboardModal) return;
        router.patch(
            route('annonces.dashboard', dashboardModal.id),
            { show_on_dashboard: show, dashboard_expires_at: expiresAt || null },
            {
                preserveScroll: true,
                onSuccess: () => setDashboardModal(null),
            },
        );
    };

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
                                <span className="mb-2 block text-sm font-semibold">Titre de l'annonce</span>
                                <input
                                    type="text"
                                    value={formState.title}
                                    onChange={(event) => setFormState((prev) => ({ ...prev, title: event.target.value }))}
                                    placeholder="Titre optionnel"
                                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                />
                                {errors.title ? <p className="mt-1 text-xs text-red-600">{errors.title}</p> : null}
                            </div>

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

                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <label className="inline-flex items-center gap-2 text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={formState.show_on_dashboard}
                                        onChange={(event) => setFormState((prev) => ({ ...prev, show_on_dashboard: event.target.checked, dashboard_expires_at: event.target.checked ? prev.dashboard_expires_at : '' }))}
                                    />
                                    Afficher sur la page d'accueil
                                </label>
                                {formState.show_on_dashboard ? (
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm text-[var(--app-muted)]">Date de fin d'affichage</span>
                                        <input
                                            type="date"
                                            value={formState.dashboard_expires_at}
                                            onChange={(event) => setFormState((prev) => ({ ...prev, dashboard_expires_at: event.target.value }))}
                                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                        />
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

                                    {item.title ? (
                                        <h3 className="mt-2 text-sm font-black text-[var(--app-text)]">{item.title}</h3>
                                    ) : null}
                                    <AnnouncementBody html={item.body_html} className="mt-2" />

                                    <div className="mt-2 space-y-0.5">
                                        {recipientsSummary(item, sectorsById, usersById).map((line) => (
                                            <p key={line} className="text-xs text-[var(--app-muted)]">{line}</p>
                                        ))}
                                    </div>

                                    {item.poll ? (
                                        <div className="mt-2 rounded-lg bg-[var(--app-surface-soft)] p-2 text-xs">
                                            {item.poll.title ? (
                                                <div className="text-sm font-bold">{item.poll.title}</div>
                                            ) : null}
                                            <span className="font-semibold">
                                                Sondage ({item.poll.poll_type === 'multiple' ? 'réponses multiples' : 'réponse unique'}) :
                                            </span>
                                            {' '}
                                            {item.poll.options.map((option) => option.label).join(', ')}
                                            {item.poll.allow_other ? ` + ${item.poll.other_label || 'Autre'}` : ''}
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
                                        {canCreate && item.status === 'sent' ? (
                                            <button
                                                type="button"
                                                onClick={() => openDashboardModal(item)}
                                                className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold ${item.is_dashboard_active ? 'border-[#F1BF0C] bg-[#F1BF0C]/10 text-[var(--app-text)]' : 'border-[var(--app-border)]'}`}
                                            >
                                                {item.is_dashboard_active ? '★ Accueil' : 'Accueil'}
                                            </button>
                                        ) : null}
                                        {canCreate && item.status === 'sent' ? (
                                            <button
                                                type="button"
                                                onClick={() => sendPushForAnnouncement(item.id)}
                                                disabled={pushSending === item.id}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold disabled:opacity-50"
                                            >
                                                <Bell className="h-3.5 w-3.5" />
                                                {pushSending === item.id ? 'Envoi…' : 'Notifier'}
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
                                        <button
                                            type="button"
                                            onClick={() => setDetailAnnouncement(item)}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--app-border)] px-2.5 py-1.5 text-xs font-semibold"
                                        >
                                            {item.poll?.results ? 'Voir les réponses' : 'Détail'}
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

            <AnnouncementDetailModal
                announcement={detailAnnouncement}
                onClose={() => setDetailAnnouncement(null)}
                onSubmitPollResponse={submitPollResponse}
                pollResponseProcessing={pollResponseProcessing}
                errors={errors}
            />

            <Modal show={Boolean(dashboardModal)} onClose={() => setDashboardModal(null)} maxWidth="sm">
                <div className="border-b border-[var(--app-border)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Affichage sur la page d'accueil</h3>
                </div>
                <div className="space-y-4 px-5 py-4">
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => submitDashboardUpdate(true, dashboardModal?.dashboard_expires_at || null)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-[#F1BF0C] bg-[#F1BF0C]/10 px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                        >
                            Afficher sur l'accueil
                        </button>
                        <button
                            type="button"
                            onClick={() => submitDashboardUpdate(false, null)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                        >
                            Retirer de l'accueil
                        </button>
                    </div>
                    <div>
                        <span className="mb-1.5 block text-sm font-semibold">Date de fin d'affichage (facultative)</span>
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                type="date"
                                value={dashboardModal?.dashboard_expires_at || ''}
                                onChange={(event) => setDashboardModal((prev) => prev ? { ...prev, dashboard_expires_at: event.target.value } : null)}
                                className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            />
                            {dashboardModal?.dashboard_expires_at ? (
                                <button
                                    type="button"
                                    onClick={() => setDashboardModal((prev) => prev ? { ...prev, dashboard_expires_at: '' } : null)}
                                    className="text-xs font-semibold text-[var(--app-muted)] hover:text-red-600"
                                >
                                    Supprimer la date
                                </button>
                            ) : null}
                        </div>
                        <p className="mt-1.5 text-xs text-[var(--app-muted)]">Sans date, l'annonce reste affichée jusqu'à désactivation manuelle.</p>
                    </div>
                    <div className="flex justify-end gap-2 border-t border-[var(--app-border)] pt-3">
                        <button
                            type="button"
                            onClick={() => setDashboardModal(null)}
                            className="rounded-xl border border-[var(--app-border)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                        >
                            Fermer
                        </button>
                        <button
                            type="button"
                            onClick={() => submitDashboardUpdate(dashboardModal?.show_on_dashboard ?? false, dashboardModal?.dashboard_expires_at || null)}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)]"
                        >
                            Enregistrer la date
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={Boolean(pendingSmsHref)} onClose={() => setPendingSmsHref(null)} maxWidth="lg">
                <div className="border-b border-[var(--app-border)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Confirmation d'ouverture SMS</h3>
                </div>
                <div className="space-y-4 px-5 py-4 text-sm">
                    <p>Cette action va ouvrir l'application Messages/SMS de votre appareil si elle est disponible.</p>
                    <p>Les destinataires seront ajoutés automatiquement à partir des numéros mobiles renseignés dans l'annuaire.</p>
                    <div>
                        <p className="font-semibold">Veuillez vérifier attentivement les destinataires avant l'envoi :</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            <li>si un utilisateur n'a pas de numéro mobile renseigné, il ne sera pas ajouté ;</li>
                            <li>si un numéro est mal renseigné, le destinataire peut être absent ou incorrect ;</li>
                            <li>le SMS ne sera pas envoyé automatiquement, vous devrez confirmer l'envoi dans l'application Messages/SMS.</li>
                        </ul>
                    </div>
                    <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--app-border)] pt-4">
                        <button
                            type="button"
                            onClick={() => setPendingSmsHref(null)}
                            className="rounded-xl border border-[var(--app-border)] px-4 py-2 text-xs font-black uppercase tracking-[0.08em]"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            onClick={confirmOpenSms}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)]"
                        >
                            Ouvrir l'application SMS
                        </button>
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}
