import UserRow from '@/Components/Directory/UserRow';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { ArrowUp, Filter, Search, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const MOBILE_MEDIA_QUERY = '(max-width: 767px)';
const DIRECTORY_RETURN_CONTEXT_KEY = 'directory:return-context';
const DIRECTORY_RETURN_CONTEXT_MAX_AGE_MS = 1000 * 60 * 30;

function readDirectoryReturnContext() {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = window.sessionStorage.getItem(DIRECTORY_RETURN_CONTEXT_KEY);
        if (!raw) {
            return null;
        }

        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function writeDirectoryReturnContext(context) {
    if (typeof window === 'undefined') {
        return;
    }

    window.sessionStorage.setItem(DIRECTORY_RETURN_CONTEXT_KEY, JSON.stringify(context));
}

function Pagination({ links = [] }) {
    if (!Array.isArray(links) || links.length <= 3) {
        return null;
    }

    const labelForPagination = (label) => {
        const raw = String(label || '')
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;/g, ' ')
            .trim();

        const normalized = raw
            .replace(/&laquo;|«/g, '')
            .replace(/&raquo;|»/g, '')
            .trim()
            .toLowerCase();

        if (
            normalized.includes('previous')
            || normalized.includes('précédent')
            || normalized.includes('precedent')
            || normalized.includes('pagination.previous')
        ) {
            return '←';
        }

        if (
            normalized.includes('next')
            || normalized.includes('suivant')
            || normalized.includes('pagination.next')
        ) {
            return '→';
        }

        return raw;
    };

    return (
        <div className="mt-4 flex flex-wrap gap-2">
            {links.map((link, index) => (
                <button
                    key={`${link.label}-${index}`}
                    type="button"
                    disabled={!link.url}
                    onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
                    className={`rounded-lg border px-3 py-1.5 text-xs font-bold transition ${
                        link.active
                            ? 'border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                            : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]'
                    } ${!link.url ? 'cursor-not-allowed opacity-50' : ''}`}
                >
                    {labelForPagination(link.label)}
                </button>
            ))}
        </div>
    );
}

export default function DirectoryIndex({ directoryUsers, filters, sectors, viewer }) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [sectorId, setSectorId] = useState(filters?.sector_id ? String(filters.sector_id) : '');
    const [isMobileFiltersOpen, setIsMobileFiltersOpen] = useState(false);
    const [isQuickSearchOpen, setIsQuickSearchOpen] = useState(false);
    const [showFloatingActions, setShowFloatingActions] = useState(false);
    const [isMobile, setIsMobile] = useState(() => {
        if (typeof window === 'undefined') {
            return Boolean(filters?.mobile_all);
        }

        return window.matchMedia(MOBILE_MEDIA_QUERY).matches;
    });
    const quickSearchPanelRef = useRef(null);
    const quickSearchButtonRef = useRef(null);
    const mobileAll = Boolean(isMobile);

    const paginationInfo = useMemo(() => {
        if (!directoryUsers) return null;
        return {
            from: directoryUsers.from ?? 0,
            to: directoryUsers.to ?? 0,
            total: directoryUsers.total ?? 0,
        };
    }, [directoryUsers]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return undefined;
        }

        const mediaQuery = window.matchMedia(MOBILE_MEDIA_QUERY);
        const handleChange = (event) => {
            setIsMobile(event.matches);
        };

        setIsMobile(mediaQuery.matches);

        if (typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', handleChange);
        } else {
            mediaQuery.addListener(handleChange);
        }

        return () => {
            if (typeof mediaQuery.removeEventListener === 'function') {
                mediaQuery.removeEventListener('change', handleChange);
            } else {
                mediaQuery.removeListener(handleChange);
            }
        };
    }, []);

    useEffect(() => {
        const currentMode = Boolean(filters?.mobile_all);

        if (currentMode === mobileAll) {
            return;
        }

        router.get(
            route('directory.index'),
            {
                search: filters?.search || undefined,
                sector_id: viewer?.is_admin && filters?.sector_id ? Number(filters.sector_id) : undefined,
                mobile_all: mobileAll ? 1 : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['directoryUsers', 'filters'],
            },
        );
    }, [filters?.mobile_all, filters?.search, filters?.sector_id, mobileAll, viewer?.is_admin]);

    useEffect(() => {
        if (!isMobileFiltersOpen && !isQuickSearchOpen) {
            return undefined;
        }

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                setIsMobileFiltersOpen(false);
                setIsQuickSearchOpen(false);
            }
        };

        document.addEventListener('keydown', handleEscape);
        return () => {
            document.removeEventListener('keydown', handleEscape);
        };
    }, [isMobileFiltersOpen, isQuickSearchOpen]);

    useEffect(() => {
        if (isMobile) {
            return;
        }

        setIsMobileFiltersOpen(false);
        setIsQuickSearchOpen(false);
    }, [isMobile]);

    useEffect(() => {
        if (!isMobile) {
            setShowFloatingActions(false);
            return undefined;
        }

        const onScroll = () => {
            setShowFloatingActions(window.scrollY > 180);
        };

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, [isMobile]);

    useEffect(() => {
        if (!isQuickSearchOpen) {
            return undefined;
        }

        const handlePointerDown = (event) => {
            const panel = quickSearchPanelRef.current;
            const button = quickSearchButtonRef.current;

            if (panel?.contains(event.target) || button?.contains(event.target)) {
                return;
            }

            setIsQuickSearchOpen(false);
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
        };
    }, [isQuickSearchOpen]);

    useEffect(() => {
        if (!isMobileFiltersOpen) {
            return undefined;
        }

        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = previous;
        };
    }, [isMobileFiltersOpen]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return undefined;
        }

        const context = readDirectoryReturnContext();
        if (!context?.shouldRestore || !context.contactId) {
            return undefined;
        }

        if (
            typeof context.savedAt === 'number'
            && Date.now() - context.savedAt > DIRECTORY_RETURN_CONTEXT_MAX_AGE_MS
        ) {
            window.sessionStorage.removeItem(DIRECTORY_RETURN_CONTEXT_KEY);
            return undefined;
        }

        const currentUrl = `${window.location.pathname}${window.location.search}`;
        if (context.returnUrl && context.returnUrl !== currentUrl) {
            return undefined;
        }

        let cancelled = false;
        let attempts = 0;
        const maxAttempts = 45;

        const tryScrollToContact = () => {
            if (cancelled) {
                return;
            }

            const row = document.getElementById(`contact-${context.contactId}`);
            if (row) {
                row.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                });

                writeDirectoryReturnContext({
                    ...context,
                    shouldRestore: false,
                });
                return;
            }

            attempts += 1;
            if (attempts < maxAttempts) {
                window.requestAnimationFrame(tryScrollToContact);
            }
        };

        window.requestAnimationFrame(tryScrollToContact);

        return () => {
            cancelled = true;
        };
    }, [directoryUsers?.current_page, directoryUsers?.data]);

    const applyFilters = ({ closeMobilePanel = false, closeQuickSearch = false } = {}) => {
        router.get(
            route('directory.index'),
            {
                search: search || undefined,
                sector_id: viewer?.is_admin && sectorId ? Number(sectorId) : undefined,
                mobile_all: mobileAll ? 1 : undefined,
            },
            {
                preserveState: true,
                replace: true,
            },
        );

        if (closeMobilePanel) {
            setIsMobileFiltersOpen(false);
        }

        if (closeQuickSearch) {
            setIsQuickSearchOpen(false);
        }
    };

    const submitFilters = (e, options = {}) => {
        e.preventDefault();
        applyFilters(options);
    };

    const resetFilters = ({ closeMobilePanel = false, closeQuickSearch = false } = {}) => {
        setSearch('');
        setSectorId('');
        router.get(
            route('directory.index'),
            {
                mobile_all: mobileAll ? 1 : undefined,
            },
            { preserveState: true, replace: true },
        );

        if (closeMobilePanel) {
            setIsMobileFiltersOpen(false);
        }

        if (closeQuickSearch) {
            setIsQuickSearchOpen(false);
        }
    };

    const filtersControls = ({ inMobilePanel = false } = {}) => {
        const inputWidth = inMobilePanel ? 'w-full' : 'w-full lg:w-[360px]';
        const sectorWidth = inMobilePanel ? 'w-full' : 'lg:w-[170px]';
        const wrapperClass = inMobilePanel
            ? 'flex w-full flex-col gap-2'
            : 'flex w-full flex-col gap-2 lg:w-auto lg:flex-row lg:items-center';

        return (
            <div className={wrapperClass}>
                <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Rechercher (nom, prénom, email, téléphone, mobile, interne)"
                    className={`${inputWidth} rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-muted)]`}
                />

                {viewer?.is_admin ? (
                    <select
                        value={sectorId}
                        onChange={(e) => setSectorId(e.target.value)}
                        className={`rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)] ${sectorWidth}`}
                    >
                        <option value="">Tous les secteurs</option>
                        {sectors.map((sector) => (
                            <option key={sector.id} value={sector.id}>
                                {sector.name}
                            </option>
                        ))}
                    </select>
                ) : (
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-muted)]">
                        Secteur: {viewer?.sector_name || 'Non défini'}
                    </div>
                )}

                <div className="flex gap-2">
                    <button
                        type="submit"
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                    >
                        Rechercher
                    </button>
                    <button
                        type="button"
                        onClick={() => resetFilters({ closeMobilePanel: inMobilePanel })}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)]"
                    >
                        Effacer
                    </button>
                </div>
            </div>
        );
    };

    const pageHeader = (
        <form onSubmit={(e) => submitFilters(e)} className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h1 className="text-[22px] leading-none">
                <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">Annuaire</span>
            </h1>

            {filtersControls()}
        </form>
    );

    const shouldShowMobileActions = isMobile && (showFloatingActions || isQuickSearchOpen || isMobileFiltersOpen);

    return (
        <AppLayout title="Annuaire" header={pageHeader}>
            <Head title="Annuaire" />

            <div className="space-y-5">
                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                                Collaborateurs
                            </h2>
                        </div>

                        {paginationInfo ? (
                            <p className="text-xs text-[var(--app-muted)]">
                                {paginationInfo.from}-{paginationInfo.to} / {paginationInfo.total}
                            </p>
                        ) : null}
                    </div>

                    <div className="mt-4 space-y-3">
                        {(directoryUsers?.data ?? []).length === 0 ? (
                            <p className="rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-5 text-sm text-[var(--app-muted)]">
                                Aucun résultat.
                            </p>
                        ) : (
                            (directoryUsers.data ?? []).map((user, index) => (
                                <UserRow key={user.id} user={user} striped={index % 2 === 1} />
                            ))
                        )}
                    </div>

                    {!mobileAll ? <Pagination links={directoryUsers?.links ?? []} /> : null}
                </section>
            </div>

            {isMobile ? (
                <>
                    {shouldShowMobileActions ? (
                        <div className="fixed bottom-[calc(env(safe-area-inset-bottom)+5.1rem)] right-3 z-40 flex items-center gap-2">
                            <button
                                ref={quickSearchButtonRef}
                                type="button"
                                onClick={() => setIsQuickSearchOpen((current) => !current)}
                                className="inline-flex h-10 min-w-[7.5rem] items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 text-[11px] font-black uppercase tracking-[0.1em] text-[var(--app-text)] shadow-lg shadow-black/10"
                                aria-label="Ouvrir la recherche rapide"
                                aria-expanded={isQuickSearchOpen}
                            >
                                <Search className="h-3.5 w-3.5" strokeWidth={2.4} />
                                <span>Recherche</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => {
                                    setIsQuickSearchOpen(false);
                                    setIsMobileFiltersOpen(true);
                                }}
                                className="inline-flex h-10 min-w-[6.9rem] items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 text-[11px] font-black uppercase tracking-[0.1em] text-[var(--app-text)] shadow-lg shadow-black/10"
                                aria-label="Ouvrir les filtres de l'annuaire"
                            >
                                <Filter className="h-3.5 w-3.5" strokeWidth={2.4} />
                                <span>Filtres</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
                                className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] shadow-lg shadow-black/10"
                                title="Remonter en haut"
                                aria-label="Remonter en haut"
                            >
                                <ArrowUp className="h-4.5 w-4.5" strokeWidth={2.4} />
                            </button>
                        </div>
                    ) : null}

                    <div
                        ref={quickSearchPanelRef}
                        className={`fixed bottom-[calc(env(safe-area-inset-bottom)+8.4rem)] right-3 z-40 w-[min(calc(100vw-2rem),22rem)] rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 shadow-2xl transition-all duration-200 ${
                            isQuickSearchOpen ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-2 opacity-0'
                        }`}
                        aria-hidden={!isQuickSearchOpen}
                    >
                        <form onSubmit={(e) => submitFilters(e, { closeQuickSearch: true })} className="space-y-2">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Recherche rapide..."
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-muted)]"
                            />
                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setIsQuickSearchOpen(false)}
                                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                                >
                                    Fermer
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)]"
                                >
                                    Rechercher
                                </button>
                            </div>
                        </form>
                    </div>

                    <div
                        className={`fixed inset-0 z-50 transition ${isMobileFiltersOpen ? 'pointer-events-auto' : 'pointer-events-none'}`}
                        aria-hidden={!isMobileFiltersOpen}
                    >
                        <button
                            type="button"
                            onClick={() => setIsMobileFiltersOpen(false)}
                            className={`absolute inset-0 bg-black/35 transition-opacity ${isMobileFiltersOpen ? 'opacity-100' : 'opacity-0'}`}
                            aria-label="Fermer le panneau de filtres"
                        />

                        <div
                            className={`absolute inset-x-0 bottom-0 rounded-t-3xl border border-b-0 border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-2xl transition-transform duration-200 ${isMobileFiltersOpen ? 'translate-y-0' : 'translate-y-full'}`}
                        >
                            <div className="mb-3 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Search className="h-4 w-4 text-[var(--app-muted)]" />
                                    <p className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">Recherche & filtres</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setIsMobileFiltersOpen(false)}
                                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-2 text-[var(--app-text)]"
                                    aria-label="Fermer"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>

                            <form onSubmit={(e) => submitFilters(e, { closeMobilePanel: true })} className="space-y-2">
                                {filtersControls({ inMobilePanel: true })}
                            </form>
                        </div>
                    </div>
                </>
            ) : null}
        </AppLayout>
    );
}
