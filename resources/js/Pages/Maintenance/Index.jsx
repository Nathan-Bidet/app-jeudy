import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

/**
 * Écran provisoire : les fondations backend du module sont en place, la
 * véritable interface (liste, formulaire, pointage) arrive en Phase 3.
 */
export default function MaintenanceIndex({ groups = [], meta = {}, permissions = {} }) {
    const pageHeader = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h1 className="text-[22px] leading-none">
                <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">
                    Maintenance / Entretien
                </span>
            </h1>
        </div>
    );

    return (
        <AppLayout title="Maintenance / Entretien" header={pageHeader}>
            <Head title="Maintenance / Entretien" />

            <div className="w-full max-w-full space-y-4 px-0 pb-20 pt-2 sm:pt-3 lg:mx-auto lg:max-w-[1460px] lg:pb-8">
                <section className="rounded-2xl border-2 border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-sm">
                    <p className="text-sm font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        Module en cours de construction
                    </p>
                    <p className="mt-2 text-sm text-[var(--app-text)]">
                        Les fondations backend sont opérationnelles. L’interface complète (liste, création,
                        demande et pointage) sera mise en place à l’étape suivante.
                    </p>

                    <dl className="mt-5 grid gap-3 sm:grid-cols-3">
                        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            <dt className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Groupes
                            </dt>
                            <dd className="mt-1 text-lg font-extrabold">{meta?.count_groups ?? groups.length}</dd>
                        </div>
                        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            <dt className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Tâches
                            </dt>
                            <dd className="mt-1 text-lg font-extrabold">{meta?.count_tasks ?? 0}</dd>
                        </div>
                        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            <dt className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Commentaires masqués
                            </dt>
                            <dd className="mt-1 text-lg font-extrabold">
                                {permissions?.can_view_hidden_comments ? 'Visibles' : 'Masqués'}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
        </AppLayout>
    );
}
