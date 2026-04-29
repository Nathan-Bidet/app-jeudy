import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function TwoFactorRecoveryCodes({ codes, status }) {
    const disableForm = useForm({
        code: '',
    });

    const regenerateCodes = () => {
        disableForm.post(route('two-factor.recovery-codes.regenerate'), {
            preserveScroll: true,
        });
    };

    const disableTwoFactor = (event) => {
        event.preventDefault();

        disableForm.delete(route('two-factor.destroy'), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Codes de secours 2FA
                </h2>
            }
        >
            <Head title="2FA Recovery Codes" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {status && <div className="rounded-md bg-green-50 p-4 text-sm text-green-700">{status}</div>}

                    <section className="overflow-hidden rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="text-lg font-semibold text-gray-900">Vos codes de secours</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Conservez ces codes hors ligne. Chaque code est utilisable une seule fois.
                        </p>

                        {codes.length > 0 ? (
                            <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {codes.map((code) => (
                                    <div key={code} className="rounded border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm">
                                        {code}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="mt-4 text-sm text-gray-600">Aucun code de secours disponible.</p>
                        )}

                        <div className="mt-6">
                            <PrimaryButton type="button" onClick={regenerateCodes}>
                                Régénérer les codes
                            </PrimaryButton>
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="text-lg font-semibold text-gray-900">Désactiver le 2FA</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            La désactivation est auditée. L&apos;enrôlement 2FA sera immédiatement requis à nouveau.
                        </p>

                        <form onSubmit={disableTwoFactor} className="mt-4">
                            <div>
                                <InputLabel htmlFor="code" value="Code TOTP actuel" />
                                <TextInput
                                    id="code"
                                    name="code"
                                    className="mt-1 block w-full sm:w-72"
                                    value={disableForm.data.code}
                                    inputMode="numeric"
                                    onChange={(event) => disableForm.setData('code', event.target.value)}
                                />
                                <InputError message={disableForm.errors.code} className="mt-2" />
                            </div>

                            <div className="mt-4">
                                <PrimaryButton disabled={disableForm.processing}>Désactiver le 2FA</PrimaryButton>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
