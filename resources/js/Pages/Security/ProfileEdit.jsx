import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from '@/Pages/Security/DeleteUserForm';
import UpdatePasswordForm from '@/Pages/Security/UpdatePasswordForm';
import UpdateProfileInformationForm from '@/Pages/Security/UpdateProfileInformationForm';
import ResetTotpForm from '@/Pages/Security/ResetTotpForm';

export default function ProfileEdit({ mustVerifyEmail, status }) {
    return (
        <AppLayout title="Profil">
            <Head title="Profil" />

            <div className="space-y-6">
                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow sm:p-8">
                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                        className="max-w-xl"
                    />
                </div>

                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow sm:p-8">
                    <UpdatePasswordForm className="max-w-xl" />
                </div>

                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow sm:p-8">
                    <ResetTotpForm className="max-w-xl" />
                </div>

                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow sm:p-8">
                    <DeleteUserForm className="max-w-xl" />
                </div>
            </div>
        </AppLayout>
    );
}
