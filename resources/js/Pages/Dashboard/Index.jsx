import WidgetCard from '@/Components/Dashboard/WidgetCard';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

export default function DashboardIndex({ dashboard, viewer }) {
    const widgets = dashboard?.widgets ?? [];

    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />

            <div className="space-y-6">
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {widgets.map((widget) => (
                        <WidgetCard key={widget.key} widget={widget} />
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
