import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Fuel } from 'lucide-react';

export default function TaskFuelIndex() {
    return (
        <AppLayout title="Carburant">
            <Head title="Carburant" />

            <div className="space-y-6">
                <header>
                    <h1 className="text-[22px] font-black uppercase tracking-[0.06em] text-[var(--app-ink)]">
                        Carburant
                    </h1>
                </header>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-sm">
                    <div className="flex items-start gap-3 rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] p-5">
                        <span className="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]">
                            <Fuel className="h-5 w-5" strokeWidth={2.2} />
                        </span>
                        <div>
                            <p className="text-sm font-semibold text-[var(--app-ink)]">Module en préparation</p>
                            <p className="mt-1 text-sm text-[var(--app-muted)]">
                                La page est prête pour recevoir la future logique métier carburant.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
