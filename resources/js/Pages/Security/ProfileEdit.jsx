import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import PushNotificationSettings from '@/Components/PushNotificationSettings';
import ResetTotpForm from '@/Pages/Security/ResetTotpForm';
import UpdateProfileInformationForm from '@/Pages/Security/UpdateProfileInformationForm';

export default function ProfileEdit({ mustVerifyEmail, status, isTotpEnabled }) {
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
                    <ResetTotpForm className="max-w-xl" isTotpEnabled={isTotpEnabled} />
                </div>

                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow sm:p-8">
                    <PushNotificationSettings className="max-w-xl" />
                </div>
            </div>
        </AppLayout>
    );
}
