import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';

export default function Dashboard({ isAdmin = false }) {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />

            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-sm">
                <p className="text-sm text-[var(--app-muted)]">
                    Vous etes connecte. Utilisez le menu lateral pour naviguer.
                </p>

                {isAdmin && (
                    <div className="mt-4 flex flex-wrap gap-3 text-sm">
                        <Link
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-3 py-2 font-semibold text-[var(--color-black)]"
                            href={route('admin.users.index')}
                        >
                            Administration utilisateurs
                        </Link>
                        <Link
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-3 py-2 font-semibold text-[var(--color-black)]"
                            href={route('admin.sectors.index')}
                        >
                            Administration secteurs
                        </Link>
                    </div>
                )}
            </section>
        </AppLayout>
    );
}
