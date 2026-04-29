import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

export default function TwoFactorSetup({ secret, otpauthUri, status, lockedUntil }) {
    const [qrCodeDataUrl, setQrCodeDataUrl] = useState('');
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        generate_recovery_codes: true,
    });

    useEffect(() => {
        let active = true;

        QRCode.toDataURL(otpauthUri, { width: 220, margin: 1 })
            .then((url) => {
                if (active) {
                    setQrCodeDataUrl(url);
                }
            })
            .catch(() => {
                if (active) {
                    setQrCodeDataUrl('');
                }
            });

        return () => {
            active = false;
        };
    }, [otpauthUri]);

    const submit = (event) => {
        event.preventDefault();
        post(route('two-factor.setup.store'));
    };

    return (
        <GuestLayout>
            <Head title="Setup 2FA" />

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">{status}</div>
            )}

            <h1 className="mb-3 text-lg font-semibold text-gray-900">Activez votre authentification 2FA</h1>
            <p className="mb-4 text-sm text-gray-700">
                Scannez le QR code dans votre application d&apos;authentification puis saisissez le code à 6 chiffres.
            </p>

            {lockedUntil && (
                <p className="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                    Vérification temporairement verrouillée jusqu&apos;au {new Date(lockedUntil).toLocaleString()}.
                </p>
            )}

            <div className="mb-4 rounded-md border border-gray-200 p-4">
                {qrCodeDataUrl ? (
                    <img src={qrCodeDataUrl} alt="QR code 2FA" className="mx-auto h-56 w-56" />
                ) : (
                    <p className="text-sm text-gray-600">Impossible de générer le QR code. Utilisez la clé manuelle.</p>
                )}

                <p className="mt-3 text-xs text-gray-600">Clé manuelle</p>
                <p className="mt-1 break-all font-mono text-sm text-gray-900">{secret}</p>
            </div>

            <form onSubmit={submit}>
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

                <div className="mt-4 block">
                    <label className="flex items-center">
                        <Checkbox
                            name="generate_recovery_codes"
                            checked={data.generate_recovery_codes}
                            onChange={(event) => setData('generate_recovery_codes', event.target.checked)}
                        />
                        <span className="ms-2 text-sm text-gray-700">Générer des codes de secours</span>
                    </label>
                </div>

                <div className="mt-6 flex justify-end">
                    <PrimaryButton disabled={processing}>Activer le 2FA</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
