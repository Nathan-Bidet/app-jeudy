import TitleCaps from '@/Layouts/AppShell/TitleCaps';
import { Link, usePage } from '@inertiajs/react';

function SidebarItem({ href, label, active, onClick }) {
    return (
        <Link
            href={href}
            onClick={onClick}
            className={`block rounded-xl px-3 py-2 text-sm font-semibold transition ${
                active
                    ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                    : 'text-[var(--app-text)] hover:bg-[var(--brand-yellow-light)] hover:text-[var(--color-black)]'
            }`}
        >
            {label}
        </Link>
    );
}

export default function Sidebar({ mobileOpen, onClose }) {
    const { auth } = usePage().props;
    const isAdmin = Boolean(auth?.is_admin);
    const canViewUsers = Boolean(auth?.permissions?.admin_users_view);
    const canViewSectors = Boolean(auth?.permissions?.admin_sectors_view);
    const canViewEntities = Boolean(auth?.permissions?.admin_entities_view);
    const canViewLogs = Boolean(auth?.permissions?.admin_logs_view);

    const items = [
        ...(canViewUsers
            ? [{ href: route('admin.users.index'), label: 'Utilisateurs', active: route().current('admin.users.*') }]
            : []),
        ...(canViewSectors
            ? [{ href: route('admin.sectors.index'), label: 'Secteurs', active: route().current('admin.sectors.*') }]
            : []),
        ...(canViewEntities
            ? [{ href: route('admin.entities'), label: 'Entités', active: route().current('admin.entities*') }]
            : []),
        ...(canViewLogs
            ? [{ href: route('admin.logs.index'), label: 'Logs', active: route().current('admin.logs.*') }]
            : []),
        ...(isAdmin
            ? [{ href: route('admin.leaves.index'), label: 'Congés', active: route().current('admin.leaves.*') }]
            : []),
        ...(isAdmin
            ? [{ href: route('admin.aprevoir-import.index'), label: 'Import À prévoir', active: route().current('admin.aprevoir-import.*') }]
            : []),
    ];
    const closeIfMobile = () => onClose?.();

    return (
        <>
            <aside className="hidden w-[250px] shrink-0 border-r border-[var(--app-border)] bg-[var(--app-surface)] lg:block">
                <div className="p-4">
                    <div className="mb-4 rounded-xl bg-[var(--brand-yellow-light)] p-3 text-[var(--color-black)]">
                        <TitleCaps text="Administration" className="text-[12px]" />
                    </div>

                    <nav className="space-y-1.5">
                        {items.map((item) => (
                            <SidebarItem key={item.href} {...item} />
                        ))}
                    </nav>
                </div>
            </aside>

            {mobileOpen && (
                <div className="fixed inset-0 z-40 flex lg:hidden">
                    <button
                        type="button"
                        className="absolute inset-0 bg-black/45"
                        onClick={closeIfMobile}
                        aria-label="Fermer le menu"
                    />

                    <aside className="relative h-full w-[280px] max-w-[85vw] border-r border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-2xl">
                        <div className="mb-4 flex items-center justify-between">
                            <TitleCaps text="Navigation" className="text-[13px]" />
                            <button
                                type="button"
                                onClick={closeIfMobile}
                                className="rounded-md border border-[var(--app-border)] px-2 py-1 text-xs font-semibold"
                            >
                                Fermer
                            </button>
                        </div>

                        <nav className="space-y-1.5">
                            {items.map((item) => (
                                <SidebarItem key={item.href} {...item} onClick={closeIfMobile} />
                            ))}
                        </nav>
                    </aside>
                </div>
            )}
        </>
    );
}
