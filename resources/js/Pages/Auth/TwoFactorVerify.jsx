import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function TwoFactorVerify({ status, lockedUntil, hasRecoveryCodes }) {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('two-factor.verify.store'), {
            onFinish: () => reset('code', 'recovery_code'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Verify 2FA" />

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">{status}</div>
            )}

            <h1 className="mb-3 text-lg font-semibold text-gray-900">Vérification 2FA</h1>
            <p className="mb-4 text-sm text-gray-700">
                Saisissez un code de votre application d&apos;authentification pour terminer la connexion.
            </p>

            {lockedUntil && (
                <p className="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                    Trop de tentatives. Réessayez après {new Date(lockedUntil).toLocaleString()}.
                </p>
            )}

            <form onSubmit={submit}>
                {!useRecoveryCode && (
                    <div>
                        <InputLabel htmlFor="code" value="Code TOTP (6 chiffres)" />
                        <TextInput
                            id="code"
                            name="code"
                            value={data.code}
                            className="mt-1 block w-full"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            onChange={(event) => setData('code', event.target.value)}
                        />
                        <InputError message={errors.code} className="mt-2" />
                    </div>
                )}

                {useRecoveryCode && (
                    <div>
                        <InputLabel htmlFor="recovery_code" value="Code de secours" />
                        <TextInput
                            id="recovery_code"
                            name="recovery_code"
                            value={data.recovery_code}
                            className="mt-1 block w-full"
                            onChange={(event) => setData('recovery_code', event.target.value)}
                        />
                        <InputError message={errors.recovery_code} className="mt-2" />
                    </div>
                )}

                {hasRecoveryCodes && (
                    <button
                        type="button"
                        className="mt-4 text-sm text-gray-600 underline"
                        onClick={() => setUseRecoveryCode((current) => !current)}
                    >
                        {useRecoveryCode ? 'Utiliser un code TOTP' : 'Utiliser un code de secours'}
                    </button>
                )}

                <div className="mt-6 flex items-center justify-between">
                    <Link href={route('logout')} method="post" as="button" className="text-sm text-gray-600 underline">
                        Se déconnecter
                    </Link>

                    <PrimaryButton disabled={processing}>Vérifier</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
