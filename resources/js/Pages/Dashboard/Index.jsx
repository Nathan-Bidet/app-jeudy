import WidgetCard from '@/Components/Dashboard/WidgetCard';
import PollDisplay from '@/Components/Announcements/PollDisplay';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';

export default function DashboardIndex({ dashboard }) {
    const widgets = dashboard?.widgets ?? [];
    const announcement = dashboard?.dashboard_announcement ?? null;

    return (
        <AppLayout title="Accueil">
            <Head title="Accueil" />

            <div className="w-full min-w-0 max-w-full space-y-6">
                {announcement ? (
                    <section className="rounded-2xl border border-[#F1BF0C] bg-[#FBEBC0] p-4 shadow-sm">
                        <div className="mb-2 flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            <Megaphone className="h-3.5 w-3.5" strokeWidth={2.3} />
                            Annonce
                        </div>
                        {announcement.title ? (
                            <p className="text-base font-bold leading-snug text-[var(--app-text)]">{announcement.title}</p>
                        ) : null}
                        <p className={`whitespace-pre-line text-sm leading-relaxed text-[var(--app-text)]${announcement.title ? ' mt-1' : ''}`}>
                            {announcement.body_text}
                        </p>
                        {announcement.poll ? (
                            <div className="mt-3">
                                <PollDisplay poll={announcement.poll} variant="basic" />
                            </div>
                        ) : null}
                        {announcement.created_by ? (
                            <p className="mt-3 text-xs text-[var(--app-muted)]">
                                Publié par {announcement.created_by}
                            </p>
                        ) : null}
                    </section>
                ) : null}

                <section className="grid w-full min-w-0 max-w-full grid-cols-1 gap-4">
                    {widgets.map((widget) => (
                        <div key={widget.key} className="w-full min-w-0 max-w-full overflow-hidden box-border">
                            <WidgetCard widget={widget} />
                        </div>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
