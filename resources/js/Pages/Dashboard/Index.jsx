import WidgetCard from '@/Components/Dashboard/WidgetCard';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

export default function DashboardIndex({ dashboard, viewer }) {
    const widgets = dashboard?.widgets ?? [];

    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />

            <div className="w-full min-w-0 max-w-full space-y-6">
                <section className="grid w-full min-w-0 max-w-full grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
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
