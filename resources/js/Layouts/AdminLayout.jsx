import AppLayout from '@/Layouts/AppLayout';

export default function AdminLayout({ title, children }) {
    return <AppLayout title={title}>{children}</AppLayout>;
}
