import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

function initials(user) {
    const first = (user?.first_name || user?.name || '').trim().charAt(0);
    const last = (user?.last_name || '').trim().charAt(0);
    return `${first}${last}`.toUpperCase() || 'U';
}

export default function UserMenu({ user, isAdmin, permissions = {}, primaryNavItems = [] }) {
    const [open, setOpen] = useState(false);
    const wrapperRef = useRef(null);

    const canAccessAdministration = useMemo(
        () =>
            Boolean(
                isAdmin
                || permissions?.admin_users_view
                || permissions?.admin_sectors_view
                || permissions?.admin_entities_view
                || permissions?.admin_logs_view
            ),
        [isAdmin, permissions],
    );

    const administrationHref = useMemo(() => {
        if (permissions?.admin_users_view) return route('admin.users.index');
        if (permissions?.admin_entities_view) return route('admin.entities');
        if (permissions?.admin_sectors_view) return route('admin.sectors.index');
        if (permissions?.admin_logs_view) return route('admin.logs.index');
        if (isAdmin) return route('admin.users.index');

        return null;
    }, [isAdmin, permissions]);

    useEffect(() => {
        const handleOutsideClick = (event) => {
            if (!wrapperRef.current) {
                return;
            }

            if (!wrapperRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', handleOutsideClick);

        return () => {
            document.removeEventListener('mousedown', handleOutsideClick);
        };
    }, []);

    return (
        <div ref={wrapperRef} className="relative">
            <button
                type="button"
                onClick={() => setOpen((state) => !state)}
                className="hidden h-10 items-center gap-2 rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 pr-3 font-semibold text-[var(--app-text)] transition hover:border-[var(--brand-brown)] md:inline-flex"
            >
                {user?.photo_url ? (
                    <img
                        src={user.photo_url}
                        alt={user?.name ?? 'Profil'}
                        className="h-8 w-8 rounded-full border border-[var(--app-border)] object-cover"
                    />
                ) : (
                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[12px] font-black">
                        {initials(user)}
                    </span>
                )}
                <span className="max-w-[140px] truncate text-sm">{user?.name ?? 'Compte'}</span>
                <ChevronDown className="h-4 w-4 opacity-70" strokeWidth={2} />
            </button>

            <button
                type="button"
                onClick={() => setOpen((state) => !state)}
                aria-label="Ouvrir le menu utilisateur"
                className="inline-flex h-10 items-center gap-1 rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 pr-2 text-[var(--app-text)] transition hover:border-[var(--brand-brown)] md:hidden"
            >
                {user?.photo_url ? (
                    <img
                        src={user.photo_url}
                        alt={user?.name ?? 'Profil'}
                        className="h-7 w-7 rounded-full border border-[var(--app-border)] object-cover"
                    />
                ) : (
                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[10px] font-black text-[var(--app-text)]">
                        {initials(user)}
                    </span>
                )}
                <ChevronDown className="h-4 w-4 opacity-70" strokeWidth={2} />
            </button>

            {open && (
                <div className="absolute right-0 z-40 mt-2 w-[min(18rem,calc(100vw-1rem))] overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl">
                    <Link
                        href={route('directory.show', user?.id)}
                        onClick={() => setOpen(false)}
                        className="block border-b border-[var(--app-border)] px-4 py-3 transition hover:bg-[var(--app-surface-soft)] focus:outline-none"
                    >
                        <div className="flex items-center gap-2.5">
                            {user?.photo_url ? (
                                <img
                                    src={user.photo_url}
                                    alt={user?.name ?? 'Profil'}
                                    className="h-11 w-11 rounded-full border border-[var(--app-border)] object-cover"
                                />
                            ) : (
                                <span className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-xs font-black text-[var(--app-text)]">
                                    {initials(user)}
                                </span>
                            )}
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-[var(--app-text)]">{user?.name}</p>
                                <p className="truncate text-xs text-[var(--app-muted)]">{user?.email}</p>
                            </div>
                        </div>
                    </Link>

                    <div className="p-1.5">
                        <Link
                            href={route('profile.edit')}
                            onClick={() => setOpen(false)}
                            className="block rounded-lg px-3 py-2 text-sm text-[var(--app-text)] hover:bg-[var(--brand-yellow-light)] hover:text-[var(--color-black)]"
                        >
                            Profil
                        </Link>
                        {canAccessAdministration && administrationHref && (
                            <Link
                                href={administrationHref}
                                onClick={() => setOpen(false)}
                                className="block rounded-lg px-3 py-2 text-sm text-[var(--app-text)] hover:bg-[var(--brand-yellow-light)] hover:text-[var(--color-black)]"
                            >
                                Administration
                            </Link>
                        )}
                        <Link
                            href={route('logout')}
                            method="post"
                            as="button"
                            onClick={() => setOpen(false)}
                            className="block w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-[var(--brand-brown)] hover:bg-[var(--brand-yellow-light)] hover:text-[var(--color-black)]"
                        >
                            Deconnexion
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}
