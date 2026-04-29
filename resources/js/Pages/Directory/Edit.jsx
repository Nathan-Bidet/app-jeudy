import InputError from '@/Components/InputError';
import DateInput from '@/Components/DateInput';
import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Crop, ImagePlus, Move, Plus, RotateCcw, Save, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const PHOTO_EDITOR_VIEWPORT = 288;
const PHOTO_EDITOR_OUTPUT = 512;

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function clampPhotoOffsets({ x, y, zoom, naturalWidth, naturalHeight }) {
    if (!naturalWidth || !naturalHeight) {
        return { x: 0, y: 0 };
    }

    const baseScale = Math.max(PHOTO_EDITOR_VIEWPORT / naturalWidth, PHOTO_EDITOR_VIEWPORT / naturalHeight);
    const scale = baseScale * zoom;
    const scaledWidth = naturalWidth * scale;
    const scaledHeight = naturalHeight * scale;

    const maxX = Math.max(0, (scaledWidth - PHOTO_EDITOR_VIEWPORT) / 2);
    const maxY = Math.max(0, (scaledHeight - PHOTO_EDITOR_VIEWPORT) / 2);

    return {
        x: clamp(x, -maxX, maxX),
        y: clamp(y, -maxY, maxY),
    };
}

function fileNameWithExtension(name, mime) {
    const safeName = (name || 'photo-profil').replace(/\.[^.]+$/, '');
    const extMap = {
        'image/jpeg': 'jpg',
        'image/png': 'png',
        'image/webp': 'webp',
    };
    const ext = extMap[mime] || 'png';
    return `${safeName}.${ext}`;
}

function Section({ title, children, description = null }) {
    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <h2 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">{title}</h2>
            {description ? (
                <p className="mt-1 text-xs text-[var(--app-muted)]">{description}</p>
            ) : null}
            <div className="mt-4">{children}</div>
        </section>
    );
}

function Field({ label, error, children, hint = null }) {
    return (
        <div>
            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                {label}
            </label>
            <div className="mt-1">{children}</div>
            {hint ? <p className="mt-1 text-[11px] text-[var(--app-muted)]">{hint}</p> : null}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function inputClass(disabled = false) {
    return `w-full rounded-xl border border-[var(--app-border)] px-3 py-2 text-sm text-[var(--app-text)] ${
        disabled ? 'bg-[var(--app-surface-soft)] opacity-80' : 'bg-[var(--app-surface-soft)]'
    }`;
}

const validityFields = [
    ['driving_license_valid_until', 'Permis'],
    ['fco_valid_until', 'FIMO / FCO'],
    ['adr_valid_until', 'ADR'],
    ['eco_conduite_valid_until', 'Éco-conduite'],
    ['certiphyto_valid_until', 'Certiphyto'],
    ['caces_valid_until', 'CACES'],
    ['fimo_valid_until', 'CACES GRUE'],
    ['nacelle_valid_until', 'Habilitation nacelle'],
    ['occupational_health_valid_until', 'Médecine du travail'],
    ['sst_valid_until', 'SST'],
];

export default function DirectoryEdit({ profile, sectors, depots, permissions, routes, field_access }) {
    const canManageAll = Boolean(permissions?.can_manage_all_fields);
    const fileInputRef = useRef(null);
    const photoEditorImageRef = useRef(null);
    const dragStateRef = useRef(null);

    const form = useForm({
        first_name: profile?.first_name ?? '',
        last_name: profile?.last_name ?? '',
        email: profile?.email ?? '',
        phone: profile?.phone ?? '',
        mobile_phone: profile?.mobile_phone ?? '',
        directory_phones: Array.isArray(profile?.directory_phones) ? profile.directory_phones : [],
        internal_number: profile?.internal_number ?? '',
        sector_id: profile?.sector_id ? String(profile.sector_id) : '',
        depot_id: profile?.depot_id ? String(profile.depot_id) : '',
        birthday: profile?.birthday ?? '',
        glpi_url: profile?.glpi_url ?? '',
        driving_license_valid_until: profile?.driving_license_valid_until ?? '',
        fimo_valid_until: profile?.fimo_valid_until ?? '',
        adr_valid_until: profile?.adr_valid_until ?? '',
        fco_valid_until: profile?.fco_valid_until ?? '',
        caces_valid_until: profile?.caces_valid_until ?? '',
        certiphyto_valid_until: profile?.certiphyto_valid_until ?? '',
        nacelle_valid_until: profile?.nacelle_valid_until ?? '',
        eco_conduite_valid_until: profile?.eco_conduite_valid_until ?? '',
        occupational_health_valid_until: profile?.occupational_health_valid_until ?? '',
        sst_valid_until: profile?.sst_valid_until ?? '',
        photo: null,
    });

    const [photoEditor, setPhotoEditor] = useState({
        open: false,
        src: null,
        sourceName: 'photo-profil',
        sourceMime: 'image/jpeg',
        naturalWidth: 0,
        naturalHeight: 0,
        zoom: 1,
        x: 0,
        y: 0,
        revokeOnClose: false,
        applying: false,
        error: '',
    });

    const photoPreview = useMemo(() => {
        if (form.data.photo instanceof File) {
            return URL.createObjectURL(form.data.photo);
        }

        return profile?.photo_url ?? null;
    }, [form.data.photo, profile?.photo_url]);

    useEffect(() => {
        return () => {
            if (photoEditor.revokeOnClose && photoEditor.src) {
                URL.revokeObjectURL(photoEditor.src);
            }
        };
    }, [photoEditor.revokeOnClose, photoEditor.src]);

    const closePhotoEditor = () => {
        setPhotoEditor((current) => {
            if (current.revokeOnClose && current.src) {
                URL.revokeObjectURL(current.src);
            }

            return {
                open: false,
                src: null,
                sourceName: 'photo-profil',
                sourceMime: 'image/jpeg',
                naturalWidth: 0,
                naturalHeight: 0,
                zoom: 1,
                x: 0,
                y: 0,
                revokeOnClose: false,
                applying: false,
                error: '',
            };
        });
    };

    const openPhotoEditor = ({ src, sourceName, sourceMime, revokeOnClose = false }) => {
        setPhotoEditor({
            open: true,
            src,
            sourceName: sourceName || 'photo-profil',
            sourceMime: ['image/jpeg', 'image/png', 'image/webp'].includes(sourceMime)
                ? sourceMime
                : 'image/png',
            naturalWidth: 0,
            naturalHeight: 0,
            zoom: 1,
            x: 0,
            y: 0,
            revokeOnClose,
            applying: false,
            error: '',
        });
    };

    const handlePhotoFileSelected = (event) => {
        const file = event.target.files?.[0];

        if (!file) {
            return;
        }

        const objectUrl = URL.createObjectURL(file);
        openPhotoEditor({
            src: objectUrl,
            sourceName: file.name || 'photo-profil',
            sourceMime: file.type || 'image/jpeg',
            revokeOnClose: true,
        });

        event.target.value = '';
    };

    const handleEditCurrentPhoto = () => {
        if (!photoPreview) {
            return;
        }

        const currentName = form.data.photo instanceof File ? form.data.photo.name : 'photo-profil';
        const currentMime = form.data.photo instanceof File ? form.data.photo.type : 'image/jpeg';

        openPhotoEditor({
            src: photoPreview,
            sourceName: currentName,
            sourceMime: currentMime,
            revokeOnClose: false,
        });
    };

    const applyPhotoCrop = async () => {
        const img = photoEditorImageRef.current;

        if (!img || !photoEditor.naturalWidth || !photoEditor.naturalHeight) {
            setPhotoEditor((current) => ({ ...current, error: 'Image non prête.' }));
            return;
        }

        setPhotoEditor((current) => ({ ...current, applying: true, error: '' }));

        try {
            const canvas = document.createElement('canvas');
            canvas.width = PHOTO_EDITOR_OUTPUT;
            canvas.height = PHOTO_EDITOR_OUTPUT;

            const ctx = canvas.getContext('2d');

            if (!ctx) {
                throw new Error('canvas');
            }

            const baseScale = Math.max(
                PHOTO_EDITOR_VIEWPORT / photoEditor.naturalWidth,
                PHOTO_EDITOR_VIEWPORT / photoEditor.naturalHeight,
            );
            const scale = baseScale * photoEditor.zoom;
            const ratio = PHOTO_EDITOR_OUTPUT / PHOTO_EDITOR_VIEWPORT;
            const drawX = ((PHOTO_EDITOR_VIEWPORT - photoEditor.naturalWidth * scale) / 2 + photoEditor.x) * ratio;
            const drawY = ((PHOTO_EDITOR_VIEWPORT - photoEditor.naturalHeight * scale) / 2 + photoEditor.y) * ratio;
            const drawW = photoEditor.naturalWidth * scale * ratio;
            const drawH = photoEditor.naturalHeight * scale * ratio;

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, drawX, drawY, drawW, drawH);

            const blob = await new Promise((resolve, reject) => {
                canvas.toBlob(
                    (result) => (result ? resolve(result) : reject(new Error('blob'))),
                    photoEditor.sourceMime || 'image/png',
                    0.92,
                );
            });

            const file = new File([blob], fileNameWithExtension(photoEditor.sourceName, photoEditor.sourceMime), {
                type: photoEditor.sourceMime || 'image/png',
            });

            form.setData('photo', file);
            closePhotoEditor();
        } catch {
            setPhotoEditor((current) => ({
                ...current,
                applying: false,
                error: 'Impossible d’appliquer le recadrage.',
            }));
            return;
        }
    };

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({
            ...data,
            sector_id: canManageAll && data.sector_id ? Number(data.sector_id) : data.sector_id,
            depot_id: canManageAll && data.depot_id ? Number(data.depot_id) : data.depot_id,
        }));

        form.put(routes.update, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                form.transform((data) => data);
            },
        });
    };

    const disabled = (path, key) => {
        if (typeof field_access?.[path] === 'boolean') {
            return !field_access[path];
        }

        return !(field_access?.[path]?.[key] ?? false);
    };

    const resetPhotoEditorView = () => {
        setPhotoEditor((current) => ({ ...current, zoom: 1, x: 0, y: 0 }));
    };

    const setPhotoZoom = (value) => {
        setPhotoEditor((current) => {
            const zoom = Number(value);
            const clamped = clampPhotoOffsets({
                x: current.x,
                y: current.y,
                zoom,
                naturalWidth: current.naturalWidth,
                naturalHeight: current.naturalHeight,
            });

            return {
                ...current,
                zoom,
                x: clamped.x,
                y: clamped.y,
            };
        });
    };

    const startPhotoDrag = (event) => {
        if (!photoEditor.open) {
            return;
        }

        dragStateRef.current = {
            startX: event.clientX,
            startY: event.clientY,
            baseX: photoEditor.x,
            baseY: photoEditor.y,
        };

        event.currentTarget.setPointerCapture?.(event.pointerId);
    };

    const onPhotoDrag = (event) => {
        if (!dragStateRef.current) {
            return;
        }

        const next = {
            x: dragStateRef.current.baseX + (event.clientX - dragStateRef.current.startX),
            y: dragStateRef.current.baseY + (event.clientY - dragStateRef.current.startY),
        };

        setPhotoEditor((current) => {
            const clamped = clampPhotoOffsets({
                ...next,
                zoom: current.zoom,
                naturalWidth: current.naturalWidth,
                naturalHeight: current.naturalHeight,
            });

            return { ...current, x: clamped.x, y: clamped.y };
        });
    };

    const endPhotoDrag = (event) => {
        dragStateRef.current = null;
        event.currentTarget.releasePointerCapture?.(event.pointerId);
    };

    const addPhoneRow = () => {
        form.setData('directory_phones', [
            ...(Array.isArray(form.data.directory_phones) ? form.data.directory_phones : []),
            { label: 'Téléphone', number: '' },
        ]);
    };

    const removePhoneRow = (index) => {
        form.setData(
            'directory_phones',
            (form.data.directory_phones || []).filter((_, i) => i !== index),
        );
    };

    const updatePhoneRow = (index, key, value) => {
        form.setData(
            'directory_phones',
            (form.data.directory_phones || []).map((row, i) =>
                i === index ? { ...row, [key]: value } : row,
            ),
        );
    };

    const editorBaseScale = photoEditor.naturalWidth && photoEditor.naturalHeight
        ? Math.max(
              PHOTO_EDITOR_VIEWPORT / photoEditor.naturalWidth,
              PHOTO_EDITOR_VIEWPORT / photoEditor.naturalHeight,
          )
        : 1;
    const editorScale = editorBaseScale * photoEditor.zoom;
    const editorImageWidth = photoEditor.naturalWidth ? photoEditor.naturalWidth * editorScale : PHOTO_EDITOR_VIEWPORT;
    const editorImageHeight = photoEditor.naturalHeight ? photoEditor.naturalHeight * editorScale : PHOTO_EDITOR_VIEWPORT;

    return (
        <AppLayout title="Modifier Fiche">
            <Head title="Modifier fiche annuaire" />

            <form onSubmit={submit} className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link
                        href={routes.show}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] shadow-sm"
                    >
                        Annuler
                    </Link>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] shadow-sm disabled:opacity-60"
                    >
                        {form.processing ? (
                            'Enregistrement...'
                        ) : (
                            <span className="inline-flex items-center gap-1.5">
                                <Save className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>Enregistrer</span>
                            </span>
                        )}
                    </button>
                </div>

                <Section title="Identité & contact">
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Prénom" error={form.errors.first_name}>
                            <input
                                type="text"
                                value={form.data.first_name}
                                onChange={(e) => form.setData('first_name', e.target.value)}
                                disabled={disabled('identity_contact', 'first_name')}
                                className={inputClass(disabled('identity_contact', 'first_name'))}
                            />
                        </Field>

                        <Field label="Nom" error={form.errors.last_name}>
                            <input
                                type="text"
                                value={form.data.last_name}
                                onChange={(e) => form.setData('last_name', e.target.value)}
                                disabled={disabled('identity_contact', 'last_name')}
                                className={inputClass(disabled('identity_contact', 'last_name'))}
                            />
                        </Field>

                        <Field label="Email" error={form.errors.email}>
                            <input
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                disabled={disabled('identity_contact', 'email')}
                                className={inputClass(disabled('identity_contact', 'email'))}
                            />
                        </Field>

                        <Field label="Téléphone" error={form.errors.phone}>
                            <input
                                type="text"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                disabled={disabled('identity_contact', 'phone')}
                                className={inputClass(disabled('identity_contact', 'phone'))}
                            />
                        </Field>

                        <Field label="Mobile" error={form.errors.mobile_phone}>
                            <input
                                type="text"
                                value={form.data.mobile_phone}
                                onChange={(e) => form.setData('mobile_phone', e.target.value)}
                                disabled={disabled('identity_contact', 'mobile_phone')}
                                className={inputClass(disabled('identity_contact', 'mobile_phone'))}
                            />
                        </Field>

                        <Field label="Numéro interne" error={form.errors.internal_number}>
                            <input
                                type="text"
                                value={form.data.internal_number}
                                onChange={(e) => form.setData('internal_number', e.target.value)}
                                disabled={disabled('identity_contact', 'internal_number')}
                                className={inputClass(disabled('identity_contact', 'internal_number'))}
                            />
                        </Field>

                        <Field label="Anniversaire" error={form.errors.birthday}>
                            <DateInput
                                value={form.data.birthday}
                                onChange={(nextValue) => form.setData('birthday', nextValue)}
                                label="Anniversaire"
                                className={inputClass(false)}
                            />
                        </Field>
                    </div>

                    <div className="mt-5 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                                    Téléphones supplémentaires
                                </p>
                                <p className="mt-1 text-[11px] text-[var(--app-muted)]">
                                    Exemple : Perso, Astreinte, Bureau...
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={addPhoneRow}
                                className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                            >
                                <span className="inline-flex items-center gap-1">
                                    <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    <span>Ajouter</span>
                                </span>
                            </button>
                        </div>

                        <div className="mt-3 space-y-3">
                            {(form.data.directory_phones || []).length === 0 ? (
                                <p className="text-xs text-[var(--app-muted)]">Aucun téléphone supplémentaire.</p>
                            ) : (
                                (form.data.directory_phones || []).map((row, index) => (
                                    <div
                                        key={`directory-phone-${index}`}
                                        className="grid gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3 md:grid-cols-[180px_1fr_auto]"
                                    >
                                        <div>
                                            <input
                                                type="text"
                                                value={row?.label ?? ''}
                                                onChange={(e) => updatePhoneRow(index, 'label', e.target.value)}
                                                placeholder="Nom du champ"
                                                className={inputClass(false)}
                                            />
                                            <InputError
                                                message={form.errors[`directory_phones.${index}.label`]}
                                                className="mt-1"
                                            />
                                        </div>

                                        <div>
                                            <input
                                                type="text"
                                                value={row?.number ?? ''}
                                                onChange={(e) => updatePhoneRow(index, 'number', e.target.value)}
                                                placeholder="Numéro"
                                                className={inputClass(false)}
                                            />
                                            <InputError
                                                message={form.errors[`directory_phones.${index}.number`]}
                                                className="mt-1"
                                            />
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => removePhoneRow(index)}
                                            className="rounded-lg border border-red-600 bg-red-600 px-2.5 py-2 text-[10px] font-black uppercase tracking-[0.12em] text-white"
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                <span>Retirer</span>
                                            </span>
                                        </button>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </Section>

                <Section title="Organisation" description="Certains champs sont en lecture seule pour le moment.">
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Secteur" error={form.errors.sector_id}>
                            {disabled('organization', 'sector_id') ? (
                                <input
                                    type="text"
                                    value={profile?.sector_name || 'Non défini'}
                                    disabled
                                    className={inputClass(true)}
                                />
                            ) : (
                                <select
                                    value={form.data.sector_id}
                                    onChange={(e) => form.setData('sector_id', e.target.value)}
                                    className={inputClass(false)}
                                >
                                    <option value="">Aucun</option>
                                    {(sectors ?? []).map((sector) => (
                                        <option key={sector.id} value={sector.id}>
                                            {sector.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>

                        <Field label="Dépôt rattaché" error={form.errors.depot_id}>
                            {disabled('organization', 'depot_id') ? (
                                <input
                                    type="text"
                                    value={profile?.depot_name || 'Aucun'}
                                    disabled
                                    className={inputClass(true)}
                                />
                            ) : (
                                <select
                                    value={form.data.depot_id}
                                    onChange={(e) => form.setData('depot_id', e.target.value)}
                                    className={inputClass(false)}
                                >
                                    <option value="">Aucun</option>
                                    {(depots ?? []).map((depot) => (
                                        <option key={depot.id} value={depot.id}>
                                            {depot.name}
                                            {depot.is_active ? '' : ' (inactif)'}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>

                        <Field label="Poste" hint="Placeholder (champ non géré en base pour l’instant).">
                            <input
                                type="text"
                                value={profile?.job_title || ''}
                                disabled
                                className={inputClass(true)}
                            />
                        </Field>

                        <Field label="Responsable secteur" hint="Placeholder (champ non géré en base pour l’instant).">
                            <input
                                type="text"
                                value={profile?.sector_manager || ''}
                                disabled
                                className={inputClass(true)}
                            />
                        </Field>
                    </div>
                </Section>

                <Section title="Dates de validité">
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        {validityFields.map(([field, label]) => (
                            <Field key={field} label={label} error={form.errors[field]}>
                                <DateInput
                                    value={form.data[field]}
                                    onChange={(nextValue) => form.setData(field, nextValue)}
                                    label={label}
                                    disabled={disabled('validities')}
                                    className={inputClass(disabled('validities'))}
                                />
                            </Field>
                        ))}
                    </div>
                </Section>

                <Section title="Liens & infos diverses">
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Lien GLPI" error={form.errors.glpi_url}>
                            <input
                                type="url"
                                value={form.data.glpi_url}
                                onChange={(e) => form.setData('glpi_url', e.target.value)}
                                disabled={disabled('links', 'glpi_url')}
                                className={inputClass(disabled('links', 'glpi_url'))}
                                placeholder="https://..."
                            />
                        </Field>
                    </div>
                </Section>

                <Section title="Photo de profil" description="Image jpg/png/webp - 2 Mo max.">
                    <div className="grid gap-4 md:grid-cols-[auto_1fr] md:items-start">
                        <div>
                            {photoPreview ? (
                                <img
                                    src={photoPreview}
                                    alt="Aperçu photo"
                                    className="h-24 w-24 rounded-2xl border border-[var(--app-border)] object-cover"
                                />
                            ) : (
                                <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-xs font-bold text-[var(--app-muted)]">
                                    Aucune photo
                                </div>
                            )}
                        </div>

                        <div>
                            <Field label="Remplacer la photo" error={form.errors.photo}>
                                <div className="space-y-2">
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                        onChange={handlePhotoFileSelected}
                                        className="hidden"
                                    />

                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            onClick={() => fileInputRef.current?.click()}
                                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                                        >
                                            <span className="inline-flex items-center gap-1.5">
                                                <ImagePlus className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                <span>Choisir une image</span>
                                            </span>
                                        </button>

                                        {photoPreview ? (
                                            <button
                                                type="button"
                                                onClick={handleEditCurrentPhoto}
                                                className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)]"
                                            >
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Crop className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    <span>Modifier</span>
                                                </span>
                                            </button>
                                        ) : null}
                                    </div>

                                    <p className="text-[11px] text-[var(--app-muted)]">
                                        Après sélection, vous pouvez zoomer et recentrer avant enregistrement.
                                    </p>
                                </div>
                            </Field>
                        </div>
                    </div>
                </Section>
            </form>

            <Modal show={photoEditor.open} onClose={closePhotoEditor} maxWidth="2xl">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        Ajuster la photo de profil
                    </h3>
                    <p className="mt-1 text-xs text-[var(--app-muted)]">
                        Zoomez et déplacez l’image pour centrer le visage.
                    </p>
                </div>

                <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4">
                    <div className="flex justify-center">
                        <div
                            className="relative h-72 w-72 select-none overflow-hidden rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] touch-none"
                            onPointerDown={startPhotoDrag}
                            onPointerMove={onPhotoDrag}
                            onPointerUp={endPhotoDrag}
                            onPointerCancel={endPhotoDrag}
                        >
                            {photoEditor.src ? (
                                <>
                                    <img
                                        ref={photoEditorImageRef}
                                        src={photoEditor.src}
                                        alt="Prévisualisation recadrage"
                                        onLoad={(e) => {
                                            const img = e.currentTarget;
                                            setPhotoEditor((current) => {
                                                const clamped = clampPhotoOffsets({
                                                    x: current.x,
                                                    y: current.y,
                                                    zoom: current.zoom,
                                                    naturalWidth: img.naturalWidth,
                                                    naturalHeight: img.naturalHeight,
                                                });

                                                return {
                                                    ...current,
                                                    naturalWidth: img.naturalWidth,
                                                    naturalHeight: img.naturalHeight,
                                                    x: clamped.x,
                                                    y: clamped.y,
                                                };
                                            });
                                        }}
                                        draggable={false}
                                        className="pointer-events-none absolute left-1/2 top-1/2 max-w-none"
                                        style={{
                                            width: `${editorImageWidth}px`,
                                            height: `${editorImageHeight}px`,
                                            transform: `translate(calc(-50% + ${photoEditor.x}px), calc(-50% + ${photoEditor.y}px))`,
                                        }}
                                    />
                                    <div className="pointer-events-none absolute inset-0 ring-1 ring-inset ring-white/40" />
                                </>
                            ) : null}
                        </div>
                    </div>

                    <div className="grid gap-3 md:grid-cols-[1fr_auto] md:items-center">
                        <div className="space-y-2">
                            <label className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                <Move className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>Déplacer / Zoomer</span>
                            </label>
                            <input
                                type="range"
                                min="1"
                                max="3"
                                step="0.01"
                                value={photoEditor.zoom}
                                onChange={(e) => setPhotoZoom(e.target.value)}
                                className="w-full"
                            />
                            <div className="text-xs text-[var(--app-muted)]">
                                Zoom: {photoEditor.zoom.toFixed(2)}x
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={resetPhotoEditorView}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)]"
                        >
                            <span className="inline-flex items-center gap-1.5">
                                <RotateCcw className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>Recentrer</span>
                            </span>
                        </button>
                    </div>

                    {photoEditor.error ? (
                        <div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {photoEditor.error}
                        </div>
                    ) : null}
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={closePhotoEditor}
                        disabled={photoEditor.applying}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] disabled:opacity-60"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        onClick={applyPhotoCrop}
                        disabled={photoEditor.applying || !photoEditor.src}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-60"
                    >
                        {photoEditor.applying ? (
                            'Application...'
                        ) : (
                            <span className="inline-flex items-center gap-1.5">
                                <Save className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>Appliquer</span>
                            </span>
                        )}
                    </button>
                </div>
            </Modal>
        </AppLayout>
    );
}
