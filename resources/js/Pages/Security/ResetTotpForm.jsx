import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm } from '@inertiajs/react';

export default function ResetTotpForm({ className = '', isTotpEnabled = false }) {
    const resetForm = useForm({
        code: '',
    });
    const disableForm = useForm({
        code: '',
    });

    const submitReset = (event) => {
        event.preventDefault();

        resetForm.post(route('two-factor.reset'), {
            preserveScroll: true,
            onSuccess: () => resetForm.reset(),
        });
    };

    const submitDisable = (event) => {
        event.preventDefault();

        if (!window.confirm('Confirmez-vous la désactivation de la double authentification ?')) {
            return;
        }

        disableForm.delete(route('two-factor.destroy'), {
            preserveScroll: true,
            onSuccess: () => disableForm.reset(),
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">Authentification TOTP</h2>

                <p className="mt-1 text-sm text-gray-600">
                    {isTotpEnabled
                        ? 'Réinitialisez votre TOTP ou désactivez la double authentification.'
                        : 'La double authentification est actuellement désactivée pour votre compte.'}
                </p>
            </header>

            {!isTotpEnabled && (
                <div className="mt-6">
                    <Link href={route('two-factor.setup')}>
                        <PrimaryButton>Configurer la double authentification</PrimaryButton>
                    </Link>
                </div>
            )}

            {isTotpEnabled && (
                <>
                    <form onSubmit={submitReset} className="mt-6 space-y-6">
                        <div>
                            <InputLabel htmlFor="reset_code" value="Code TOTP actuel" />

                            <TextInput
                                id="reset_code"
                                type="text"
                                name="code"
                                className="mt-1 block w-full sm:w-80"
                                value={resetForm.data.code}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                onChange={(event) => resetForm.setData('code', event.target.value)}
                            />

                            <InputError className="mt-2" message={resetForm.errors.code} />
                        </div>

                        <div className="flex items-center gap-4">
                            <PrimaryButton disabled={resetForm.processing}>Réinitialiser et afficher le QR code</PrimaryButton>

                            <Transition
                                show={resetForm.recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-gray-600">Redirection vers l&apos;enrôlement en cours...</p>
                            </Transition>
                        </div>
                    </form>

                    <form onSubmit={submitDisable} className="mt-8 space-y-4 rounded-lg border border-red-200 bg-red-50 p-4">
                        <p className="text-sm text-red-700">
                            Désactivation de la double authentification: confirmation requise avec votre code TOTP actuel.
                        </p>
                        <div>
                            <InputLabel htmlFor="disable_code" value="Code TOTP actuel" />
                            <TextInput
                                id="disable_code"
                                type="text"
                                name="disable_code"
                                className="mt-1 block w-full sm:w-80"
                                value={disableForm.data.code}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                onChange={(event) => disableForm.setData('code', event.target.value)}
                            />
                            <InputError className="mt-2" message={disableForm.errors.code} />
                        </div>
                        <div className="flex items-center gap-3">
                            <DangerButton disabled={disableForm.processing}>
                                Désactiver la double authentification
                            </DangerButton>
                            <SecondaryButton
                                type="button"
                                disabled={disableForm.processing}
                                onClick={() => disableForm.reset()}
                            >
                                Annuler
                            </SecondaryButton>
                        </div>
                    </form>
                </>
            )}
        </section>
    );
}
