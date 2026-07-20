import UserMenu from '@/Layouts/AppShell/UserMenu';
import PollDisplay from '@/Components/Announcements/PollDisplay';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    Bell,
    BookUser,
    Brush,
    CalendarDays,
    CalendarX2,
    ChevronDown,
    ClipboardCheck,
    Clock3,
    Database,
    ExternalLink,
    FileText,
    Fuel,
    Home,
    ListTodo,
    Megaphone,
    Menu,
    MoonStar,
    Search,
    Shield,
    ShieldUser,
    Sun,
    Wrench,
    X,
} from 'lucide-react';
import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Dialog, DialogPanel, Menu as HeadlessMenu, MenuButton, MenuItem, MenuItems, Transition, TransitionChild } from '@headlessui/react';

function safeHasRoute(name) {
    try {
        return route().has(name);
    } catch {
        return false;
    }
}

function safeHref(name, params) {
    try {
        return route(name, params);
    } catch {
        return null;
    }
}

function safeCurrent(pattern) {
    try {
        return route().current(pattern);
    } catch {
        return false;
    }
}

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function highlightText(text, query) {
    const source = String(text ?? '');
    const normalizedQuery = String(query ?? '').trim();

    if (!normalizedQuery) {
        return source;
    }

    const terms = Array.from(new Set(
        normalizedQuery
            .split(/\s+/)
            .map((term) => term.trim())
            .filter(Boolean),
    ));

    if (terms.length === 0) {
        return source;
    }

    const pattern = new RegExp(`(${terms.map(escapeRegExp).join('|')})`, 'gi');
    const segments = source.split(pattern);

    return segments.map((segment, index) => {
        if (!segment) {
            return null;
        }

        const isMatch = terms.some((term) => term.toLowerCase() === segment.toLowerCase());
        if (!isMatch) {
            return <Fragment key={`text-${index}`}>{segment}</Fragment>;
        }

        return (
            <mark
                key={`mark-${index}`}
                style={{
                    backgroundColor: '#F1BF0C',
                    color: '#000',
                    padding: '0 2px',
                    borderRadius: '3px',
                }}
            >
                {segment}
            </mark>
        );
    });
}

function useHoverNavEnabled() {
    const [enabled, setEnabled] = useState(false);

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return;
        }

        const media = window.matchMedia('(hover: hover) and (pointer: fine)');
        const update = () => setEnabled(Boolean(media.matches));
        update();

        if (typeof media.addEventListener === 'function') {
            media.addEventListener('change', update);
            return () => media.removeEventListener('change', update);
        }

        media.addListener(update);
        return () => media.removeListener(update);
    }, []);

    return enabled;
}

function basePrimaryNavItems() {
    const items = [];

    if (safeHasRoute('dashboard')) {
        items.push({
            key: 'home',
            label: 'Accueil',
            href: safeHref('dashboard'),
            active: safeCurrent('dashboard'),
            icon: Home,
        });
    }

    return items;
}

function buildModuleNav({ isAdmin, permissions = {} }) {
    const canAdminUsers = Boolean(isAdmin || permissions?.admin_users_view || permissions?.admin_users_manage);
    const canAdminSectors = Boolean(isAdmin || permissions?.admin_sectors_view || permissions?.admin_sectors_manage);
    const canAdminEntities = Boolean(isAdmin || permissions?.admin_entities_view || permissions?.admin_entities_manage);
    const canAdminLogs = Boolean(isAdmin || permissions?.admin_logs_view);
    const canAdminLeaves = Boolean(isAdmin);
    const canAdminCotations = Boolean(isAdmin || permissions?.cotations_admin);
    const canTaskData = Boolean(
        permissions?.task_data_view
        || permissions?.task_data_jeudy_view
        || permissions?.task_data_jeudy_manage
        || permissions?.task_data_transporters_view
        || permissions?.task_data_transporters_manage
        || permissions?.task_data_depots_view
        || permissions?.task_data_depots_manage,
    );

    const canArchive = Array.isArray(permissions)
        ? permissions.includes('task.archive.view')
        : Boolean(permissions?.task_archive_view || permissions?.['task.archive.view']);
    const canCalendarView = Boolean(isAdmin || permissions?.calendar_view || permissions?.['calendar.view']);
    const canCotationsView = Boolean(isAdmin || permissions?.cotations_view || permissions?.['cotations.view']);
    const canAnnoncesView = Boolean(isAdmin || permissions?.annonces_view || permissions?.['annonces.view']);
    const canAdminAnnonces = Boolean(isAdmin || permissions?.annonces_manage || permissions?.['annonces.manage']);

    const tasksChildren = [
        safeHasRoute('ldt.index') && (permissions?.ldt_view ?? true) ? {
            key: 'ldt-book',
            label: 'Livre du Travail',
            href: safeHref('ldt.index'),
            icon: FileText,
            active: safeCurrent('ldt.*'),
        } : isAdmin ? {
            key: 'ldt-book',
            label: 'Livre du Travail',
            icon: FileText,
            disabled: true,
            hint: 'Module à venir',
        } : null,
        safeHasRoute('a_prevoir.index') && (permissions?.a_prevoir_view ?? true) ? {
            key: 'ldt-planning',
            label: 'À Prévoir',
            href: safeHref('a_prevoir.index'),
            icon: Clock3,
            active: safeCurrent('a_prevoir.*'),
        } : isAdmin ? {
            key: 'ldt-planning',
            label: 'À Prévoir',
            href: null,
            icon: Clock3,
            disabled: true,
            hint: 'Module à venir',
        } : null,
        safeHasRoute('engrais.index') && (permissions?.engrais_view ?? false) ? {
            key: 'engrais-planning',
            label: 'Engrais',
            href: safeHref('engrais.index'),
            icon: Clock3,
            active: safeCurrent('engrais.*'),
        } : isAdmin ? {
            key: 'engrais-planning',
            label: 'Engrais',
            href: safeHref('engrais.index'),
            icon: Clock3,
            active: safeCurrent('engrais.*'),
        } : null,
        safeHasRoute('task.data.index') && canTaskData ? {
            key: 'ldt-data',
            label: 'Données',
            href: safeHref('task.data.index'),
            icon: Database,
            active: safeCurrent('task.data.*'),
        } : isAdmin ? {
            key: 'ldt-data',
            label: 'Données',
            href: null,
            icon: Database,
            disabled: true,
            hint: 'Accès requis',
        } : null,
        safeHasRoute('task.fuel.index') && (permissions?.task_fuel_view ?? false) ? {
            key: 'task-fuel',
            label: 'Carburant',
            href: safeHref('task.fuel.index'),
            icon: Fuel,
            active: safeCurrent('task.fuel.*'),
        } : isAdmin ? {
            key: 'task-fuel',
            label: 'Carburant',
            href: null,
            icon: Fuel,
            disabled: true,
            hint: 'Accès requis',
        } : null,
        safeHasRoute('task.tiers.index') && (permissions?.task_tiers_view ?? false) ? {
            key: 'task-tiers',
            label: 'Tiers',
            href: safeHref('task.tiers.index'),
            icon: BookUser,
            active: safeCurrent('task.tiers.*'),
        } : isAdmin ? {
            key: 'task-tiers',
            label: 'Tiers',
            href: null,
            icon: BookUser,
            disabled: true,
            hint: 'Accès requis',
        } : null,
        safeHasRoute('task.formatting.index') && (permissions?.task_formatting_view ?? false) ? {
            key: 'ldt-style',
            label: 'Mise en forme',
            href: safeHref('task.formatting.index'),
            icon: Brush,
            active: safeCurrent('task.formatting.*'),
        } : isAdmin ? {
            key: 'ldt-style',
            label: 'Mise en forme',
            href: null,
            icon: Brush,
            disabled: true,
            hint: 'Accès requis',
        } : null,
        canArchive && safeHasRoute('task.archive.index') ? {
            key: 'ldt-archive',
            label: 'Archive',
            href: safeHref('task.archive.index'),
            icon: Archive,
            active: safeCurrent('task.archive.*'),
        } : isAdmin ? {
            key: 'ldt-archive',
            label: 'Archive',
            href: null,
            icon: Archive,
            disabled: true,
            hint: 'Accès requis',
        } : null,
    ].filter(Boolean);

    const activitiesChildren = [
        safeHasRoute('leaves.index') ? {
            key: 'conges',
            label: 'Congés',
            href: safeHref('leaves.index'),
            icon: CalendarX2,
            active: safeCurrent('leaves.*'),
        } : isAdmin ? {
            key: 'conges',
            label: 'Congés',
            href: null,
            icon: CalendarX2,
            disabled: true,
            hint: 'Module à venir',
        } : null,
        safeHasRoute('hours.index') && (permissions?.hours_view ?? false) ? {
            key: 'hours',
            label: 'Heures',
            href: safeHref('hours.index'),
            icon: Clock3,
            active: safeCurrent('hours.*'),
        } : isAdmin ? {
            key: 'hours',
            label: 'Heures',
            href: null,
            icon: Clock3,
            disabled: true,
            hint: 'Module à venir',
        } : null,
        safeHasRoute('cotations.index') && canCotationsView ? {
            key: 'cotations',
            label: 'Cotations',
            href: safeHref('cotations.index'),
            icon: FileText,
            active: safeCurrent('cotations.*'),
        } : isAdmin ? {
            key: 'cotations',
            label: 'Cotations',
            href: null,
            icon: FileText,
            disabled: true,
            hint: 'Accès requis',
        } : null,
    ].filter(Boolean);

    const toolsChildren = [
        safeHasRoute('directory.index') && {
            key: 'annuaire',
            label: 'Annuaire',
            href: safeHref('directory.index'),
            icon: BookUser,
            active: safeCurrent('directory.*'),
        },
        safeHasRoute('annonces.index') && canAnnoncesView && {
            key: 'annonces',
            label: 'Annonces',
            href: safeHref('annonces.index'),
            icon: Megaphone,
            active: safeCurrent('annonces.*'),
        },
        canAdminUsers && {
            key: 'admin-users',
            label: 'Utilisateurs',
            href: safeHref('admin.users.index'),
            icon: ShieldUser,
            active: safeCurrent('admin.users.*'),
        },
        canAdminSectors && {
            key: 'admin-sectors',
            label: 'Secteurs',
            href: safeHref('admin.sectors.index'),
            icon: Shield,
            active: safeCurrent('admin.sectors.*'),
        },
        canAdminLeaves && {
            key: 'admin-leaves',
            label: 'Congés',
            href: safeHref('admin.leaves.index'),
            icon: CalendarX2,
            active: safeCurrent('admin.leaves.*'),
        },
        canAdminEntities && {
            key: 'admin-entities',
            label: 'Entités',
            href: safeHref('admin.entities'),
            icon: Database,
            active: safeCurrent('admin.entities*'),
        },
        canAdminLogs && {
            key: 'admin-logs',
            label: 'Logs',
            href: safeHref('admin.logs.index'),
            icon: FileText,
            active: safeCurrent('admin.logs.*'),
        },
        canAdminCotations && {
            key: 'admin-cotations',
            label: 'Cotations',
            href: safeHref('admin.cotations.index'),
            icon: FileText,
            active: safeCurrent('admin.cotations.*'),
        },
        canAdminAnnonces && {
            key: 'admin-annonces',
            label: 'Droits Annonces',
            href: safeHref('admin.annonces.index'),
            icon: Megaphone,
            active: safeCurrent('admin.annonces.*'),
        },
        {
            key: 'crm',
            label: 'CRM',
            href: 'https://grc.jeudy-sa.fr',
            icon: ExternalLink,
            external: true,
        },
        {
            key: 'intranet',
            label: 'Intranet',
            href: 'http://intranet.jeudy-sa.fr/',
            icon: ExternalLink,
            external: true,
        },
    ].filter(Boolean);

    const modules = [
        {
            key: 'tasks',
            label: 'Tâches',
            icon: ListTodo,
            active: tasksChildren.some((item) => item.active),
            children: tasksChildren,
        },
        activitiesChildren.length ? {
            key: 'activities',
            label: 'Activité',
            icon: ClipboardCheck,
            active: activitiesChildren.some((item) => item.active),
            children: activitiesChildren,
        } : null,
        safeHasRoute('calendar.index') && canCalendarView ? {
            key: 'calendar',
            label: 'Calendrier',
            icon: CalendarDays,
            active: safeCurrent('calendar.*'),
            href: safeHref('calendar.index'),
            children: [],
            simple: true,
        } : isAdmin ? {
            key: 'calendar',
            label: 'Calendrier',
            icon: CalendarDays,
            active: false,
            href: null,
            disabled: true,
            hint: 'Accès requis',
            children: [],
            simple: true,
        } : null,
        {
            key: 'tools',
            label: 'Outils',
            icon: Wrench,
            active: toolsChildren.some((item) => item.active),
            children: toolsChildren,
        },
    ].filter(Boolean);

    return modules.filter((module) => module.simple || module.children?.length);
}

function ThemeSwitch({ isDark, onToggleTheme }) {
    const selectTheme = (target) => {
        const shouldBeDark = target === 'dark';
        if (shouldBeDark !== isDark) {
            onToggleTheme();
        }
    };

    return (
        <div
            className="relative inline-grid h-10 w-[72px] grid-cols-2 items-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] p-1"
            role="group"
            aria-label="Selection du theme"
        >
            <span
                aria-hidden="true"
                className={classNames(
                    'absolute left-1 top-1 h-8 w-8 rounded-full bg-[var(--brand-yellow-dark)] transition-transform duration-200 ease-out',
                    isDark ? 'translate-x-8' : 'translate-x-0',
                )}
            />

            <button
                type="button"
                onClick={() => selectTheme('light')}
                aria-label="Theme clair"
                aria-pressed={!isDark}
                title="Theme clair"
                className={classNames(
                    'relative z-10 inline-flex h-8 w-8 items-center justify-center rounded-full transition focus:outline-none',
                    !isDark ? 'text-[var(--color-black)]' : 'text-[var(--app-text)]',
                )}
            >
                <Sun className="block h-7 w-7" strokeWidth={2} />
            </button>

            <button
                type="button"
                onClick={() => selectTheme('dark')}
                aria-label="Theme sombre"
                aria-pressed={isDark}
                title="Theme sombre"
                className={classNames(
                    'relative z-10 inline-flex h-8 w-8 items-center justify-center rounded-full transition focus:outline-none',
                    isDark ? 'text-[var(--color-black)]' : 'text-[var(--app-text)]',
                )}
            >
                <MoonStar className="block h-7 w-7" strokeWidth={2} />
            </button>
        </div>
    );
}

function GlobalSearchVisual() {
    const [isOpen, setIsOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [errorMessage, setErrorMessage] = useState('');
    const [hasSearched, setHasSearched] = useState(false);
    const [resultScrollTop, setResultScrollTop] = useState(0);
    const [resultViewportHeight, setResultViewportHeight] = useState(320);
    const containerRef = useRef(null);
    const inputRef = useRef(null);
    const resultListRef = useRef(null);
    const focusInput = useCallback(() => {
        window.requestAnimationFrame(() => {
            inputRef.current?.focus();
        });
    }, []);
    const closeAndResetSearch = useCallback(() => {
        setIsOpen(false);
        setQuery('');
        setResults([]);
        setIsLoading(false);
        setErrorMessage('');
        setHasSearched(false);
    }, []);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        focusInput();
    }, [focusInput, isOpen]);

    useEffect(() => {
        const handleGlobalSearchShortcuts = (event) => {
            const key = String(event.key || '').toLowerCase();
            const isOpenShortcut = key === 'k' && (event.metaKey || event.ctrlKey);

            if (isOpenShortcut) {
                event.preventDefault();
                setIsOpen(true);
                focusInput();
                return;
            }

            if (event.key === 'Escape' && isOpen) {
                event.preventDefault();
                closeAndResetSearch();
            }
        };

        document.addEventListener('keydown', handleGlobalSearchShortcuts);
        return () => document.removeEventListener('keydown', handleGlobalSearchShortcuts);
    }, [closeAndResetSearch, focusInput, isOpen]);

    useEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        const handlePointerDown = (event) => {
            if (containerRef.current?.contains(event.target)) {
                return;
            }

            setIsOpen(false);
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
        };
    }, [isOpen]);

    useEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        const trimmed = query.trim();

        if (trimmed.length < 2) {
            setResults([]);
            setIsLoading(false);
            setErrorMessage('');
            setHasSearched(false);
            return undefined;
        }

        const controller = new AbortController();
        const timeoutId = window.setTimeout(async () => {
            try {
                setIsLoading(true);
                setErrorMessage('');

                const response = await fetch(`/global-search?q=${encodeURIComponent(trimmed)}`, {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`Search failed (${response.status})`);
                }

                const payload = await response.json();
                setResults(Array.isArray(payload) ? payload : []);
                setHasSearched(true);
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                setResults([]);
                setErrorMessage('Erreur lors de la recherche.');
                setHasSearched(true);
            } finally {
                setIsLoading(false);
            }
        }, 300);

        return () => {
            window.clearTimeout(timeoutId);
            controller.abort();
        };
    }, [isOpen, query]);

    const showDropdown = isOpen && (query.trim().length >= 2 || isLoading || errorMessage !== '' || hasSearched);

    useEffect(() => {
        if (!showDropdown) {
            return undefined;
        }

        const measureResultViewport = () => {
            setResultViewportHeight(resultListRef.current?.clientHeight || 320);
        };

        measureResultViewport();
        window.addEventListener('resize', measureResultViewport);

        return () => window.removeEventListener('resize', measureResultViewport);
    }, [showDropdown, results.length]);

    useEffect(() => {
        setResultScrollTop(0);
        resultListRef.current?.scrollTo({ top: 0 });
    }, [query]);

    const resultItemHeight = 74;
    const shouldVirtualizeResults = results.length > 80;
    const virtualStartIndex = shouldVirtualizeResults
        ? Math.max(0, Math.floor(resultScrollTop / resultItemHeight) - 6)
        : 0;
    const virtualEndIndex = shouldVirtualizeResults
        ? Math.min(results.length, Math.ceil((resultScrollTop + resultViewportHeight) / resultItemHeight) + 6)
        : results.length;
    const renderedResults = useMemo(
        () => results.slice(virtualStartIndex, virtualEndIndex),
        [results, virtualEndIndex, virtualStartIndex],
    );
    const footerText = results.length > 0
        ? `${results.length} résultat${results.length > 1 ? 's' : ''} affiché${results.length > 1 ? 's' : ''}`
        : 'Tapez pour affiner votre recherche';

    const handleResultClick = (url) => {
        if (!url) {
            return;
        }

        closeAndResetSearch();
        window.location.assign(url);
    };

    return (
        <div ref={containerRef} className="relative h-10">
            <input
                ref={inputRef}
                type="text"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Recherche globale..."
                aria-label="Recherche globale"
                className={classNames(
                    'h-10 rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-sm text-[var(--app-text)] shadow-sm outline-none transition-all duration-200 ease-out placeholder:text-[var(--app-muted)]',
                    isOpen
                        ? 'w-[11rem] pr-3 pl-10 opacity-100 sm:w-[14rem]'
                        : 'w-10 cursor-default pr-0 pl-0 opacity-0',
                )}
            />

            <button
                type="button"
                onClick={() => {
                    setIsOpen((current) => {
                        const next = !current;
                        if (next) {
                            focusInput();
                        }
                        return next;
                    });
                }}
                aria-label="Ouvrir la recherche globale"
                aria-expanded={isOpen}
                className="absolute left-0 top-0 inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] transition hover:border-[var(--brand-brown)]"
            >
                <Search className="h-4.5 w-4.5 text-[#0F6930]" strokeWidth={2.1} />
            </button>

            {showDropdown ? (
                <div className="fixed left-3 right-3 top-[calc(var(--app-navbar-height,72px)_+_0.5rem)] z-50 flex max-h-[calc(100vh_-_var(--app-navbar-height,72px)_-_1rem)] flex-col overflow-hidden rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl sm:absolute sm:left-auto sm:right-0 sm:top-11 sm:w-[min(26rem,calc(100vw-1rem))] sm:max-h-[calc(100vh_-_7rem)]">
                    <div
                        ref={resultListRef}
                        onScroll={(event) => setResultScrollTop(event.currentTarget.scrollTop)}
                        className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2"
                    >
                        {isLoading ? (
                            <p className="rounded-xl px-3 py-2 text-sm text-[var(--app-muted)]">Chargement...</p>
                        ) : null}

                        {!isLoading && errorMessage !== '' ? (
                            <p className="rounded-xl px-3 py-2 text-sm text-red-600">{errorMessage}</p>
                        ) : null}

                        {!isLoading && errorMessage === '' && results.length === 0 && hasSearched ? (
                            <p className="rounded-xl px-3 py-2 text-sm text-[var(--app-muted)]">Aucun résultat</p>
                        ) : null}

                        {!isLoading && errorMessage === '' && results.length > 0 ? (
                            <ul className="space-y-1">
                                {shouldVirtualizeResults && virtualStartIndex > 0 ? (
                                    <li aria-hidden="true" style={{ height: virtualStartIndex * resultItemHeight }} />
                                ) : null}

                                {renderedResults.map((result, index) => (
                                    <li
                                        key={`${result.type || 'result'}-${result.url || ''}-${virtualStartIndex + index}`}
                                        className="min-h-[74px]"
                                    >
                                        <button
                                            type="button"
                                            onClick={() => handleResultClick(result.url)}
                                            className="block w-full rounded-xl px-3 py-2 text-left transition hover:bg-[var(--app-surface-soft)]"
                                        >
                                            <p className="text-sm font-semibold text-[var(--app-text)]">{highlightText(result.label || '-', query)}</p>
                                            <p className="mt-0.5 text-xs text-[var(--app-muted)]">{highlightText(result.description || '', query)}</p>
                                            <p className="mt-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-[var(--app-muted)]">{result.type || ''}</p>
                                        </button>
                                    </li>
                                ))}

                                {shouldVirtualizeResults && virtualEndIndex < results.length ? (
                                    <li aria-hidden="true" style={{ height: (results.length - virtualEndIndex) * resultItemHeight }} />
                                ) : null}
                            </ul>
                        ) : null}
                    </div>

                    <div className="flex-shrink-0 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-[11px] font-semibold text-[var(--app-muted)]">
                        {footerText}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function DesktopModuleDropdown({ module }) {
    const Icon = module.icon;
    const canHover = useHoverNavEnabled();
    const [hoverOpen, setHoverOpen] = useState(false);
    const closeTimeoutRef = useRef(null);
    const buttonRef = useRef(null);

    if (module.simple) {
        if (module.href && !module.disabled) {
            return (
                <Link
                    href={module.href}
                    className={classNames(
                        'inline-flex h-10 items-center gap-2 rounded-2xl px-3.5 text-sm font-semibold transition',
                        module.active
                            ? 'bg-[var(--app-surface-soft)] text-[var(--app-text)] shadow-sm'
                            : 'text-[var(--app-text)] hover:bg-[var(--app-surface-soft)]',
                    )}
                >
                    <Icon className="h-5 w-5 text-[#0F6930]" strokeWidth={2.1} />
                    <span>{module.label}</span>
                </Link>
            );
        }

        return (
            <span
                className={classNames(
                    'inline-flex h-10 items-center gap-2 rounded-2xl px-3.5 text-sm font-semibold transition',
                    module.disabled
                        ? 'cursor-not-allowed opacity-60'
                        : 'text-[var(--app-text)] hover:bg-[var(--app-surface-soft)]',
                )}
            >
                <Icon className="h-5 w-5 text-[#0F6930]" strokeWidth={2.1} />
                <span>{module.label}</span>
            </span>
        );
    }

    return (
        <HeadlessMenu as="div" className="relative">
            {({ open }) => (
                <div
                    onMouseEnter={() => {
                        if (!canHover) return;
                        if (closeTimeoutRef.current) {
                            clearTimeout(closeTimeoutRef.current);
                            closeTimeoutRef.current = null;
                        }
                        setHoverOpen(true);
                    }}
                    onMouseLeave={() => {
                        if (!canHover) return;
                        if (closeTimeoutRef.current) {
                            clearTimeout(closeTimeoutRef.current);
                        }
                        closeTimeoutRef.current = setTimeout(() => {
                            setHoverOpen(false);
                            if (open && buttonRef.current) {
                                buttonRef.current.click();
                            }
                        }, 140);
                    }}
                >
                    <MenuButton
                        ref={buttonRef}
                        className={classNames(
                            'inline-flex h-10 items-center gap-2 rounded-2xl px-3.5 text-sm font-semibold transition',
                            module.active
                                ? 'bg-[var(--app-surface-soft)] text-[var(--app-text)] shadow-sm'
                                : 'text-[var(--app-text)] hover:bg-[var(--app-surface-soft)]',
                        )}
                    >
                        <Icon className="h-5 w-5 text-[#0F6930]" strokeWidth={2.1} />
                        <span>{module.label}</span>
                        <ChevronDown className="h-4 w-4 opacity-70" strokeWidth={2} />
                    </MenuButton>

                    <Transition
                        as={Fragment}
                        show={open || (canHover && hoverOpen)}
                        enter="transition ease-out duration-200"
                        enterFrom="opacity-0 translate-y-1"
                        enterTo="opacity-100 translate-y-0"
                        leave="transition ease-in duration-150"
                        leaveFrom="opacity-100 translate-y-0"
                        leaveTo="opacity-0 -translate-y-1"
                    >
                        <MenuItems
                            static
                            className="absolute left-0 z-50 mt-2 w-72 overflow-hidden rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-xl focus:outline-none"
                        >
                            {module.children.map((item) => {
                                const ItemIcon = item.icon;
                                return (
                                    <MenuItem key={item.key} disabled={item.disabled}>
                                        {({ focus, disabled }) => {
                                            const content = (
                                                <span
                                                    className={classNames(
                                                        'flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm',
                                                        disabled
                                                            ? 'cursor-not-allowed opacity-50'
                                                            : focus || item.active
                                                                ? 'bg-[var(--app-surface-soft)]'
                                                                : '',
                                                    )}
                                                >
                                                    <span className="flex items-center gap-2.5">
                                                        <ItemIcon className="h-[18px] w-[18px] text-[#0F6930]" strokeWidth={2.1} />
                                                        <span>{item.label}</span>
                                                    </span>
                                                    {item.external ? <ExternalLink className="h-3.5 w-3.5 opacity-60" strokeWidth={2} /> : null}
                                                </span>
                                            );

                                            if (disabled || !item.href) {
                                                return content;
                                            }

                                            if (item.external) {
                                                return (
                                                    <a href={item.href} target="_blank" rel="noreferrer" className="block">
                                                        {content}
                                                    </a>
                                                );
                                            }

                                            return (
                                                <Link href={item.href} className="block">
                                                    {content}
                                                </Link>
                                            );
                                        }}
                                    </MenuItem>
                                );
                            })}
                        </MenuItems>
                    </Transition>
                </div>
            )}
        </HeadlessMenu>
    );
}

function NotificationsMenu() {
    const { notifications: notificationsProp, errors: pageErrors = {} } = usePage().props;
    const initialNotifications = Array.isArray(notificationsProp?.items) ? notificationsProp.items : [];
    const initialUnreadCount = Number(notificationsProp?.unread_count ?? initialNotifications.filter((notification) => !notification?.read_at).length);
    const [notifications, setNotifications] = useState(initialNotifications);
    const [unreadCount, setUnreadCount] = useState(initialUnreadCount);
    const [expandedNotificationIds, setExpandedNotificationIds] = useState(new Set());
    const [announcementModal, setAnnouncementModal] = useState(null);
    const [pollResponseProcessing, setPollResponseProcessing] = useState(false);
    const hasUnread = unreadCount > 0;

    const openAnnouncementModal = (notification) => setAnnouncementModal(notification);

    useEffect(() => {
        setAnnouncementModal((prev) => {
            if (!prev) return prev;
            const updated = notifications.find((notification) => notification.id === prev.id);
            return updated || prev;
        });
    }, [notifications]);

    const submitPollResponseFor = (announcementId, payload) => {
        if (!announcementId) return;
        setPollResponseProcessing(true);
        router.post(route('annonces.poll-response', announcementId), payload, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setPollResponseProcessing(false),
        });
    };

    const submitAnnouncementPollResponse = (payload) => {
        submitPollResponseFor(announcementModal?.announcement_id, payload);
    };

    const toggleExpanded = (notificationId, event) => {
        event.stopPropagation();
        setExpandedNotificationIds((prev) => {
            const next = new Set(prev);
            if (next.has(notificationId)) {
                next.delete(notificationId);
            } else {
                next.add(notificationId);
            }
            return next;
        });
    };

    useEffect(() => {
        const nextNotifications = Array.isArray(notificationsProp?.items) ? notificationsProp.items : [];
        setNotifications(nextNotifications);
        setUnreadCount(Number(
            notificationsProp?.unread_count
            ?? nextNotifications.filter((notification) => !notification?.read_at).length,
        ));
    }, [notificationsProp]);

    const refreshNotifications = useCallback(async () => {
        const latestUrl = safeHref('notifications.latest');
        if (!latestUrl) {
            return;
        }

        const response = await fetch(latestUrl, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        const nextNotifications = Array.isArray(payload?.notifications) ? payload.notifications : [];
        setNotifications(nextNotifications);
        setUnreadCount(Number(payload?.unread_count ?? nextNotifications.filter((notification) => !notification?.read_at).length));
    }, []);

    useEffect(() => {
        refreshNotifications().catch(() => null);
        const intervalId = window.setInterval(() => {
            refreshNotifications().catch(() => null);
        }, 30000);

        return () => window.clearInterval(intervalId);
    }, [refreshNotifications]);

    const markAsRead = (notificationId) => new Promise((resolve) => {
        const readUrl = safeHref('notifications.read', notificationId);
        if (!readUrl) {
            resolve(false);
            return;
        }

        router.post(
            readUrl,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onSuccess: () => {
                    setNotifications((currentNotifications) => currentNotifications.map((notification) => (
                        notification.id === notificationId
                            ? { ...notification, read_at: notification.read_at ?? new Date().toISOString() }
                            : notification
                    )));
                    setUnreadCount((currentUnreadCount) => Math.max(0, currentUnreadCount - 1));
                    resolve(true);
                },
                onError: () => resolve(false),
                onCancel: () => resolve(false),
            },
        );
    });

    const markAllAsRead = () => new Promise((resolve) => {
        const readAllUrl = safeHref('notifications.read_all');
        if (!readAllUrl) {
            resolve(false);
            return;
        }

        router.post(
            readAllUrl,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onSuccess: () => {
                    setNotifications((currentNotifications) => currentNotifications.map((notification) => ({
                        ...notification,
                        read_at: notification.read_at ?? new Date().toISOString(),
                    })));
                    setUnreadCount(0);
                    resolve(true);
                },
                onError: () => resolve(false),
                onCancel: () => resolve(false),
            },
        );
    });

    const deleteNotification = (notificationId) => new Promise((resolve) => {
        const deleteUrl = safeHref('notifications.destroy', notificationId);
        if (!deleteUrl) {
            resolve(false);
            return;
        }

        router.delete(
            deleteUrl,
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onSuccess: () => {
                    setNotifications((currentNotifications) =>
                        currentNotifications.filter((notification) => notification.id !== notificationId),
                    );
                    resolve(true);
                },
                onError: () => resolve(false),
                onCancel: () => resolve(false),
            },
        );
    });

    const handleNotificationClick = async (notification) => {
        if (!notification?.id) {
            return;
        }

        if (!notification?.read_at) {
            await markAsRead(notification.id);
        }

        if (notification?.url) {
            window.location.assign(notification.url);
        }
    };

    const formatNotificationDate = (value) => {
        if (!value) {
            return '';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();

        return `${day}-${month}-${year}`;
    };

    const formatIsoDateFr = (isoDate) => {
        if (!isoDate || typeof isoDate !== 'string') {
            return '-';
        }

        const match = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) {
            return isoDate;
        }

        const [, year, month, day] = match;
        return `${day}-${month}-${year}`;
    };

    const buildLeaveNotificationMessage = (notification) => {
        const type = String(notification?.type || '');
        const startAt = formatIsoDateFr(notification?.period?.start_at || null);
        const endAt = formatIsoDateFr(notification?.period?.end_at || null);

        if (type === 'leave_request_approved') {
            return `Votre demande du ${startAt} au ${endAt} a été approuvée.`;
        }

        if (type === 'leave_request_refused') {
            return `Votre demande du ${startAt} au ${endAt} a été refusée.`;
        }

        if (type === 'leave_request_submitted') {
            let requesterLabel = String(notification?.requester_label || '').trim();
            if (!requesterLabel) {
                const rawMessage = String(notification?.message || '');
                const extracted = rawMessage.match(/Nouvelle demande de congé de (.+?) du /i);
                if (extracted?.[1]) {
                    requesterLabel = extracted[1].trim();
                }
            }
            return `Nouvelle demande de congé de ${requesterLabel || 'un collaborateur'} du ${startAt} au ${endAt}.`;
        }

        return String(notification?.message || '').replace(/#\d+/g, '').trim() || notification?.type;
    };

    const notificationItemClassName = (notification) => {
        const type = String(notification?.type || '');

        if (type === 'leave_request_approved') {
            return 'rounded-xl border px-3 py-2.5 border-green-200 bg-green-50';
        }

        if (type === 'leave_request_refused') {
            return 'rounded-xl border px-3 py-2.5 border-red-200 bg-red-50';
        }

        if (type === 'leave_request_submitted') {
            return 'rounded-xl border px-3 py-2.5 border-[var(--app-border)] bg-[var(--app-surface-soft)]';
        }

        return 'rounded-xl border px-3 py-2.5 border-[var(--app-border)] bg-[var(--app-surface-soft)]';
    };

    return (
        <>
        <HeadlessMenu as="div" className="relative">
            <MenuButton className={classNames(
                'relative inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] transition hover:border-[var(--brand-brown)]',
                hasUnread ? 'notif-bell-attention' : '',
            )}>
                <Bell className="h-5 w-5 text-[#0F6930]" strokeWidth={2} />
                {unreadCount > 0 ? (
                    <span className={classNames(
                        'absolute -right-1 -top-1 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-black text-white',
                        hasUnread ? 'notif-badge-pulse' : '',
                    )}>
                        {unreadCount}
                    </span>
                ) : null}
            </MenuButton>

            <Transition
                as={Fragment}
                enter="transition ease-out duration-150"
                enterFrom="opacity-0 translate-y-1"
                enterTo="opacity-100 translate-y-0"
                leave="transition ease-in duration-100"
                leaveFrom="opacity-100 translate-y-0"
                leaveTo="opacity-0 translate-y-1"
            >
                <MenuItems className="fixed left-1/2 top-16 z-50 w-[calc(100vw-24px)] -translate-x-1/2 overflow-hidden rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl focus:outline-none sm:absolute sm:right-0 sm:left-auto sm:top-auto sm:mt-2 sm:w-[min(24rem,calc(100vw-1rem))] sm:translate-x-0">
                    <div className="flex items-center justify-between gap-2 border-b border-[var(--app-border)] px-4 py-3">
                        <p className="text-sm font-semibold">Notifications</p>
                        {hasUnread ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        markAllAsRead().then(() => {
                                            refreshNotifications().catch(() => null);
                                        });
                                    }}
                                    className="text-xs font-semibold text-[#0F6930] transition hover:opacity-80"
                                >
                                    Tout marquer comme lu
                            </button>
                        ) : null}
                    </div>
                    <div className="p-3">
                        {notifications.length > 0 ? (
                            <div className="max-h-80 space-y-2 overflow-y-auto pr-1">
                                {notifications.map((notification) => {
                                    const isAnnouncement = notification.type === 'announcement';
                                    const isExpanded = expandedNotificationIds.has(notification.id);
                                    const canExpand = isAnnouncement && (
                                        notification.full_message
                                        || String(notification.message ?? '').endsWith('…')
                                    );

                                    return (
                                        <div
                                            key={notification.id}
                                            role="button"
                                            tabIndex={0}
                                            onClick={() => {
                                                if (isAnnouncement) {
                                                    openAnnouncementModal(notification);
                                                } else {
                                                    handleNotificationClick(notification);
                                                }
                                            }}
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter' || event.key === ' ') {
                                                    event.preventDefault();
                                                    if (isAnnouncement) {
                                                        openAnnouncementModal(notification);
                                                    } else {
                                                        handleNotificationClick(notification).catch(() => null);
                                                    }
                                                }
                                            }}
                                            className={classNames(notificationItemClassName(notification), 'cursor-pointer')}
                                        >
                                            {isAnnouncement ? (
                                                <>
                                                    {notification.title ? (
                                                        <p className="text-sm font-bold leading-snug text-[var(--app-text)]">{notification.title}</p>
                                                    ) : null}
                                                    <p className={classNames(
                                                        'text-sm leading-[1.25rem] text-[var(--app-text)] whitespace-pre-line',
                                                        notification.title ? 'mt-0.5' : '',
                                                        !isExpanded ? 'line-clamp-3' : '',
                                                    )}>
                                                        {isExpanded
                                                            ? (notification.full_message || notification.message)
                                                            : notification.message}
                                                    </p>
                                                    {canExpand ? (
                                                        <button
                                                            type="button"
                                                            onClick={(event) => toggleExpanded(notification.id, event)}
                                                            className="mt-0.5 text-xs font-semibold text-[#0F6930] transition hover:opacity-80"
                                                        >
                                                            {isExpanded ? 'Réduire' : 'Lire plus'}
                                                        </button>
                                                    ) : null}
                                                    {isExpanded && notification.poll ? (
                                                        <div className="mt-2" onClick={(event) => event.stopPropagation()}>
                                                            <PollDisplay
                                                                poll={notification.poll}
                                                                variant="full"
                                                                onSubmitResponse={(payload) => submitPollResponseFor(notification.announcement_id, payload)}
                                                                responseProcessing={pollResponseProcessing}
                                                                errors={pageErrors}
                                                            />
                                                        </div>
                                                    ) : null}
                                                </>
                                            ) : (
                                                <p className="text-sm text-[var(--app-text)]">
                                                    {buildLeaveNotificationMessage(notification)}
                                                </p>
                                            )}
                                            <p className="mt-1 text-xs text-[var(--app-muted)]">
                                                {formatNotificationDate(notification.created_at)}
                                                {!notification.read_at ? ' • Non lue' : ''}
                                            </p>
                                            {!notification.read_at && !isAnnouncement ? (
                                                <button
                                                    type="button"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        markAsRead(notification.id).then(() => {
                                                            refreshNotifications().catch(() => null);
                                                        });
                                                    }}
                                                    className="mt-1 text-xs font-semibold text-[#0F6930] transition hover:opacity-80"
                                                >
                                                    Marquer comme lu
                                                </button>
                                            ) : null}
                                            {notification.read_at && !isAnnouncement ? (
                                                <button
                                                    type="button"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        deleteNotification(notification.id);
                                                    }}
                                                    className="mt-1 text-xs font-semibold text-[#0F6930] transition hover:opacity-80"
                                                >
                                                    Effacer
                                                </button>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-4 text-sm text-[var(--app-muted)]">
                                Aucune notification pour le moment.
                            </div>
                        )}
                    </div>
                </MenuItems>
            </Transition>
        </HeadlessMenu>

        <Transition show={announcementModal !== null} leave="duration-200">
            <Dialog
                as="div"
                className="fixed inset-0 z-[200] flex transform items-start overflow-y-auto px-3 py-4 transition-all sm:items-center sm:px-4 sm:py-6"
                onClose={() => setAnnouncementModal(null)}
            >
                <TransitionChild
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-gray-500/75" />
                </TransitionChild>

                <TransitionChild
                    enter="ease-out duration-300"
                    enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enterTo="opacity-100 translate-y-0 sm:scale-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                    leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <DialogPanel className="relative mx-auto w-full max-w-[calc(100vw-1.5rem)] transform overflow-hidden rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl transition-all sm:max-w-lg">
                        <div className="flex items-start justify-between gap-3 border-b border-[var(--app-border)] px-5 py-4">
                            <div className="min-w-0">
                                <p className="break-words text-base font-bold leading-snug text-[var(--app-text)]">
                                    {announcementModal?.title || 'Annonce'}
                                </p>
                                <p className="mt-0.5 text-xs text-[var(--app-muted)]">
                                    {formatNotificationDate(announcementModal?.created_at)}
                                    {announcementModal?.announcement_author ? ` • Publié par ${announcementModal.announcement_author}` : ''}
                                    {!announcementModal?.read_at ? ' • Non lue' : ''}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setAnnouncementModal(null)}
                                className="mt-0.5 flex-shrink-0 rounded-lg p-1 text-[var(--app-muted)] transition hover:bg-[var(--app-surface-soft)] hover:text-[var(--app-text)]"
                                aria-label="Fermer"
                            >
                                <X className="h-4 w-4" strokeWidth={2} />
                            </button>
                        </div>
                        <div className="max-h-[70vh] space-y-3 overflow-y-auto px-5 py-4">
                            <p className="whitespace-pre-line text-sm leading-relaxed text-[var(--app-text)]">
                                {announcementModal?.full_message || announcementModal?.message}
                            </p>
                            {announcementModal?.poll ? (
                                <PollDisplay
                                    poll={announcementModal.poll}
                                    variant="full"
                                    onSubmitResponse={submitAnnouncementPollResponse}
                                    responseProcessing={pollResponseProcessing}
                                    errors={pageErrors}
                                />
                            ) : null}
                        </div>
                        {!announcementModal?.read_at ? (
                            <div className="border-t border-[var(--app-border)] px-5 py-3">
                                <button
                                    type="button"
                                    onClick={() => {
                                        markAsRead(announcementModal.id).then(() => {
                                            setAnnouncementModal((prev) => prev ? { ...prev, read_at: new Date().toISOString() } : null);
                                            refreshNotifications().catch(() => null);
                                        });
                                    }}
                                    className="text-sm font-semibold text-[#0F6930] transition hover:opacity-80"
                                >
                                    Marquer comme lu
                                </button>
                            </div>
                        ) : null}
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
        </>
    );
}

function MobileBottomModules({ modules, openKey, onToggleKey, onClose }) {
    const activeModule = modules.find((module) => module.key === openKey) ?? null;
    const dashboardItem = safeHasRoute('dashboard')
        ? {
            href: safeHref('dashboard'),
            active: safeCurrent('dashboard'),
            label: 'Accueil',
            icon: Home,
        }
        : null;

    return (
        <>
            {openKey ? (
                <button
                    type="button"
                    aria-label="Fermer le menu modules"
                    onClick={onClose}
                    className="fixed inset-0 z-30 bg-transparent md:hidden"
                />
            ) : null}

            {activeModule ? (
                <Transition
                    appear
                    show={Boolean(activeModule)}
                    as={Fragment}
                    enter="transition ease-out duration-200"
                    enterFrom="opacity-0 translate-y-2"
                    enterTo="opacity-100 translate-y-0"
                    leave="transition ease-in duration-150"
                    leaveFrom="opacity-100 translate-y-0"
                    leaveTo="opacity-0 translate-y-2"
                >
                    <div className="app-mobile-bottom-nav fixed inset-x-4 bottom-[calc(env(safe-area-inset-bottom)+4.75rem)] z-40 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-2xl md:hidden">
                        <div className="max-h-[45vh] overflow-y-auto">
                            {activeModule.children.map((item) => {
                                const ItemIcon = item.icon;
                                const itemClasses = classNames(
                                    'flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-sm',
                                    item.disabled || !item.href
                                        ? 'cursor-not-allowed opacity-50'
                                        : item.active
                                            ? 'bg-[var(--app-surface-soft)] font-semibold'
                                            : 'hover:bg-[var(--app-surface-soft)]',
                                );

                                const inner = (
                                    <span className={itemClasses}>
                                        <ItemIcon className="h-[18px] w-[18px] text-[#0F6930]" strokeWidth={2.1} />
                                        <span className="truncate">{item.label}</span>
                                        {item.external ? <ExternalLink className="ml-auto h-3.5 w-3.5 opacity-60" strokeWidth={2} /> : null}
                                    </span>
                                );

                                if (item.disabled || !item.href) {
                                    return <div key={item.key}>{inner}</div>;
                                }

                                if (item.external) {
                                    return (
                                        <a key={item.key} href={item.href} target="_blank" rel="noreferrer" onClick={onClose}>
                                            {inner}
                                        </a>
                                    );
                                }

                                return (
                                    <Link key={item.key} href={item.href} onClick={onClose}>
                                        {inner}
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                </Transition>
            ) : null}

            <div className="app-mobile-bottom-nav fixed inset-x-3 bottom-[calc(env(safe-area-inset-bottom)+18px)] z-40 md:hidden">
                <div className="mx-auto flex max-w-[640px] items-center justify-between rounded-[22px] border border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)]/70 px-2 py-1 shadow-lg shadow-[var(--brand-yellow-dark)]/20 backdrop-blur">
                    {modules.slice(0, 2).map((module) => {
                        const Icon = module.icon;
                        const isOpen = openKey === module.key;

                        if (module.simple && module.href && !module.disabled) {
                            return (
                                <Link
                                    key={module.key}
                                    href={module.href}
                                    onClick={onClose}
                                    className={classNames(
                                        'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-2xl px-1 py-1 text-[10px] font-semibold leading-tight',
                                        module.active
                                            ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                            : 'text-[var(--color-black)]/90',
                                    )}
                                >
                                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--app-surface)]/35">
                                        <Icon className="h-[18px] w-[18px] text-[#0F6930]" strokeWidth={2.1} />
                                    </span>
                                    <span className="truncate">{module.label}</span>
                                </Link>
                            );
                        }

                        return (
                            <button
                                key={module.key}
                                type="button"
                                onClick={() => {
                                    if (module.simple) return;
                                    onToggleKey(module.key);
                                }}
                                aria-disabled={module.simple}
                                className={classNames(
                                    'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-2xl px-1 py-1 text-[10px] font-semibold leading-tight',
                                    isOpen || module.active
                                        ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                        : 'text-[var(--color-black)]/90',
                                    module.simple ? 'opacity-60' : '',
                                )}
                            >
                                <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--app-surface)]/35">
                                    <Icon className="h-[18px] w-[18px] text-[#0F6930]" strokeWidth={2.1} />
                                </span>
                                <span className="truncate">{module.label}</span>
                            </button>
                        );
                    })}

                    {dashboardItem ? (
                        <Link
                            href={dashboardItem.href}
                            onClick={onClose}
                            className={classNames(
                                'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-2xl px-1 py-1 text-[10px] font-semibold leading-tight',
                                dashboardItem.active
                                    ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                    : 'text-[var(--color-black)]/90',
                            )}
                        >
                            {(() => {
                                const DashboardIcon = dashboardItem.icon;
                                return (
                                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--app-surface)]/35">
                                        <DashboardIcon className="h-[18px] w-[18px] text-[#0F6930]" strokeWidth={2.1} />
                                    </span>
                                );
                            })()}
                            <span className="truncate">Accueil</span>
                        </Link>
                    ) : null}

                    {modules.slice(2, 4).map((module) => {
                        const Icon = module.icon;
                        const isOpen = openKey === module.key;

                        if (module.simple && module.href && !module.disabled) {
                            return (
                                <Link
                                    key={module.key}
                                    href={module.href}
                                    onClick={onClose}
                                    className={classNames(
                                        'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-2xl px-1 py-1 text-[10px] font-semibold leading-tight',
                                        module.active
                                            ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                            : 'text-[var(--color-black)]/90',
                                    )}
                                >
                                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--app-surface)]/35">
                                        <Icon className="h-[18px] w-[18px] text-[#0F6930]" strokeWidth={2.1} />
                                    </span>
                                    <span className="truncate">{module.label}</span>
                                </Link>
                            );
                        }

                        return (
                            <button
                                key={module.key}
                                type="button"
                                onClick={() => {
                                    if (module.simple) return;
                                    onToggleKey(module.key);
                                }}
                                aria-disabled={module.simple}
                                className={classNames(
                                    'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-2xl px-1 py-1 text-[10px] font-semibold leading-tight',
                                    isOpen || module.active
                                        ? 'bg-[var(--brand-yellow-light)] text-[var(--color-black)]'
                                        : 'text-[var(--color-black)]/90',
                                    module.simple ? 'opacity-60' : '',
                                )}
                            >
                                <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--app-surface)]/35">
                                    <Icon className="h-[18px] w-[18px] text-[#0F6930]" strokeWidth={2.1} />
                                </span>
                                <span className="truncate">{module.label}</span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </>
    );
}
export default function Navbar({
    user,
    isAdmin,
    permissions,
    showAdminSidebar,
    isDark,
    onToggleTheme,
    onToggleMobileMenu,
}) {
    const modules = useMemo(() => buildModuleNav({ isAdmin, permissions }), [isAdmin, permissions]);
    const primaryNavItems = useMemo(() => basePrimaryNavItems(), []);
    const [mobileModuleOpenKey, setMobileModuleOpenKey] = useState(null);
    const headerRef = useRef(null);

    useEffect(() => {
        setMobileModuleOpenKey(null);
    }, [showAdminSidebar]);

    useEffect(() => {
        const handleResize = () => {
            if (window.innerWidth >= 768) {
                setMobileModuleOpenKey(null);
            }
        };

        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    useEffect(() => {
        const applyNavbarHeight = () => {
            const height = headerRef.current?.offsetHeight ?? 0;
            document.documentElement.style.setProperty('--app-navbar-height', `${height}px`);
        };

        applyNavbarHeight();

        let observer;
        if (typeof ResizeObserver !== 'undefined' && headerRef.current) {
            observer = new ResizeObserver(() => applyNavbarHeight());
            observer.observe(headerRef.current);
        }

        window.addEventListener('resize', applyNavbarHeight);

        return () => {
            window.removeEventListener('resize', applyNavbarHeight);
            observer?.disconnect?.();
        };
    }, []);

    const toggleMobileModule = (key) => {
        setMobileModuleOpenKey((current) => (current === key ? null : key));
    };

    return (
        <>
            <header ref={headerRef} className="sticky top-0 z-30 bg-[var(--app-bg)]/95 px-2 pt-2 backdrop-blur sm:px-3">
                <div className="rounded-[22px] border border-[var(--brand-yellow-dark)] bg-[var(--app-surface)] shadow-sm shadow-black/5">
                    <div className="flex h-14 items-center justify-between gap-2 px-3 sm:px-4 md:h-[58px]">
                        <div className="flex min-w-0 items-center gap-2 sm:gap-3">
                            {showAdminSidebar ? (
                                <button
                                    type="button"
                                    onClick={onToggleMobileMenu}
                                    className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] text-[var(--app-text)] lg:hidden"
                                    aria-label="Ouvrir le menu administration"
                                >
                                    <Menu className="h-5 w-5" strokeWidth={2} />
                                </button>
                            ) : null}

                            <Link href={safeHref('dashboard') || '#'} className="inline-flex items-center">
                                <img
                                    src="/Logo%20Jeudy.png"
                                    alt="Logo Jeudy"
                                    className="h-9 w-auto max-w-[92px] object-contain md:h-8 md:max-w-[84px]"
                                />
                            </Link>

                            {safeHasRoute('dashboard') ? (
                                <Link
                                    href={safeHref('dashboard')}
                                    className={classNames(
                                        'hidden h-10 items-center gap-2 rounded-2xl px-3 text-sm font-semibold transition md:inline-flex',
                                        safeCurrent('dashboard')
                                            ? 'bg-[var(--app-surface-soft)] text-[var(--app-text)] shadow-sm'
                                            : 'bg-[var(--app-surface)] text-[var(--app-text)] hover:border-[var(--brand-brown)]',
                                    )}
                                >
                                    <Home className="h-4 w-4 text-[#0F6930]" strokeWidth={2.1} />
                                    <span>Accueil</span>
                                </Link>
                            ) : null}
                        </div>

                        <div className="hidden min-w-0 flex-1 items-center px-1 md:flex">
                            <nav className="flex min-w-0 items-center gap-1 overflow-visible">
                                {modules.map((module) => (
                                    <DesktopModuleDropdown key={module.key} module={module} />
                                ))}
                            </nav>
                        </div>

                        <div className="flex items-center gap-1.5 sm:gap-2">
                            <GlobalSearchVisual />
                            <NotificationsMenu />
                            <UserMenu
                                user={user}
                                isAdmin={isAdmin}
                                permissions={permissions}
                                primaryNavItems={primaryNavItems}
                            />
                        </div>
                    </div>
                </div>
            </header>

            <MobileBottomModules
                modules={modules}
                openKey={mobileModuleOpenKey}
                onToggleKey={toggleMobileModule}
                onClose={() => setMobileModuleOpenKey(null)}
            />
        </>
    );
}
