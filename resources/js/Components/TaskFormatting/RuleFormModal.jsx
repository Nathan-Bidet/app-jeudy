import FormattedText from '@/Components/FormattedText';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { adaptiveTaskStyle } from '@/Support/taskColorStyle';

const SCOPE_LABELS = {
    task: 'Tâche',
    comment: 'Commentaire',
    both: 'Tâche + Commentaire',
};

const MATCH_TYPE_LABELS = {
    contains: 'Contient',
    starts_with: 'Commence par',
    regex: 'Regex',
};

const PREVIEW_PHONE = '06 12 34 56 78';

// Même calcul que le rendu réel des tâches (variable --app-phone-color incluse).
function previewStyle(form) {
    return adaptiveTaskStyle(form?.data || {});
}

function normalizeHex(value) {
    const text = String(value || '').trim();

    return text ? text.toUpperCase() : '';
}

function ColorField({ form, field, id, label, placeholder, pickerFallback, disabled, hint = null }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <div className="mt-1 flex items-center gap-2">
                <TextInput
                    id={id}
                    className="block w-full"
                    placeholder={placeholder}
                    value={form.data[field]}
                    onChange={(event) => form.setData(field, normalizeHex(event.target.value))}
                    disabled={disabled}
                />
                <input
                    type="color"
                    value={form.data[field] || pickerFallback}
                    onChange={(event) => form.setData(field, normalizeHex(event.target.value))}
                    disabled={disabled}
                    className="h-10 w-12 rounded border border-gray-300 bg-white p-1"
                />
                <SecondaryButton
                    type="button"
                    onClick={() => form.setData(field, '')}
                    disabled={disabled}
                >
                    Auto
                </SecondaryButton>
            </div>
            {hint ? <p className="mt-1 text-xs text-[var(--app-muted)]">{hint}</p> : null}
            <InputError className="mt-2" message={form.errors[field]} />
        </div>
    );
}

export default function RuleFormModal({
    show,
    mode = 'create',
    form,
    scopes = [],
    matchTypes = [],
    canManage,
    onClose,
    onSubmit,
}) {
    const isReadOnly = !canManage;

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={onSubmit}>
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-lg font-semibold text-[var(--app-text)]">
                        {mode === 'edit' ? 'Modifier une règle de mise en forme' : 'Ajouter une règle de mise en forme'}
                    </h3>
                </div>

                <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4">
                    <div className="grid grid-cols-1 gap-4">
                        <div>
                            <InputLabel htmlFor="formatting-name" value="Nom" />
                            <TextInput
                                id="formatting-name"
                                className="mt-1 block w-full"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                disabled={isReadOnly}
                            />
                            <InputError className="mt-2" message={form.errors.name} />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="formatting-scope" value="Scope" />
                            <select
                                id="formatting-scope"
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={form.data.scope}
                                onChange={(event) => form.setData('scope', event.target.value)}
                                disabled={isReadOnly}
                            >
                                {scopes.map((scope) => (
                                    <option key={scope} value={scope}>
                                        {SCOPE_LABELS[scope] || scope}
                                    </option>
                                ))}
                            </select>
                            <InputError className="mt-2" message={form.errors.scope} />
                        </div>

                        <div>
                            <InputLabel htmlFor="formatting-match-type" value="Type de match" />
                            <select
                                id="formatting-match-type"
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={form.data.match_type}
                                onChange={(event) => form.setData('match_type', event.target.value)}
                                disabled={isReadOnly}
                            >
                                {matchTypes.map((matchType) => (
                                    <option key={matchType} value={matchType}>
                                        {MATCH_TYPE_LABELS[matchType] || matchType}
                                    </option>
                                ))}
                            </select>
                            <InputError className="mt-2" message={form.errors.match_type} />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="formatting-pattern" value="Pattern" />
                        <TextInput
                            id="formatting-pattern"
                            className="mt-1 block w-full"
                            value={form.data.pattern}
                            onChange={(event) => form.setData('pattern', event.target.value)}
                            disabled={isReadOnly}
                        />
                        <p className="mt-1 text-xs text-[var(--app-muted)]">
                            Exemples: texte simple, préfixe, ou regex (ex: `/\\burgent\\b/i`).
                        </p>
                        <InputError className="mt-2" message={form.errors.pattern} />
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <ColorField
                            form={form}
                            field="text_color"
                            id="formatting-text-color"
                            label="Couleur texte"
                            placeholder="#111827"
                            pickerFallback="#111111"
                            disabled={isReadOnly}
                        />

                        <ColorField
                            form={form}
                            field="bg_color"
                            id="formatting-bg-color"
                            label="Couleur fond"
                            placeholder="#FEF3C7"
                            pickerFallback="#FFFFFF"
                            disabled={isReadOnly}
                        />

                        <ColorField
                            form={form}
                            field="phone_color"
                            id="formatting-phone-color"
                            label="Couleur des numéros de téléphone"
                            placeholder="#1D4ED8"
                            pickerFallback="#1D4ED8"
                            disabled={isReadOnly}
                            hint="Auto : couleur habituelle des liens."
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="formatting-description" value="Description" />
                        <textarea
                            id="formatting-description"
                            rows={3}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            disabled={isReadOnly}
                        />
                        <InputError className="mt-2" message={form.errors.description} />
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label className="inline-flex items-center gap-2 text-sm text-[var(--app-text)]">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300"
                                checked={Boolean(form.data.is_active)}
                                onChange={(event) => form.setData('is_active', event.target.checked)}
                                disabled={isReadOnly}
                            />
                            Active
                        </label>

                        <label className="inline-flex items-center gap-2 text-sm text-[var(--app-text)]">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300"
                                checked={Boolean(form.data.applies_to_a_prevoir)}
                                onChange={(event) => form.setData('applies_to_a_prevoir', event.target.checked)}
                                disabled={isReadOnly}
                            />
                            S'applique à À Prévoir
                        </label>

                        <label className="inline-flex items-center gap-2 text-sm text-[var(--app-text)]">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300"
                                checked={Boolean(form.data.applies_to_ldt)}
                                onChange={(event) => form.setData('applies_to_ldt', event.target.checked)}
                                disabled={isReadOnly}
                            />
                            S'applique à LDT
                        </label>
                    </div>
                    <InputError className="mt-1" message={form.errors.applies_to_a_prevoir || form.errors.applies_to_ldt} />

                    <div className="rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-[var(--app-muted)]">Aperçu live</p>
                        <div className="mt-2 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2" style={previewStyle(form)}>
                            <FormattedText
                                text={`Tâche démo: Livraison client - Commentaire: urgent avant 10h - Tél. ${PREVIEW_PHONE}`}
                            />
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Fermer
                    </SecondaryButton>

                    {canManage ? (
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {mode === 'edit' ? 'Enregistrer' : 'Ajouter'}
                        </PrimaryButton>
                    ) : null}
                </div>
            </form>
        </Modal>
    );
}
