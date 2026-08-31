import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PlaceAutocompleteTextarea from '@/Components/Shared/PlaceAutocompleteTextarea';
import SearchableSelect from '@/Components/Shared/SearchableSelect';
import { EyeOff, Loader2, Save } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';

function FieldLabel({ children, required = false }) {
    return (
        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
            {children}
            {required ? <span className="ml-1 text-[var(--brand-yellow-dark)]">*</span> : null}
        </label>
    );
}

export default function MaintenanceTaskModal({
    show,
    onClose,
    form,
    reference = {},
    mode = 'create',
    origin = 'creation',
    commentWithheld = false,
    onSubmit,
}) {
    const taskRef = useRef(null);

    const isEditing = mode === 'edit';
    const isRequest = origin === 'request';

    const userOptions = useMemo(
        () =>
            (reference?.assignee_users || []).map((item) => ({
                value: String(item.id),
                label: item.sector_name ? `${item.name} (${item.sector_name})` : item.name,
            })),
        [reference],
    );

    const depotOptions = useMemo(
        () =>
            (reference?.depots || []).map((item) => ({
                value: String(item.id),
                label: item.name,
            })),
        [reference],
    );

    // La date de fin de période n'a de sens qu'une fois la date de début connue.
    const hasStartDate = Boolean(form?.data?.date);

    useEffect(() => {
        if (!hasStartDate && form?.data?.fin_date) {
            form.setData('fin_date', '');
        }
    }, [hasStartDate]);

    useEffect(() => {
        if (show) {
            const timeout = window.setTimeout(() => taskRef.current?.focus(), 120);
            return () => window.clearTimeout(timeout);
        }

        return undefined;
    }, [show]);

    const title = isEditing
        ? 'Modifier la tâche'
        : isRequest
            ? 'Demander une tâche'
            : 'Nouvelle tâche';

    const submitLabel = isEditing
        ? 'Enregistrer'
        : isRequest
            ? 'Envoyer la demande'
            : 'Créer la tâche';

    const handleSubmit = (event) => {
        event.preventDefault();
        onSubmit?.();
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={handleSubmit}>
                <div className="flex items-center justify-between gap-3 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">{title}</h3>
                    {isRequest && !isEditing ? (
                        <span className="rounded-lg border border-amber-300 bg-amber-50 px-2 py-1 text-[10px] font-black uppercase tracking-[0.08em] text-amber-700">
                            Demande
                        </span>
                    ) : null}
                </div>

                <div className="grid max-h-[70vh] gap-4 overflow-y-auto bg-[var(--app-surface)] px-5 py-4">
                    {isRequest && !isEditing ? (
                        <p className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-xs text-[var(--app-muted)]">
                            Vous ne disposez pas du droit de créer directement une tâche : celle-ci sera
                            enregistrée comme une demande, à votre nom.
                        </p>
                    ) : null}

                    <div className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <FieldLabel required>Date</FieldLabel>
                            <input
                                type="date"
                                value={form.data.date || ''}
                                onChange={(event) => form.setData('date', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                required
                            />
                            <InputError className="mt-1" message={form.errors.date} />
                        </div>

                        <div>
                            <FieldLabel>Au (fin de période)</FieldLabel>
                            <input
                                type="date"
                                value={form.data.fin_date || ''}
                                min={form.data.date || undefined}
                                disabled={!hasStartDate}
                                onChange={(event) => form.setData('fin_date', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                title={hasStartDate ? undefined : 'Renseignez d’abord la date'}
                            />
                            <InputError className="mt-1" message={form.errors.fin_date} />
                        </div>

                        <div>
                            <FieldLabel>Date de fin souhaitée</FieldLabel>
                            <input
                                type="date"
                                value={form.data.due_date || ''}
                                onChange={(event) => form.setData('due_date', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            />
                            <InputError className="mt-1" message={form.errors.due_date} />
                        </div>
                    </div>

                    <div>
                        <FieldLabel>Personne affectée</FieldLabel>
                        <div className="mt-1">
                            <SearchableSelect
                                options={userOptions}
                                value={form.data.assignee_user_id ? String(form.data.assignee_user_id) : ''}
                                onChange={(next) => {
                                    form.setData('assignee_user_id', next || '');
                                    form.setData('assignee_label_free', '');
                                }}
                                onFreeSelect={(label) => {
                                    form.setData('assignee_user_id', '');
                                    form.setData('assignee_label_free', label);
                                }}
                                allowFree
                                freeLabel={form.data.assignee_label_free || ''}
                                placeholder="Utilisateur, nom libre, ou personne"
                                emptyLabel="Aucune personne affectée"
                            />
                        </div>
                        {form.data.assignee_label_free ? (
                            <p className="mt-1 text-xs text-[var(--app-muted)]">
                                Personne saisie librement : {form.data.assignee_label_free}
                            </p>
                        ) : null}
                        <InputError
                            className="mt-1"
                            message={form.errors.assignee_user_id || form.errors.assignee_label_free}
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <FieldLabel>Dépôt</FieldLabel>
                            <div className="mt-1">
                                <SearchableSelect
                                    options={depotOptions}
                                    value={form.data.depot_id ? String(form.data.depot_id) : ''}
                                    onChange={(next) => form.setData('depot_id', next || '')}
                                    placeholder="Sélectionner un dépôt"
                                    emptyLabel="Aucun dépôt"
                                />
                            </div>
                            <InputError className="mt-1" message={form.errors.depot_id} />
                        </div>

                        <PlaceAutocompleteTextarea
                            label="Adresse / lieu libre"
                            value={form.data.address_free || ''}
                            onChange={(next) => form.setData('address_free', next)}
                            error={form.errors.address_free}
                            placeholder="Adresse, atelier, bâtiment…"
                            suggestions={reference?.place_suggestions || []}
                            defaultSuggestions={reference?.depot_name_suggestions || []}
                        />
                    </div>

                    <div>
                        <FieldLabel required>Tâche</FieldLabel>
                        <textarea
                            ref={taskRef}
                            value={form.data.task || ''}
                            onChange={(event) => form.setData('task', event.target.value)}
                            rows={4}
                            placeholder="Décrivez le travail à effectuer…"
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            required
                        />
                        <InputError className="mt-1" message={form.errors.task} />
                    </div>

                    <div>
                        <FieldLabel>Commentaire</FieldLabel>
                        {commentWithheld ? (
                            <p className="mt-1 rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] p-2 text-xs text-[var(--app-muted)]">
                                Le commentaire de cette tâche est masqué et ne vous est pas accessible.
                                Il sera conservé tel quel : le laisser vide ne l’effacera pas.
                            </p>
                        ) : null}
                        <textarea
                            value={form.data.comment || ''}
                            onChange={(event) => form.setData('comment', event.target.value)}
                            rows={3}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                        <InputError className="mt-1" message={form.errors.comment} />

                        <label
                            className={`mt-2 inline-flex items-center gap-2 text-sm ${
                                commentWithheld ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
                            }`}
                        >
                            <input
                                type="checkbox"
                                checked={Boolean(form.data.comment_hidden)}
                                onChange={(event) => form.setData('comment_hidden', event.target.checked)}
                                disabled={commentWithheld}
                                className="h-4 w-4 rounded border-[var(--app-border)]"
                            />
                            <EyeOff className="h-3.5 w-3.5 text-[var(--app-muted)]" strokeWidth={2.2} />
                            <span className="font-semibold">Masquer le commentaire</span>
                        </label>
                        {form.data.comment_hidden ? (
                            <p className="mt-1 text-xs text-[var(--app-muted)]">
                                Seuls les utilisateurs autorisés à afficher les commentaires masqués en verront
                                le contenu.
                            </p>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-col-reverse gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={form.processing}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] disabled:opacity-60"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border-2 border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-60"
                    >
                        {form.processing ? (
                            <Loader2 className="h-3.5 w-3.5 animate-spin" strokeWidth={2.4} />
                        ) : (
                            <Save className="h-3.5 w-3.5" strokeWidth={2.4} />
                        )}
                        <span>{form.processing ? 'Enregistrement…' : submitLabel}</span>
                    </button>
                </div>
            </form>
        </Modal>
    );
}
