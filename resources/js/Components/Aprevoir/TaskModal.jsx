import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PlaceAutocompleteTextarea from '@/Components/Shared/PlaceAutocompleteTextarea';
import SearchableSelect from '@/Components/Shared/SearchableSelect';
import { htmlToMarkedText, renderFormattedHtml } from '@/Support/textFormatting';
import { Bold, Copy, Save, Strikethrough } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

function detectFlags(taskText, commentText) {
    const text = `${taskText || ''} ${commentText || ''}`.toLowerCase();

    return {
        isDirect: text.includes('direct'),
        isBoursagri: text.includes('boursagri'),
    };
}

function assigneeOptions(reference) {
    const users = (reference?.assignee_users || []).map((item) => ({
        value: `user:${item.id}`,
        label: `${item.name} (Ets Jeudy)`,
    }));
    const transporters = (reference?.assignee_transporters || []).map((item) => {
        const fullName = `${item.first_name || ''} ${item.last_name || ''}`.trim();
        const company = String(item.company_name || '').trim();
        let label = `Transporteur #${item.id}`;

        if (fullName && company) {
            label = `${fullName} (${company})`;
        } else if (company) {
            label = company;
        } else if (fullName) {
            label = fullName;
        }

        return {
            value: `transporter:${item.id}`,
            label,
        };
    });
    const depots = (reference?.assignee_depots || []).map((item) => ({
        value: `depot:${item.id}`,
        label: item.name,
    }));

    return [...users, ...transporters, ...depots].sort((a, b) => a.label.localeCompare(b.label, 'fr'));
}

function formatDayMonthInput(value) {
    const digits = (value || '').replace(/\D/g, '').slice(0, 4);
    if (digits.length <= 2) return digits;
    return `${digits.slice(0, 2)}/${digits.slice(2)}`;
}

function RichTextMarkerField({
    label,
    value,
    onChange,
    error,
    placeholder,
    minHeight = '92px',
}) {
    const editorRef = useRef(null);
    const [isFocused, setIsFocused] = useState(false);

    const resizeEditor = () => {
        const editor = editorRef.current;
        if (!editor) return;

        const min = Number.parseInt(String(minHeight).replace(/[^\d]/g, ''), 10);
        const minPx = Number.isNaN(min) ? 40 : min;

        editor.style.height = 'auto';
        editor.style.height = `${Math.max(editor.scrollHeight, minPx)}px`;
    };

    useEffect(() => {
        const editor = editorRef.current;
        if (!editor) return;

        const current = htmlToMarkedText(editor.innerHTML);
        const target = String(value ?? '');

        if (current !== target) {
            editor.innerHTML = renderFormattedHtml(target, { multiline: true, linkify: false });
        }

        resizeEditor();
    }, [value]);

    const syncValue = () => {
        if (!editorRef.current) return;
        onChange(htmlToMarkedText(editorRef.current.innerHTML));
    };

    const runCommand = (command) => {
        if (!editorRef.current) return;
        editorRef.current.focus();
        document.execCommand(command, false);
        syncValue();
    };

    const handleKeyDown = (event) => {
        const isModKey = event.ctrlKey || event.metaKey;

        if (event.key === 'Enter' && !isModKey && !event.altKey) {
            event.preventDefault();
            document.execCommand('insertLineBreak');
            syncValue();
            requestAnimationFrame(() => resizeEditor());
            return;
        }

        if (!isModKey) return;

        const key = String(event.key || '').toLowerCase();

        // Ctrl/Cmd + B => gras
        if (key === 'b' && !event.shiftKey && !event.altKey) {
            event.preventDefault();
            runCommand('bold');
            return;
        }

        // Ctrl/Cmd + Shift + X => barré
        if (key === 'x' && event.shiftKey && !event.altKey) {
            event.preventDefault();
            runCommand('strikeThrough');
        }
    };

    const showPlaceholder = !isFocused && !String(value || '').trim();

    return (
        <div className="sm:col-span-2">
            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                {label}
            </label>
            <div className="mt-1 flex items-center gap-2">
                <button
                    type="button"
                    onClick={() => runCommand('bold')}
                    className="inline-flex h-7 items-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-[11px] font-black uppercase tracking-[0.08em]"
                    title="Mettre en gras"
                >
                    <Bold className="h-3.5 w-3.5" strokeWidth={2.3} />
                    Gras
                </button>
                <button
                    type="button"
                    onClick={() => runCommand('strikeThrough')}
                    className="inline-flex h-7 items-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-[11px] font-black uppercase tracking-[0.08em]"
                    title="Mettre en barré"
                >
                    <Strikethrough className="h-3.5 w-3.5" strokeWidth={2.2} />
                    Barré
                </button>
            </div>
            <div className="relative mt-1">
                {showPlaceholder ? (
                    <span className="pointer-events-none absolute left-3 top-2 text-sm text-[var(--app-muted)]">
                        {placeholder}
                    </span>
                ) : null}
                <div
                    ref={editorRef}
                    contentEditable
                    role="textbox"
                    aria-multiline="true"
                    suppressContentEditableWarning
                    onFocus={() => setIsFocused(true)}
                    onBlur={() => {
                        setIsFocused(false);
                        syncValue();
                    }}
                    onInput={() => {
                        syncValue();
                        resizeEditor();
                    }}
                    onKeyDown={handleKeyDown}
                    onPaste={(event) => {
                        event.preventDefault();
                        const text = event.clipboardData?.getData('text/plain') ?? '';
                        document.execCommand('insertText', false, text);
                        syncValue();
                        requestAnimationFrame(() => resizeEditor());
                    }}
                    className="w-full overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--brand-yellow-dark)]/40"
                    style={{ minHeight, whiteSpace: 'pre-wrap' }}
                />
            </div>
            <InputError className="mt-1" message={error} />
        </div>
    );
}

export default function TaskModal({
    open,
    mode = 'create',
    isDuplicate = false,
    form,
    reference,
    onClose,
    onSubmit,
}) {
    const SubmitIcon = isDuplicate ? Copy : Save;
    const modalTitle = isDuplicate
        ? 'DUPLIQUER UNE TÂCHE'
        : (mode === 'edit' ? 'MODIFIER UNE TÂCHE' : 'AJOUTER UNE TÂCHE');
    const submitLabel = isDuplicate ? 'Dupliquer' : 'Enregistrer';
    const processingLabel = isDuplicate ? 'Duplication...' : 'Enregistrement...';
    const submitButtonClassName = isDuplicate
        ? 'w-full rounded-xl border border-[#0f6930] bg-[#0f6930] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-[#12803b] focus:outline-none focus:ring-2 focus:ring-[#0f6930]/35 disabled:opacity-60 sm:w-auto'
        : 'w-full rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-60 sm:w-auto';
    const detected = useMemo(
        () => detectFlags(form?.data?.task, form?.data?.comment),
        [form?.data?.task, form?.data?.comment],
    );

    const showBoursagriContract = Boolean(form?.data?.is_boursagri || detected.isBoursagri);
    const assignees = useMemo(() => assigneeOptions(reference), [reference]);
    const vehicleOptions = useMemo(
        () =>
            (reference?.vehicles || []).map((vehicle) => ({
                value: String(vehicle.id),
                label: vehicle.label,
                mode: vehicle.mode || 'vehicle',
            })),
        [reference],
    );
    const remorqueOptions = useMemo(
        () =>
            (reference?.remorques || []).map((vehicle) => ({
                value: String(vehicle.id),
                label: vehicle.label,
            })),
        [reference],
    );
    const selectedVehicle = useMemo(
        () => vehicleOptions.find((item) => String(item.value) === String(form?.data?.vehicle_id || '')) || null,
        [vehicleOptions, form?.data?.vehicle_id],
    );
    const selectedAssigneeUser = useMemo(() => {
        if ((form?.data?.assignee_type || '') !== 'user') return null;
        return (reference?.assignee_users || []).find(
            (user) => String(user.id) === String(form?.data?.assignee_id || ''),
        ) || null;
    }, [reference, form?.data?.assignee_type, form?.data?.assignee_id]);
    const selectedAssigneeValue = useMemo(() => {
        const type = String(form?.data?.assignee_type || '');
        const id = String(form?.data?.assignee_id || '');
        if (type === 'free') {
            return '';
        }
        return type && id ? `${type}:${id}` : '';
    }, [form?.data?.assignee_type, form?.data?.assignee_id]);
    const showRemorqueField = selectedVehicle?.mode !== 'ensemble_pl';
    const placeSuggestions = useMemo(() => reference?.place_suggestions || [], [reference]);
    const depotPlaceSuggestions = useMemo(() => reference?.depot_place_suggestions || [], [reference]);
    const initializedAutoVehicleRef = useRef(false);
    const lastAssigneeKeyRef = useRef('');

    useEffect(() => {
        if (selectedVehicle?.mode === 'ensemble_pl' && form?.data?.remorque_id) {
            form.setData('remorque_id', '');
        }
    }, [selectedVehicle?.mode, form]);

    useEffect(() => {
        const currentAssigneeKey = `${form?.data?.assignee_type || ''}:${form?.data?.assignee_id || ''}`;

        if (!initializedAutoVehicleRef.current) {
            initializedAutoVehicleRef.current = true;
            lastAssigneeKeyRef.current = currentAssigneeKey;

            if (
                mode === 'create'
                && (form?.data?.assignee_type || '') === 'user'
                && !String(form?.data?.vehicle_id || '').trim()
                && selectedAssigneeUser?.default_vehicle_id
            ) {
                form.setData('vehicle_id', String(selectedAssigneeUser.default_vehicle_id));
            }

            return;
        }

        if (lastAssigneeKeyRef.current === currentAssigneeKey) {
            return;
        }
        lastAssigneeKeyRef.current = currentAssigneeKey;

        if ((form?.data?.assignee_type || '') !== 'user') {
            return;
        }

        if (String(form?.data?.vehicle_id || '').trim()) {
            return;
        }

        const defaultVehicleId = selectedAssigneeUser?.default_vehicle_id;
        if (!defaultVehicleId) {
            return;
        }

        form.setData('vehicle_id', String(defaultVehicleId));
    }, [
        form,
        mode,
        form?.data?.assignee_type,
        form?.data?.assignee_id,
        form?.data?.vehicle_id,
        selectedAssigneeUser?.default_vehicle_id,
    ]);

    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            <form onSubmit={onSubmit}>
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                        {modalTitle}
                    </h3>
                </div>

                <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4 sm:grid-cols-2">
                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Date
                        </label>
                        <input
                            type="date"
                            value={form.data.date}
                            onChange={(e) => form.setData('date', e.target.value)}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                        <InputError className="mt-1" message={form.errors.date} />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Fin (JJ/MM)
                        </label>
                        <input
                            type="text"
                            value={form.data.fin_date || ''}
                            onChange={(e) => form.setData('fin_date', formatDayMonthInput(e.target.value))}
                            inputMode="numeric"
                            placeholder="JJ/MM"
                            maxLength={5}
                            className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                        />
                        <InputError className="mt-1" message={form.errors.fin_date} />
                    </div>

                    <div className="sm:col-span-2">
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Chauffeur
                        </label>
                        <div className="mt-1">
                            <SearchableSelect
                                options={assignees}
                                value={selectedAssigneeValue}
                                onChange={(nextValue) => {
                                    const raw = String(nextValue || '');
                                    if (!raw) {
                                        form.setData('assignee_type', '');
                                        form.setData('assignee_id', '');
                                        form.setData('assignee_label_free', '');
                                        return;
                                    }

                                    const [type, id] = raw.split(':');
                                    const normalizedType = ['user', 'transporter', 'depot'].includes(type)
                                        ? type
                                        : 'user';
                                    form.setData('assignee_type', normalizedType);
                                    form.setData('assignee_id', id || '');
                                    form.setData('assignee_label_free', '');
                                }}
                                placeholder="Rechercher un chauffeur, transporteur ou dépôt..."
                                emptyLabel="Aucun"
                                allowFree
                                freeLabel={form?.data?.assignee_label_free || ''}
                                onFreeSelect={(label) => {
                                    form.setData('assignee_type', 'free');
                                    form.setData('assignee_id', '');
                                    form.setData('assignee_label_free', label);
                                }}
                            />
                        </div>
                        {form?.data?.assignee_type === 'free' && form?.data?.assignee_label_free ? (
                            <div className="mt-1 text-xs text-amber-600">
                                Chauffeur non présent dans la base
                            </div>
                        ) : null}
                        <InputError className="mt-1" message={form.errors.assignee_type || form.errors.assignee_id || form.errors.assignee_label_free} />
                    </div>

                    <div className={showRemorqueField ? '' : 'sm:col-span-2'}>
                        <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Véhicule
                        </label>
                        <div className="mt-1">
                            <SearchableSelect
                                options={vehicleOptions}
                                value={form.data.vehicle_id}
                                onChange={(nextValue) => form.setData('vehicle_id', nextValue)}
                                placeholder="Rechercher un véhicule..."
                                emptyLabel="Aucun"
                            />
                        </div>
                        <InputError className="mt-1" message={form.errors.vehicle_id} />
                    </div>

                    {showRemorqueField ? (
                        <div>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Remorques
                            </label>
                            <div className="mt-1">
                                <SearchableSelect
                                    options={remorqueOptions}
                                    value={form.data.remorque_id}
                                    onChange={(nextValue) => form.setData('remorque_id', nextValue)}
                                    placeholder="Rechercher une remorque..."
                                    emptyLabel="Aucune"
                                />
                            </div>
                            <InputError className="mt-1" message={form.errors.remorque_id} />
                        </div>
                    ) : null}

                    <RichTextMarkerField
                        label="Tâche"
                        value={form.data.task}
                        onChange={(next) => form.setData('task', next)}
                        error={form.errors.task}
                        placeholder="Saisir la tâche..."
                        minHeight="40px"
                    />

                    <PlaceAutocompleteTextarea
                        label="Lieux de chargement"
                        value={form.data.loading_place || ''}
                        onChange={(next) => form.setData('loading_place', next)}
                        error={form.errors.loading_place}
                        placeholder="Lieu(x) de chargement..."
                        suggestions={placeSuggestions}
                        defaultSuggestions={depotPlaceSuggestions}
                    />

                    <PlaceAutocompleteTextarea
                        label="Lieux de livraison"
                        value={form.data.delivery_place || ''}
                        onChange={(next) => form.setData('delivery_place', next)}
                        error={form.errors.delivery_place}
                        placeholder="Lieu(x) de livraison..."
                        suggestions={placeSuggestions}
                        defaultSuggestions={depotPlaceSuggestions}
                    />

                    <RichTextMarkerField
                        label="Commentaire"
                        value={form.data.comment}
                        onChange={(next) => form.setData('comment', next)}
                        error={form.errors.comment}
                        placeholder="Saisir un commentaire..."
                        minHeight="40px"
                    />

                    <div className="sm:col-span-2">
                        <div className="flex flex-wrap items-center gap-3">
                            <label className="inline-flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.data.is_direct)}
                                    onChange={(e) => form.setData('is_direct', e.target.checked)}
                                />
                                <span>D (Direct)</span>
                            </label>

                            <label className="inline-flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.data.is_boursagri)}
                                    onChange={(e) => form.setData('is_boursagri', e.target.checked)}
                                />
                                <span>B (Boursagri)</span>
                            </label>

                            {(detected.isDirect || detected.isBoursagri) ? (
                                <div className="text-xs text-[var(--app-muted)]">
                                    Détection auto :{' '}
                                    {[
                                        detected.isDirect ? 'Direct' : null,
                                        detected.isBoursagri ? 'Boursagri' : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' • ')}
                                </div>
                            ) : null}
                        </div>
                        <InputError className="mt-1" message={form.errors.is_direct || form.errors.is_boursagri} />
                    </div>

                    {showBoursagriContract ? (
                        <div className="sm:col-span-2">
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Contrat Boursagri
                            </label>
                            <input
                                type="text"
                                value={form.data.boursagri_contract_number}
                                onChange={(e) => form.setData('boursagri_contract_number', e.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                placeholder="Numéro de contrat"
                            />
                            <InputError className="mt-1" message={form.errors.boursagri_contract_number} />
                        </div>
                    ) : null}
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] sm:w-auto"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className={submitButtonClassName}
                    >
                        {form.processing ? (
                            processingLabel
                        ) : (
                            <span className="inline-flex items-center gap-1.5">
                                <SubmitIcon className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>{submitLabel}</span>
                            </span>
                        )}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
