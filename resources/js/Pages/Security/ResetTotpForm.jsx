import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';

export default function ResetTotpForm({ className = '' }) {
    const { data, setData, post, processing, errors, reset, recentlySuccessful } = useForm({
        code: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('two-factor.reset'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">Authentification TOTP</h2>

                <p className="mt-1 text-sm text-gray-600">
                    Réinitialisez votre TOTP: validez un code actuel puis scannez un nouveau QR code.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="code" value="Code TOTP actuel" />

                    <TextInput
                        id="code"
                        type="text"
                        name="code"
                        className="mt-1 block w-full sm:w-80"
                        value={data.code}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        onChange={(event) => setData('code', event.target.value)}
                    />

                    <InputError className="mt-2" message={errors.code} />
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Réinitialiser et afficher le QR code</PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">Redirection vers l&apos;enrôlement en cours...</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
