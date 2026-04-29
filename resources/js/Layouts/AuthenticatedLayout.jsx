import AppLayout from '@/Layouts/AppLayout';

export default function AuthenticatedLayout({ header, children }) {
    return <AppLayout header={header}>{children}</AppLayout>;
}
