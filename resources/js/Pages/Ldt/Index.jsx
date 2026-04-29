import EntryCard from '@/Components/Ldt/EntryCard';
import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { ArrowUp, CalendarDays, Filter, ListChecks, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const EMPTY_FILTER_STATE = {
    date_from: '',
    date_to: '',
    search: '',
    pointed_filter: 'todo',
};

function buildFilterState(raw = {}) {
    return {
        ...EMPTY_FILTER_STATE,
        ...raw,
        pointed_filter: raw?.pointed_filter || 'todo',
    };
}

function MobileFiltersModal({
    open,
    onClose,
    filters,
    setFilters,
    searchValue,
    onSearchChange,
    onApply,
    onReset,
}) {
    if (!open) return null;

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">Filtres</h3>
            </div>

            <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4">
                <div>
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Recherche
                    </label>
                    <div className="relative mt-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={searchValue}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Tâche, commentaire, chauffeur, dépôt, véhicule..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Du</label>
                        <div className="relative mt-1">
                            <CalendarDays className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                            <input
                                type="date"
                                value={filters.date_from}
                                onChange={(event) => setFilters((prev) => ({ ...prev, date_from: event.target.value }))}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Au</label>
                        <div className="relative mt-1">
                            <CalendarDays className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                            <input
                                type="date"
                                value={filters.date_to}
                                onChange={(event) => setFilters((prev) => ({ ...prev, date_to: event.target.value }))}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm"
                            />
                        </div>
                    </div>
                </div>

                <div>
                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Statut
                    </label>
                    <div className="relative mt-1">
                        <ListChecks className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <select
                            value={filters.pointed_filter}
                            onChange={(event) => setFilters((prev) => ({ ...prev, pointed_filter: event.target.value }))}
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm"
                        >
                            <option value="all">Tous</option>
                            <option value="done">Pointé</option>
                            <option value="todo">Non pointé</option>
                        </select>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <button
                    type="button"
                    onClick={onClose}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] sm:w-auto"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={onReset}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] sm:w-auto"
                >
                    Réinitialiser
                </button>
                <button
                    type="button"
                    onClick={onApply}
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] sm:w-auto"
                >
                    Appliquer
                </button>
            </div>
        </Modal>
    );
}

export default function LdtIndex({
    groups = [],
    filters = {},
    meta = {},
    focus_entry_id = null,
    focus_task_id = null,
    permissions = {},
    depot_place_map = {},
}) {
    const [localFilters, setLocalFilters] = useState(buildFilterState(filters));
    const [mobileFilterDraft, setMobileFilterDraft] = useState(buildFilterState(filters));
    const [showMobileFilters, setShowMobileFilters] = useState(false);
    const [showFloatingActions, setShowFloatingActions] = useState(false);
    const [showQuickSearch, setShowQuickSearch] = useState(false);

    const [commentSelectionByEntry, setCommentSelectionByEntry] = useState({});
    const [transportSelectionByEntry, setTransportSelectionByEntry] = useState({});
    const [highlightedEntryId, setHighlightedEntryId] = useState(null);
    const [highlightedTaskId, setHighlightedTaskId] = useState(null);

    const entryRefs = useRef(new Map());
    const taskRefs = useRef(new Map());
    const focusRetryRef = useRef({ entryId: null, taskId: null, attempts: 0 });
    const quickSearchPanelRef = useRef(null);
    const quickSearchButtonRef = useRef(null);
    const searchDebounceRef = useRef(null);
    const searchEffectReadyRef = useRef(false);

    useEffect(() => {
        const next = buildFilterState(filters);
        setLocalFilters(next);
        setMobileFilterDraft(next);
    }, [filters]);

    const allEntries = useMemo(
        () => (groups || []).flatMap((group) => group.entries || []),
        [groups],
    );

    useEffect(() => {
        const defaults = {};
        allEntries.forEach((entry) => {
            const items = Array.isArray(entry.task_items) ? entry.task_items : [];
            const selection = {};
            items.forEach((item, index) => {
                const key = String(item?.id ?? `i-${index}`);
                const hasComment = String(item?.comment || '').trim() !== '';
                if (hasComment) {
                    selection[key] = true;
                }
            });
            defaults[entry.id] = selection;
        });
        setCommentSelectionByEntry((prev) => {
            const merged = { ...defaults };
            Object.keys(prev || {}).forEach((entryId) => {
                merged[entryId] = { ...(defaults[entryId] || {}), ...(prev[entryId] || {}) };
            });
            return merged;
        });
    }, [allEntries]);

    useEffect(() => {
        const defaults = {};
        allEntries.forEach((entry) => {
            const items = Array.isArray(entry.task_items) ? entry.task_items : [];
            const selection = {};
            items.forEach((item, index) => {
                const key = String(item?.id ?? `i-${index}`);
                selection[key] = false;
            });
            defaults[entry.id] = selection;
        });

        setTransportSelectionByEntry((prev) => {
            const merged = { ...defaults };
            Object.keys(prev || {}).forEach((entryId) => {
                merged[entryId] = { ...(defaults[entryId] || {}), ...(prev[entryId] || {}) };
            });
            return merged;
        });
    }, [allEntries]);

    useEffect(() => {
        if (!focus_entry_id) return undefined;
        let cancelled = false;

        const tryScroll = () => {
            if (cancelled) return;
            const node = entryRefs.current.get(focus_entry_id);
            if (node) {
                node.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setHighlightedEntryId(focus_entry_id);
                const timeout = window.setTimeout(() => setHighlightedEntryId(null), 2000);
                return () => window.clearTimeout(timeout);
            }

            focusRetryRef.current = {
                entryId: focus_entry_id,
                taskId: focusRetryRef.current.taskId,
                attempts: (focusRetryRef.current.attempts || 0) + 1,
            };

            if (focusRetryRef.current.attempts < 12) {
                window.setTimeout(tryScroll, 80);
            }

            return undefined;
        };

        const cleanup = tryScroll();
        return () => {
            cancelled = true;
            if (typeof cleanup === 'function') cleanup();
        };
    }, [focus_entry_id, groups.length]);

    useEffect(() => {
        if (!focus_task_id) return undefined;
        const numericTaskId = Number(focus_task_id);
        let cancelled = false;

        const tryScroll = () => {
            if (cancelled) return;
            const taskNode = taskRefs.current.get(numericTaskId);
            if (taskNode) {
                taskNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setHighlightedTaskId(numericTaskId);
                const timeout = window.setTimeout(() => setHighlightedTaskId(null), 1800);
                return () => window.clearTimeout(timeout);
            }

            const fallbackEntry = (groups || [])
                .flatMap((group) => group.entries || [])
                .find((entry) => {
                    const items = Array.isArray(entry.task_items) ? entry.task_items : [];
                    return items.some((item) => Number(item?.id || 0) === numericTaskId);
                });

            if (fallbackEntry?.id && entryRefs.current.get(fallbackEntry.id)) {
                const entryNode = entryRefs.current.get(fallbackEntry.id);
                entryNode?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setHighlightedEntryId(fallbackEntry.id);
                const timeout = window.setTimeout(() => setHighlightedEntryId(null), 1800);
                return () => window.clearTimeout(timeout);
            }

            focusRetryRef.current = {
                entryId: focusRetryRef.current.entryId,
                taskId: numericTaskId,
                attempts: (focusRetryRef.current.attempts || 0) + 1,
            };

            if (focusRetryRef.current.attempts < 12) {
                window.setTimeout(tryScroll, 80);
            }

            return undefined;
        };

        const cleanup = tryScroll();
        return () => {
            cancelled = true;
            if (typeof cleanup === 'function') cleanup();
        };
    }, [focus_task_id, groups.length]);

    useEffect(() => {
        if (typeof window === 'undefined' || !window.Echo) {
            return undefined;
        }

        const channel = window.Echo.channel('ldt.global');
        const onEntryUpdated = () => {
            router.reload({
                only: ['groups', 'meta'],
                preserveScroll: true,
                preserveState: true,
            });
        };

        channel.listen('.ldt.entry.updated', onEntryUpdated);

        return () => {
            channel.stopListening('.ldt.entry.updated');
        };
    }, []);

    useEffect(() => {
        const onScroll = () => {
            setShowFloatingActions(window.scrollY > 180);
        };

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        if (!showQuickSearch) return undefined;

        const handlePointerDown = (event) => {
            const panel = quickSearchPanelRef.current;
            const button = quickSearchButtonRef.current;

            if (panel?.contains(event.target) || button?.contains(event.target)) {
                return;
            }

            setShowQuickSearch(false);
        };

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                setShowQuickSearch(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);
        document.addEventListener('keydown', handleEscape);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [showQuickSearch]);

    useEffect(() => {
        if (!searchEffectReadyRef.current) {
            searchEffectReadyRef.current = true;
            return undefined;
        }

        if (searchDebounceRef.current) {
            window.clearTimeout(searchDebounceRef.current);
        }

        searchDebounceRef.current = window.setTimeout(() => {
            submitFilters(localFilters);
        }, 300);

        return () => {
            if (searchDebounceRef.current) {
                window.clearTimeout(searchDebounceRef.current);
            }
        };
    }, [localFilters.search]);

    const submitFilters = (nextFilters = localFilters) => {
        router.get(route('ldt.index'), {
            date_from: nextFilters.date_from || undefined,
            date_to: nextFilters.date_to || undefined,
            search: nextFilters.search || undefined,
            pointed_filter: nextFilters.pointed_filter !== 'all' ? nextFilters.pointed_filter : undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const onSearchChange = (value) => {
        const nextValue = String(value ?? '');
        setLocalFilters((prev) => ({ ...prev, search: nextValue }));
        setMobileFilterDraft((prev) => ({ ...prev, search: nextValue }));
    };

    const resetFilters = () => {
        const next = { ...EMPTY_FILTER_STATE };
        setLocalFilters(next);
        submitFilters(next);
    };

    const openMobileFilters = () => {
        setShowQuickSearch(false);
        setMobileFilterDraft({ ...localFilters });
        setShowMobileFilters(true);
    };

    const closeMobileFilters = () => {
        setShowMobileFilters(false);
    };

    const applyMobileFilters = () => {
        const next = { ...mobileFilterDraft };
        setLocalFilters(next);
        submitFilters(next);
        setShowMobileFilters(false);
    };

    const resetMobileFilters = () => {
        const next = { ...EMPTY_FILTER_STATE };
        setMobileFilterDraft(next);
        setLocalFilters(next);
        submitFilters(next);
        setShowMobileFilters(false);
    };

    const toggleSmsSent = (entry, checked) => {
        router.patch(route('ldt.entries.sms', entry.id), {
            sms_sent: Boolean(checked),
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const toggleTaskComment = (entryId, taskId, checked) => {
        setCommentSelectionByEntry((prev) => ({
            ...prev,
            [entryId]: {
                ...(prev?.[entryId] || {}),
                [String(taskId)]: Boolean(checked),
            },
        }));
    };

    const toggleTaskTransport = (entryId, taskId, checked) => {
        setTransportSelectionByEntry((prev) => ({
            ...prev,
            [entryId]: {
                ...(prev?.[entryId] || {}),
                [String(taskId)]: Boolean(checked),
            },
        }));
    };

    const pageHeader = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h1 className="text-[22px] leading-none">
                <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">Livre du Travail</span>
            </h1>

            <div className="flex w-full flex-col gap-2 lg:hidden">
                <div className="w-full">
                    <label className="sr-only">Recherche globale</label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={localFilters.search}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Tâche, commentaire, contrat, chauffeur..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                        />
                    </div>
                </div>

                <button
                    type="button"
                    onClick={openMobileFilters}
                    className="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                >
                    <Filter className="h-3.5 w-3.5" strokeWidth={2.2} />
                    <span>Filtres</span>
                </button>
            </div>

            <div className="hidden w-full flex-col gap-2 lg:flex lg:w-auto">
                <div className="flex w-full flex-col gap-2 lg:w-auto lg:flex-row lg:items-center">
                    <label className="relative block lg:w-[360px]">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={localFilters.search}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Tâche, commentaire, chauffeur, dépôt, véhicule..."
                            className="h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                        />
                    </label>

                    <label className="relative block lg:w-[165px]">
                        <CalendarDays className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="date"
                            value={localFilters.date_from}
                            onChange={(event) => setLocalFilters((prev) => ({ ...prev, date_from: event.target.value }))}
                            className="h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm"
                            title="Date début"
                        />
                    </label>

                    <label className="relative block lg:w-[165px]">
                        <CalendarDays className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="date"
                            value={localFilters.date_to}
                            onChange={(event) => setLocalFilters((prev) => ({ ...prev, date_to: event.target.value }))}
                            className="h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm"
                            title="Date fin"
                        />
                    </label>

                    <label className="relative block lg:w-[150px]">
                        <ListChecks className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <select
                            value={localFilters.pointed_filter}
                            onChange={(event) => setLocalFilters((prev) => ({ ...prev, pointed_filter: event.target.value }))}
                            className="h-10 w-full rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 pl-9 text-sm"
                        >
                            <option value="all">Tous</option>
                            <option value="done">Pointé</option>
                            <option value="todo">Non pointé</option>
                        </select>
                    </label>

                    <button
                        type="button"
                        onClick={() => submitFilters(localFilters)}
                        className="h-10 rounded-xl border-2 border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] lg:shrink-0"
                    >
                        Appliquer
                    </button>

                    <button
                        type="button"
                        onClick={resetFilters}
                        className="h-10 rounded-xl border-2 border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] lg:shrink-0"
                    >
                        Effacer
                    </button>
                </div>

            </div>
        </div>
    );

    return (
        <AppLayout title="Livre Du Travail" header={pageHeader}>
            <Head title="Livre du Travail" />

            <div className="ldt-page relative left-1/2 w-screen -translate-x-1/2 space-y-4 px-0 pb-20 pt-2 sm:static sm:left-auto sm:w-full sm:translate-x-0 sm:pt-3 lg:mx-auto lg:max-w-[1460px] lg:pb-8">
                {groups.length === 0 ? (
                    <section className="rounded-2xl border-2 border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)]">
                        Aucune entrée LDT pour les filtres sélectionnés.
                    </section>
                ) : null}

                <section className="w-full overflow-hidden rounded-2xl border-2 border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm">
                    <div className="w-full space-y-10 px-0 py-4 sm:px-3 sm:py-5">
                        {(groups || []).map((group) => (
                            <section key={group.date} className="w-full space-y-2">
                                <div className="w-full grid gap-8">
                                    {(group.entries || []).map((entry) => (
                                        <EntryCard
                                            key={entry.id}
                                            entry={entry}
                                            placeResolver={depot_place_map}
                                            commentSelection={commentSelectionByEntry[entry.id] || {}}
                                            transportSelection={transportSelectionByEntry[entry.id] || {}}
                                            onToggleTaskComment={toggleTaskComment}
                                            onToggleTaskTransport={toggleTaskTransport}
                                            canSmsMark={Boolean(permissions?.can_sms_mark)}
                                            onToggleSmsSent={toggleSmsSent}
                                            highlighted={highlightedEntryId === entry.id}
                                            setEntryRef={(entryId, node) => {
                                                if (!node) {
                                                    entryRefs.current.delete(entryId);
                                                    return;
                                                }
                                                entryRefs.current.set(entryId, node);
                                            }}
                                            highlightedTaskId={highlightedTaskId}
                                            setTaskRef={(taskId, node) => {
                                                if (!taskId) return;
                                                if (!node) {
                                                    taskRefs.current.delete(taskId);
                                                    return;
                                                }
                                                taskRefs.current.set(taskId, node);
                                            }}
                                        />
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                </section>
            </div>

            <MobileFiltersModal
                open={showMobileFilters}
                onClose={closeMobileFilters}
                filters={mobileFilterDraft}
                setFilters={setMobileFilterDraft}
                searchValue={localFilters.search}
                onSearchChange={onSearchChange}
                onApply={applyMobileFilters}
                onReset={resetMobileFilters}
            />

            <div
                ref={quickSearchPanelRef}
                className={`fixed bottom-[calc(env(safe-area-inset-bottom)+8.4rem)] right-3 z-20 w-[min(calc(100vw-2rem),22rem)] rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-2xl transition-all duration-200 lg:hidden ${
                    showQuickSearch ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-2 opacity-0'
                }`}
                aria-hidden={!showQuickSearch}
            >
                <div className="space-y-2">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                        <input
                            type="text"
                            value={localFilters.search}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Recherche rapide..."
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] py-2 pl-9 pr-3 text-sm"
                            autoFocus={showQuickSearch}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setShowQuickSearch(false)}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em]"
                        >
                            Fermer
                        </button>
                    </div>
                </div>
            </div>

            {showFloatingActions || showQuickSearch || showMobileFilters ? (
                <div className="fixed bottom-[calc(env(safe-area-inset-bottom)+5.1rem)] right-3 z-20 flex items-center gap-2 md:bottom-4 md:right-4 lg:hidden">
                    <button
                        ref={quickSearchButtonRef}
                        type="button"
                        onClick={() => setShowQuickSearch((current) => !current)}
                        className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 text-[11px] font-black uppercase tracking-[0.1em] shadow-lg shadow-black/10"
                    >
                        <Search className="h-3.5 w-3.5" strokeWidth={2.4} />
                        <span>Recherche</span>
                    </button>

                    <button
                        type="button"
                        onClick={openMobileFilters}
                        className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 text-[11px] font-black uppercase tracking-[0.1em] shadow-lg shadow-black/10"
                    >
                        <Filter className="h-3.5 w-3.5" strokeWidth={2.4} />
                        <span>Filtres</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] shadow-lg shadow-black/10"
                        title="Remonter en haut"
                        aria-label="Remonter en haut"
                    >
                        <ArrowUp className="h-4.5 w-4.5" strokeWidth={2.4} />
                    </button>
                </div>
            ) : null}
        </AppLayout>
    );
}
