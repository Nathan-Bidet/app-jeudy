import Modal from '@/Components/Modal';
import MaintenanceTaskCard from '@/Components/Maintenance/TaskCard';
import MaintenanceTaskModal from '@/Components/Maintenance/TaskModal';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { CalendarCheck, CalendarDays, Filter, ListChecks, Plus, Search, User, UserRound } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const EMPTY_FILTER_STATE = {
    date_from: '',
    date_to: '',
    search: '',
    origin: '',
    pointed_filter: 'unpointed',
};

const EMPTY_FORM_STATE = {
    date: '',
    fin_date: '',
    due_date: '',
    assignee_user_id: '',
    assignee_label_free: '',
    depot_id: '',
    address_free: '',
    task: '',
    comment: '',
    comment_hidden: false,
};

/**
 * Avatar de la personne affectée, calqué sur celui du Livre du travail
 * (Components/Ldt/EntryCard) : mêmes dimensions, même bordure, même repli.
 * Une tâche sans affectation n'affiche aucune vignette, pour ne pas suggérer
 * un utilisateur qui n'existe pas.
 */
function AssigneeAvatar({ assignee }) {
    const type = String(assignee?.type || '');

    if (type === 'none') {
        return null;
    }

    if (type === 'user' && assignee?.photo_url) {
        return (
            <img
                src={assignee.photo_url}
                alt={assignee.name}
                className="h-8 w-8 shrink-0 rounded-full border-2 border-[var(--app-border)] object-cover"
            />
        );
    }

    return (
        <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--brand-yellow-dark)]">
            <UserRound className="h-4 w-4" strokeWidth={2.2} />
        </span>
    );
}

/** Nombre de tâches d'un groupe, accordé (« 1 tâche », « 2 tâches »). */
function taskCountLabel(group) {
    const count = group?.tasks?.length || 0;

    return `${count} tâche${count > 1 ? 's' : ''}`;
}

function buildFilterState(raw = {}) {
    return {
        ...EMPTY_FILTER_STATE,
        ...Object.fromEntries(
            Object.entries(raw || {}).map(([key, value]) => [key, value ?? '']),
        ),
        pointed_filter: raw?.pointed_filter || 'unpointed',
    };
}

/**
 * @param setFilters       met à jour l'état (dates : soumission différée)
 * @param onSelectChange   applique aussitôt (listes déroulantes)
 */
function FilterFields({ filters, setFilters, onSelectChange = null, stacked = false }) {
    const changeSelect = (patch) =>
        onSelectChange ? onSelectChange(patch) : setFilters((prev) => ({ ...prev, ...patch }));

    return (
        <>
            <label className={`relative block ${stacked ? '' : 'lg:w-[165px]'}`}>
                {stacked ? (
                    <span className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Du</span>
                ) : null}
                <CalendarDays className={`pointer-events-none absolute left-3 h-4 w-4 text-[var(--app-muted)] ${stacked ? 'top-[calc(50%+8px)] -translate-y-1/2' : 'top-1/2 -translate-y-1/2'}`} />
                <input
                    type="date"
                    value={filters.date_from}
                    onChange={(event) => setFilters((prev) => ({ ...prev, date_from: event.target.value }))}
                    className={`${stacked ? 'mt-1' : ''} h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm`}
                    title="Date début"
                />
            </label>

            <label className={`relative block ${stacked ? '' : 'lg:w-[165px]'}`}>
                {stacked ? (
                    <span className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Au</span>
                ) : null}
                <CalendarDays className={`pointer-events-none absolute left-3 h-4 w-4 text-[var(--app-muted)] ${stacked ? 'top-[calc(50%+8px)] -translate-y-1/2' : 'top-1/2 -translate-y-1/2'}`} />
                <input
                    type="date"
                    value={filters.date_to}
                    onChange={(event) => setFilters((prev) => ({ ...prev, date_to: event.target.value }))}
                    className={`${stacked ? 'mt-1' : ''} h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm`}
                    title="Date fin"
                />
            </label>

            <label className={`relative block ${stacked ? '' : 'lg:w-[150px]'}`}>
                {stacked ? (
                    <span className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Origine</span>
                ) : null}
                <User className={`pointer-events-none absolute left-3 h-4 w-4 text-[var(--app-muted)] ${stacked ? 'top-[calc(50%+8px)] -translate-y-1/2' : 'top-1/2 -translate-y-1/2'}`} />
                <select
                    value={filters.origin}
                    onChange={(event) => changeSelect({ origin: event.target.value })}
                    className={`${stacked ? 'mt-1' : ''} h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm`}
                >
                    <option value="">Toutes origines</option>
                    <option value="creation">Créations</option>
                    <option value="request">Demandes</option>
                </select>
            </label>

            <label className={`relative block ${stacked ? '' : 'lg:w-[150px]'}`}>
                {stacked ? (
                    <span className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Statut</span>
                ) : null}
                <ListChecks className={`pointer-events-none absolute left-3 h-4 w-4 text-[var(--app-muted)] ${stacked ? 'top-[calc(50%+8px)] -translate-y-1/2' : 'top-1/2 -translate-y-1/2'}`} />
                <select
                    value={filters.pointed_filter}
                    onChange={(event) => changeSelect({ pointed_filter: event.target.value })}
                    className={`${stacked ? 'mt-1' : ''} h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm`}
                >
                    <option value="all">Toutes</option>
                    <option value="unpointed">À faire</option>
                    <option value="partial">En cours</option>
                    <option value="pointed">Terminées</option>
                </select>
            </label>
        </>
    );
}

export default function MaintenanceIndex({
    groups = [],
    meta = {},
    filters = {},
    reference = {},
    permissions = {},
    focus_task_id = null,
}) {
    const [localFilters, setLocalFilters] = useState(() => buildFilterState(filters));
    const [showMobileFilters, setShowMobileFilters] = useState(false);
    const [mobileFilterDraft, setMobileFilterDraft] = useState(() => buildFilterState(filters));
    const [modalOpen, setModalOpen] = useState(false);
    const [editingTask, setEditingTask] = useState(null);
    // Demande en cours de transformation : le modal complet s'ouvre pré-rempli.
    const [convertingTask, setConvertingTask] = useState(null);
    const [taskToDelete, setTaskToDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);
    // Bascules de pointage appliquées avant la réponse serveur, puis effacées
    // dès que de nouvelles props arrivent (ou restaurées en cas d'échec).
    const [pointingOverrides, setPointingOverrides] = useState({});
    const [savingTaskIds, setSavingTaskIds] = useState({});
    const [dateTask, setDateTask] = useState(null);
    const [dateValue, setDateValue] = useState('');
    const [savingDate, setSavingDate] = useState(false);

    const searchDebounceRef = useRef(null);
    const searchReadyRef = useRef(false);
    const taskRefs = useRef(new Map());
    const [highlightedTaskId, setHighlightedTaskId] = useState(null);

    const canCreate = Boolean(permissions?.can_create);
    const canRequest = Boolean(permissions?.can_request);
    const canSubmit = canCreate || canRequest;
    // Sans le droit de créer, toute soumission est une demande.
    const submitOrigin = canCreate ? 'creation' : 'request';

    const form = useForm({ ...EMPTY_FORM_STATE });

    useEffect(() => {
        setLocalFilters(buildFilterState(filters));
    }, [filters]);

    useEffect(() => {
        setPointingOverrides({});
    }, [groups]);

    // Arrivée depuis une notification : on amène la tâche à l'écran et on la
    // souligne brièvement, comme le fait le Livre du travail.
    useEffect(() => {
        if (!focus_task_id) return undefined;

        const node = taskRefs.current.get(Number(focus_task_id));
        if (!node) return undefined;

        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setHighlightedTaskId(Number(focus_task_id));

        const timeout = window.setTimeout(() => setHighlightedTaskId(null), 2000);
        return () => window.clearTimeout(timeout);
    }, [focus_task_id, groups]);

    const displayedGroups = useMemo(() => {
        if (Object.keys(pointingOverrides).length === 0) {
            return groups;
        }

        return groups.map((group) => ({
            ...group,
            tasks: (group.tasks || []).map((task) =>
                pointingOverrides[task.id] ? { ...task, ...pointingOverrides[task.id] } : task,
            ),
        }));
    }, [groups, pointingOverrides]);

    const notifyPointingError = () => {
        window.dispatchEvent(
            new CustomEvent('app:toast', {
                detail: {
                    type: 'error',
                    message: "Échec du pointage. L'état précédent a été restauré.",
                },
            }),
        );
    };

    /**
     * Les deux pointages sont indépendants : basculer l'un ne touche pas l'autre.
     * Le serveur reste seul juge — l'override n'est qu'un confort d'affichage.
     */
    const sendPointing = (task, routeName, payload, optimistic) => {
        const taskId = Number(task?.id || 0);
        if (!taskId || savingTaskIds[taskId]) return;

        setSavingTaskIds((prev) => ({ ...prev, [taskId]: true }));
        setPointingOverrides((prev) => ({ ...prev, [taskId]: { ...(prev[taskId] || {}), ...optimistic } }));

        router.patch(route(routeName, taskId), payload, {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setPointingOverrides((prev) => {
                    const next = { ...prev };
                    delete next[taskId];
                    return next;
                });
                notifyPointingError();
            },
            onFinish: () => {
                setSavingTaskIds((prev) => {
                    const next = { ...prev };
                    delete next[taskId];
                    return next;
                });
            },
        });
    };

    const togglePartialPoint = (task, partiallyPointed) => {
        sendPointing(
            task,
            'maintenance.tasks.partial-point',
            { partially_pointed: Boolean(partiallyPointed) },
            { partially_pointed: Boolean(partiallyPointed) },
        );
    };

    const togglePoint = (task, pointed) => {
        sendPointing(
            task,
            'maintenance.tasks.point',
            { pointed: Boolean(pointed) },
            { pointed: Boolean(pointed) },
        );
    };

    const openPointingDate = (task) => {
        setDateTask(task);
        setDateValue(task?.first_pointed_on || '');
    };

    const savePointingDate = () => {
        if (!dateTask) return;

        setSavingDate(true);
        router.patch(
            route('maintenance.tasks.pointing-date', dateTask.id),
            { first_pointed_on: dateValue || null },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSavingDate(false);
                    setDateTask(null);
                },
            },
        );
    };

    const submitFilters = (nextFilters = localFilters) => {
        router.get(
            route('maintenance.index'),
            {
                date_from: nextFilters.date_from || undefined,
                date_to: nextFilters.date_to || undefined,
                search: nextFilters.search || undefined,
                origin: nextFilters.origin || undefined,
                pointed_filter: nextFilters.pointed_filter || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        if (!searchReadyRef.current) {
            searchReadyRef.current = true;
            return undefined;
        }

        if (searchDebounceRef.current) {
            window.clearTimeout(searchDebounceRef.current);
        }

        searchDebounceRef.current = window.setTimeout(() => submitFilters(localFilters), 300);

        return () => {
            if (searchDebounceRef.current) {
                window.clearTimeout(searchDebounceRef.current);
            }
        };
        // Recherche et dates passent par le même délai : taper une date à la
        // main produit des valeurs vides intermédiaires, qui sans cela
        // déclencheraient plusieurs allers-retours.
    }, [localFilters.search, localFilters.date_from, localFilters.date_to]);

    /**
     * Listes déroulantes : appliquées aussitôt, il n'y a rien à attendre.
     * La recherche et les dates, elles, passent par le délai ci-dessus.
     */
    const applyImmediately = (patch) => {
        setLocalFilters((prev) => {
            const next = { ...prev, ...patch };
            submitFilters(next);

            return next;
        });
    };

    const resetFilters = () => {
        setLocalFilters({ ...EMPTY_FILTER_STATE });
        submitFilters({ ...EMPTY_FILTER_STATE });
    };

    const openCreate = () => {
        setEditingTask(null);
        setConvertingTask(null);
        form.clearErrors();
        // Après une soumission réussie, Inertia promeut les données envoyées au
        // rang de valeurs par défaut du formulaire. reset() restaurerait donc la
        // tâche qui vient d'être créée. On pose l'état vierge explicitement :
        // setData avec un objet complet remplace les données sans dépendre de
        // defaults, et setDefaults réaligne le point de référence.
        // Le dépôt de rattachement vient du serveur : il pré-remplit la
        // demande sans la figer, le demandeur reste libre d'en choisir un autre.
        const next = {
            ...EMPTY_FORM_STATE,
            depot_id: canCreate ? '' : String(reference?.current_user_depot_id || ''),
        };

        form.setData(next);
        form.setDefaults(next);
        setModalOpen(true);
    };

    /** Ouvre le modal complet, pré-rempli avec ce que la demande sait déjà. */
    const openConversion = (task) => {
        setEditingTask(task);
        setConvertingTask(task);
        form.clearErrors();

        const next = {
            ...EMPTY_FORM_STATE,
            due_date: task.due_date || '',
            depot_id: task.depot?.id ? String(task.depot.id) : '',
            task: task.task || '',
        };

        form.setData(next);
        form.setDefaults(next);
        setModalOpen(true);
    };

    const openEdit = (task) => {
        setEditingTask(task);
        setConvertingTask(null);
        form.clearErrors();
        const next = {
            date: task.date || '',
            fin_date: task.fin_date || '',
            due_date: task.due_date || '',
            assignee_user_id: task.assignee?.type === 'user' ? String(task.assignee.id) : '',
            assignee_label_free: task.assignee?.type === 'free' ? task.assignee.name : '',
            depot_id: task.depot?.id ? String(task.depot.id) : '',
            address_free: task.address_free || '',
            task: task.task || '',
            // Un commentaire non transmis arrive vide ici ; le serveur conserve
            // l'existant plutôt que de le laisser écraser.
            comment: task.comment || '',
            comment_hidden: Boolean(task.comment_hidden),
        };
        // Même logique en édition, à ceci près que l'état de référence est la
        // tâche sélectionnée.
        form.setData(next);
        form.setDefaults(next);
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditingTask(null);
        setConvertingTask(null);
        form.clearErrors();
    };

    const submitForm = () => {
        const options = {
            preserveScroll: true,
            // Le modal ne se ferme que si l'enregistrement a réussi : les
            // erreurs restent affichées à côté des champs concernés.
            onSuccess: () => closeModal(),
            onError: () => {
                window.dispatchEvent(
                    new CustomEvent('app:toast', {
                        detail: {
                            type: 'error',
                            message: "La tâche n'a pas pu être enregistrée. Vérifiez les champs signalés.",
                        },
                    }),
                );
            },
            // transform() est persistant sur le formulaire : on le remet à
            // l'identité pour que l'origine d'une création ne soit pas rejouée
            // lors d'une modification ultérieure.
            onFinish: () => form.transform((data) => data),
        };

        if (editingTask) {
            // Transformer une demande, c'est mettre à jour sa ligne en la
            // marquant convertie : aucune seconde tâche n'est créée.
            if (convertingTask) {
                form.transform((data) => ({ ...data, convert: true }));
            }

            form.put(route('maintenance.tasks.update', editingTask.id), options);

            return;
        }

        // transform() ne retourne rien dans l'adaptateur React : il se déclare
        // avant l'envoi, il ne se chaîne pas.
        form.transform((data) => ({ ...data, origin: submitOrigin }));
        form.post(route('maintenance.tasks.store'), options);
    };

    const confirmDelete = () => {
        if (!taskToDelete) return;

        setDeleting(true);
        router.delete(route('maintenance.tasks.destroy', taskToDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setTaskToDelete(null);
            },
        });
    };

    const createLabel = canCreate ? 'Nouvelle tâche' : 'Nouvelle demande';
    // Même icône dans les deux cas : le geste est le même, ouvrir le formulaire.
    const CreateIcon = Plus;

    const totalTasks = meta?.count_tasks ?? 0;

    const pageHeader = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h1 className="text-[22px] leading-none">
                <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">
                    Maintenance / Entretien
                </span>
            </h1>

            <div className="flex w-full flex-col gap-2 lg:hidden">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                    <input
                        type="text"
                        value={localFilters.search}
                        onChange={(event) => setLocalFilters((prev) => ({ ...prev, search: event.target.value }))}
                        placeholder="Tâche, personne, lieu…"
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                    />
                </div>

                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => {
                            setMobileFilterDraft({ ...localFilters });
                            setShowMobileFilters(true);
                        }}
                        className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                    >
                        <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                        <span>Filtres</span>
                    </button>

                    {canSubmit ? (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border-2 border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] hover:brightness-95 disabled:opacity-60"
                        >
                            <CreateIcon className="h-3.5 w-3.5" strokeWidth={2.3} />
                            <span>{canCreate ? 'Ajouter' : 'Demande'}</span>
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="hidden w-full flex-col gap-2 lg:flex lg:w-auto">
                <div className="flex w-full flex-col gap-2 lg:w-auto lg:flex-row lg:items-center">
                    <label className="relative block lg:w-[300px]">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={localFilters.search}
                            onChange={(event) => setLocalFilters((prev) => ({ ...prev, search: event.target.value }))}
                            placeholder="Tâche, personne, lieu, dépôt…"
                            className="h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                        />
                    </label>

                    <FilterFields
                        filters={localFilters}
                        setFilters={setLocalFilters}
                        onSelectChange={applyImmediately}
                    />

                    <button
                        type="button"
                        onClick={resetFilters}
                        className="h-10 shrink-0 rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                    >
                        Effacer
                    </button>

                    {canSubmit ? (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl border-2 border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] hover:brightness-95 disabled:opacity-60"
                        >
                            <CreateIcon className="h-3.5 w-3.5" strokeWidth={2.3} />
                            <span>{createLabel}</span>
                        </button>
                    ) : null}
                </div>
            </div>
        </div>
    );

    return (
        <AppLayout title="Maintenance / Entretien" header={pageHeader}>
            <Head title="Maintenance / Entretien" />

            <div className="maintenance-page w-full max-w-full space-y-4 px-0 sm:space-y-5">
                {displayedGroups.length === 0 ? (
                    <section className="rounded-2xl border-2 border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)]">
                        Aucune tâche de maintenance pour les filtres sélectionnés.
                    </section>
                ) : (
                    <>
                        <p className="px-1 text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            {totalTasks} tâche{totalTasks > 1 ? 's' : ''} • {displayedGroups.length} groupe
                            {displayedGroups.length > 1 ? 's' : ''}
                        </p>

                        <div className="space-y-4">
                            {displayedGroups.map((group) => (
                                <section
                                    key={group.key}
                                    className={`rounded-2xl border-2 border-[var(--app-border)] p-2.5 shadow-sm sm:p-5 ${
                                        group.is_request
                                            ? 'maintenance-request-group'
                                            : 'bg-[var(--app-surface)]'
                                    }`}
                                >
                                    <div className="mb-2.5 flex flex-wrap items-start justify-between gap-3 sm:mb-4">
                                        <div>
                                            {/* Deux badges, comme l'en-tête d'une
                                                entrée du Livre du travail. Une
                                                demande n'a ni date de début ni
                                                personne affectée : seul le
                                                compteur reste. */}
                                            <div className="flex flex-wrap items-center gap-2">
                                                {group.is_request ? null : (
                                                    <span className="inline-flex items-center gap-1 rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[11px] font-black uppercase tracking-[0.12em]">
                                                        <CalendarDays className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                        {group.date_label || group.date}
                                                    </span>
                                                )}
                                                <span className="inline-flex items-center rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-bold uppercase text-[var(--app-muted)]">
                                                    {taskCountLabel(group)}
                                                </span>
                                            </div>
                                            {group.is_request ? null : (
                                                <h3 className="mt-1.5 flex min-w-0 items-center gap-2 text-base font-extrabold text-[var(--app-text)]">
                                                    <AssigneeAvatar assignee={group.assignee} />
                                                    <span className="min-w-0 break-words">
                                                        {group.assignee?.name || 'Non affectée'}
                                                    </span>
                                                </h3>
                                            )}
                                        </div>
                                    </div>

                                    <div className="grid gap-3">
                                        {(group.tasks || []).map((task) => (
                                            <div
                                                key={task.id}
                                                ref={(node) => {
                                                    if (node) {
                                                        taskRefs.current.set(Number(task.id), node);
                                                    } else {
                                                        taskRefs.current.delete(Number(task.id));
                                                    }
                                                }}
                                                className={
                                                    highlightedTaskId === Number(task.id)
                                                        ? 'rounded-xl ring-2 ring-[var(--brand-yellow-dark)]'
                                                        : undefined
                                                }
                                            >
                                            <MaintenanceTaskCard
                                                task={task}
                                                placeResolver={reference?.depot_place_map || {}}
                                                onEdit={openEdit}
                                                onDelete={setTaskToDelete}
                                                onTogglePartialPoint={togglePartialPoint}
                                                onTogglePoint={togglePoint}
                                                onEditPointingDate={openPointingDate}
                                                onConvert={openConversion}
                                                deleting={deleting && taskToDelete?.id === task.id}
                                                saving={Boolean(savingTaskIds[task.id])}
                                            />
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </div>
                    </>
                )}
            </div>

            <MaintenanceTaskModal
                show={modalOpen}
                onClose={closeModal}
                form={form}
                reference={reference}
                mode={editingTask ? 'edit' : 'create'}
                origin={submitOrigin}
                converting={Boolean(convertingTask)}
                currentAssignee={
                    editingTask?.assignee?.type === 'user' ? editingTask.assignee : null
                }
                onSubmit={submitForm}
            />

            <Modal show={showMobileFilters} onClose={() => setShowMobileFilters(false)} maxWidth="lg">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Filtres</h3>
                </div>

                <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4">
                    <FilterFields filters={mobileFilterDraft} setFilters={setMobileFilterDraft} stacked />
                </div>

                <div className="flex justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={() => {
                            const next = { ...EMPTY_FILTER_STATE };
                            setMobileFilterDraft(next);
                            setLocalFilters(next);
                            submitFilters(next);
                            setShowMobileFilters(false);
                        }}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                    >
                        Effacer
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            const next = { ...mobileFilterDraft };
                            setLocalFilters(next);
                            submitFilters(next);
                            setShowMobileFilters(false);
                        }}
                        className="rounded-xl border-2 border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                    >
                        Appliquer
                    </button>
                </div>
            </Modal>

            <Modal show={Boolean(dateTask)} onClose={() => setDateTask(null)} maxWidth="md">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="inline-flex items-center gap-2 text-sm font-black uppercase tracking-[0.08em]">
                        <CalendarCheck className="h-4 w-4" strokeWidth={2.3} />
                        Date du premier pointage
                    </h3>
                </div>

                <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4">
                    <p className="text-xs text-[var(--app-muted)]">
                        Date métier, corrigeable à la main. Les horodatages techniques des pointages et leurs
                        auteurs ne sont pas modifiés.
                    </p>

                    <input
                        type="date"
                        value={dateValue}
                        onChange={(event) => setDateValue(event.target.value)}
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                    />

                    {dateTask?.partially_pointed_at_label || dateTask?.pointed_at_label ? (
                        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-xs text-[var(--app-muted)]">
                            {dateTask?.partially_pointed_at_label ? (
                                <p>Pointage partiel : {dateTask.partially_pointed_at_label}</p>
                            ) : null}
                            {dateTask?.pointed_at_label ? (
                                <p>Pointage définitif : {dateTask.pointed_at_label}</p>
                            ) : null}
                        </div>
                    ) : null}

                    {dateValue ? null : (
                        <p className="text-xs text-[var(--app-muted)]">
                            Laissée vide, la date restera vide : elle ne sera plus recalculée automatiquement.
                        </p>
                    )}
                </div>

                <div className="flex justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={() => setDateTask(null)}
                        disabled={savingDate}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] disabled:opacity-60"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        onClick={savePointingDate}
                        disabled={savingDate}
                        className="rounded-xl border-2 border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-60"
                    >
                        {savingDate ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                </div>
            </Modal>

            <Modal show={Boolean(taskToDelete)} onClose={() => setTaskToDelete(null)} maxWidth="md">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Supprimer la tâche</h3>
                </div>

                <div className="bg-[var(--app-surface)] px-5 py-4 text-sm">
                    <p>Cette action est définitive. Confirmer la suppression&nbsp;?</p>
                    {taskToDelete ? (
                        <p className="mt-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-xs">
                            {taskToDelete.date_label} — {taskToDelete.task}
                        </p>
                    ) : null}
                </div>

                <div className="flex justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={() => setTaskToDelete(null)}
                        disabled={deleting}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] disabled:opacity-60"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        onClick={confirmDelete}
                        disabled={deleting}
                        className="rounded-xl border-2 border-red-300 bg-red-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-60"
                    >
                        {deleting ? 'Suppression…' : 'Supprimer'}
                    </button>
                </div>
            </Modal>
        </AppLayout>
    );
}
