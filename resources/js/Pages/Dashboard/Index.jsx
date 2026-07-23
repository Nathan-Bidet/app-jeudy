import WidgetCard from '@/Components/Dashboard/WidgetCard';
import CollapsibleAnnouncementBody from '@/Components/Announcements/CollapsibleAnnouncementBody';
import PollDisplay from '@/Components/Announcements/PollDisplay';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { useState } from 'react';

export default function DashboardIndex({ dashboard }) {
    const { errors = {} } = usePage().props;
    const widgets = dashboard?.widgets ?? [];
    const announcement = dashboard?.dashboard_announcement ?? null;
    const [pollResponseProcessing, setPollResponseProcessing] = useState(false);
    // Valeur dérivée directement au rendu à partir de la même source de
    // vérité que le repli de l'annonce (has_been_viewed, fournie par le
    // serveur) : pas d'état local à synchroniser, pas d'effet, donc pas de
    // boucle de rendu possible entre ce composant et CollapsibleAnnouncementBody.
    const accessQuickAbove = Boolean(announcement?.has_been_viewed);

    const submitPollResponse = (payload) => {
        if (!announcement?.id) return;
        setPollResponseProcessing(true);
        router.post(route('annonces.poll-response', announcement.id), payload, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setPollResponseProcessing(false),
        });
    };

    const announcementBlock = announcement ? (
        <section className="rounded-2xl border border-[#F1BF0C] bg-[#FBEBC0] p-4 shadow-sm">
            <div className="mb-2 flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                <Megaphone className="h-3.5 w-3.5" strokeWidth={2.3} />
                Annonce
            </div>
            {announcement.title ? (
                <p className="text-base font-bold leading-snug text-[var(--app-text)]">{announcement.title}</p>
            ) : null}
            <CollapsibleAnnouncementBody
                html={announcement.body_html}
                announcementId={announcement.id}
                hasBeenViewed={announcement.has_been_viewed}
                className={announcement.title ? 'mt-1' : ''}
            >
                {announcement.poll ? (
                    <div className="mt-3">
                        <PollDisplay
                            poll={announcement.poll}
                            variant="full"
                            onSubmitResponse={submitPollResponse}
                            responseProcessing={pollResponseProcessing}
                            errors={errors}
                        />
                    </div>
                ) : null}
            </CollapsibleAnnouncementBody>
            {announcement.created_by ? (
                <p className="mt-3 text-xs text-[var(--app-muted)]">
                    Publié par {announcement.created_by}
                </p>
            ) : null}
        </section>
    ) : null;

    const widgetsBlock = (
        <section className="grid w-full min-w-0 max-w-full grid-cols-1 gap-4">
            {widgets.map((widget) => (
                <div key={widget.key} className="w-full min-w-0 max-w-full overflow-hidden box-border">
                    <WidgetCard widget={widget} />
                </div>
            ))}
        </section>
    );

    return (
        <AppLayout title="Accueil">
            <Head title="Accueil" />

            <div className="w-full min-w-0 max-w-full space-y-6">
                {announcement && accessQuickAbove ? (
                    <>
                        {widgetsBlock}
                        {announcementBlock}
                    </>
                ) : (
                    <>
                        {announcementBlock}
                        {widgetsBlock}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
