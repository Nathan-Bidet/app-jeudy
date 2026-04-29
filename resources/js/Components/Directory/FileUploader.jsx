import InputError from '@/Components/InputError';
import { useForm } from '@inertiajs/react';

export default function FileUploader({
    uploadUrl,
    canUpload,
    allowCustomName = false,
    customNameLabel = 'Nom du document (optionnel)',
}) {
    const form = useForm({
        label: '',
        file: null,
    });

    if (!canUpload) {
        return (
            <div className="rounded-2xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4 text-sm text-[var(--app-muted)]">
                Ajout de fichiers réservé aux administrateurs.
            </div>
        );
    }

    const submit = (e) => {
        e.preventDefault();

        if (!form.data.file) {
            return;
        }

        form.post(uploadUrl, {
            forceFormData: true,
            onSuccess: () => form.reset('file', 'label'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm"
        >
            <div>
                <p className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                    Pièce jointe
                </p>
                <p className="mt-1 text-xs text-[var(--app-muted)]">
                    PDF, images, Word, Excel, CSV - 20 Mo max.
                </p>
            </div>

            {allowCustomName ? (
                <div className="space-y-1">
                    <label className="block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        {customNameLabel}
                    </label>
                    <input
                        type="text"
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        placeholder="Ex: Carte grise, Contrôle technique..."
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-muted)]"
                    />
                    <InputError message={form.errors.label} className="mt-1" />
                </div>
            ) : null}

            <label className="block cursor-pointer rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-4 text-sm text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]">
                <span className="font-semibold">
                    {form.data.file ? form.data.file.name : 'Choisir un fichier'}
                </span>
                <input
                    type="file"
                    className="hidden"
                    onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                />
            </label>

            {form.progress ? (
                <div className="space-y-1">
                    <div className="h-1.5 rounded-full bg-[var(--app-surface-soft)]">
                        <div
                            className="h-1.5 rounded-full bg-green-500 transition-all"
                            style={{ width: `${form.progress.percentage}%` }}
                        />
                    </div>
                    <p className="text-[11px] text-[var(--app-muted)]">
                        Upload {Math.round(form.progress.percentage)}%
                    </p>
                </div>
            ) : null}

            <InputError message={form.errors.file} className="mt-1" />

            <button
                type="submit"
                disabled={form.processing || !form.data.file}
                className="inline-flex items-center rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {form.processing ? 'Envoi...' : 'Ajouter'}
            </button>
        </form>
    );
}
