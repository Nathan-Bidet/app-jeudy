import Navbar from '@/Layouts/AppShell/Navbar';
import Sidebar from '@/Layouts/AppShell/Sidebar';
import ToastHost from '@/Layouts/AppShell/ToastHost';
import TitleCaps from '@/Layouts/AppShell/TitleCaps';
import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const THEME_KEY = 'app.theme';

function getInitialTheme() {
    if (typeof window === 'undefined') {
        return 'light';
    }

    const stored = localStorage.getItem(THEME_KEY);

    if (stored === 'dark' || stored === 'light') {
        return stored;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export default function AppLayout({ title, header, children }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const isAdmin = Boolean(auth?.is_admin);
    const permissions = auth?.permissions ?? {};
    const isAdminSection = route().current('admin.*');
    const isTaskDataSection = route().current('task.data.*');
    const isCalendarSection = route().current('calendar.*');
    const isWidePlanningSection = route().current('a_prevoir.*')
        || route().current('ldt.*')
        || route().current('task.archive.*');

    const [mobileOpen, setMobileOpen] = useState(false);
    const [theme, setTheme] = useState(getInitialTheme);

    const isDark = theme === 'dark';
    const hasHeader = useMemo(() => Boolean(header || title), [header, title]);

    useEffect(() => {
        if (typeof document === 'undefined') {
            return;
        }

        const root = document.documentElement;
        root.classList.toggle('theme-dark', isDark);
        localStorage.setItem(THEME_KEY, theme);
    }, [isDark, theme]);

    useEffect(() => {
        setMobileOpen(false);
    }, [title]);

    return (
        <div className="app-shell min-h-screen bg-[var(--app-bg)] text-[var(--app-text)]">
            <Navbar
                user={user}
                isAdmin={isAdmin}
                permissions={permissions}
                showAdminSidebar={isAdminSection}
                isDark={isDark}
                onToggleTheme={() => setTheme((prev) => (prev === 'dark' ? 'light' : 'dark'))}
                onToggleMobileMenu={() => setMobileOpen((prev) => !prev)}
            />

            <div className="flex min-h-[calc(100vh-4rem)]">
                {isAdminSection ? (
                    <Sidebar mobileOpen={mobileOpen} onClose={() => setMobileOpen(false)} />
                ) : null}

                <main className="flex-1 pb-24 md:pb-0">
                    <div
                        className={`mx-auto w-full ${
                            isTaskDataSection
                                ? 'max-w-none px-0 py-3 sm:px-1 sm:py-5 lg:px-2'
                                : isCalendarSection
                                ? 'max-w-[1320px] px-0 py-3 sm:px-6 sm:py-6'
                                : isWidePlanningSection
                                ? 'max-w-none px-1 py-3 sm:px-2 sm:py-5 lg:px-4'
                                : 'max-w-[1320px] p-4 sm:p-6'
                        }`}
                    >
                        {hasHeader && (
                            <header className={`app-header-panel rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm ${
                                isCalendarSection ? 'mb-3 px-4 py-2.5' : 'mb-6 px-5 py-4'
                            }`}>
                                {header ? (
                                    header
                                ) : (
                                    <h1 className="text-[22px] leading-none">
                                        <TitleCaps text={title} />
                                    </h1>
                                )}
                            </header>
                        )}

                        {children}
                    </div>
                </main>
            </div>

            <ToastHost />
        </div>
    );
}
