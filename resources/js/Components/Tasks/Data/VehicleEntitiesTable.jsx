import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { Pencil, Plus, Save, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

function normalizeString(value) {
    return String(value ?? '')
        .toLowerCase()
        .trim();
}

function activeBadge(isActive) {
    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${
                isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'
            }`}
        >
            {isActive ? 'Oui' : 'Non'}
        </span>
    );
}

const BASE_FORM = {
    section: '',
    vehicle_type_id: '',
    name: '',
    registration: '',
    code_zeendoc: '',
    depot_id: '',
    tractor_vehicle_id: '',
    benne_ids: [],
    is_active: true,
};

const MODAL_ENTITY_LABEL = {
    camions: 'un camion',
    remorques: 'une remorque',
    ensembles_pl: 'un ensemble PL',
    vl: 'un véhicule léger',
};

function normalizeValue(value) {
    return value === null || value === undefined ? '' : String(value);
}

export default function VehicleEntitiesTable({
    title,
    hint,
    vehicles = [],
    sectionKey,
    canManage = false,
    formOptions = {},
}) {
    const [search, setSearch] = useState('');
    const [copiedZeendocId, setCopiedZeendocId] = useState(null);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState(null);
    const form = useForm({ ...BASE_FORM, section: sectionKey });

    const typeOptions = useMemo(
        () => formOptions?.type_options?.[sectionKey] ?? [],
        [formOptions, sectionKey],
    );
    const depotOptions = useMemo(() => formOptions?.depots ?? [], [formOptions]);
    const tractorOptions = useMemo(() => formOptions?.tractor_candidates ?? [], [formOptions]);
    const remorqueOptions = useMemo(() => formOptions?.remorque_candidates ?? [], [formOptions]);

    const rows = useMemo(() => {
        const needle = normalizeString(search);

        if (!needle) {
            return vehicles;
        }

        return vehicles.filter((vehicle) => {
            const haystack = [
                vehicle.display_label,
                vehicle.name,
                vehicle.registration,
                vehicle.code_zeendoc,
                vehicle.type_label,
                vehicle.depot_name,
                vehicle.garage_name,
                vehicle.tractor_label,
                (vehicle.bennes_labels ?? []).join(' '),
            ]
                .map(normalizeString)
                .join(' ');

            return haystack.includes(needle);
        });
    }, [search, vehicles]);

    const copyZeendoc = async (vehicleId, code) => {
        if (!code) return;

        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(code);
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = code;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'absolute';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }

            setCopiedZeendocId(vehicleId);
            window.setTimeout(() => {
                setCopiedZeendocId((current) => (current === vehicleId ? null : current));
            }, 1500);
        } catch {
            // No-op si le clipboard est bloqué.
        }
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingItem(null);
        form.reset();
        form.clearErrors();
        form.setData({
            ...BASE_FORM,
            section: sectionKey,
            vehicle_type_id:
                sectionKey !== 'ensembles_pl' && typeOptions.length === 1
                    ? String(typeOptions[0].id)
                    : '',
        });
    };

    const openCreate = () => {
        setEditingItem(null);
        form.clearErrors();
        form.setData({
            ...BASE_FORM,
            section: sectionKey,
            is_active: true,
            vehicle_type_id:
                sectionKey !== 'ensembles_pl' && typeOptions.length === 1
                    ? String(typeOptions[0].id)
                    : '',
        });
        setIsModalOpen(true);
    };

    const openEdit = (vehicle) => {
        setEditingItem(vehicle);
        form.clearErrors();
        form.setData({
            section: sectionKey,
            vehicle_type_id:
                sectionKey !== 'ensembles_pl'
                    ? normalizeValue(
                          vehicle.vehicle_type_id ??
                              (typeOptions.length === 1 ? typeOptions[0].id : ''),
                      )
                    : '',
            name: vehicle.name ?? '',
            registration: vehicle.registration ?? '',
            code_zeendoc: vehicle.code_zeendoc ?? '',
            depot_id: normalizeValue(vehicle.depot_id),
            tractor_vehicle_id: normalizeValue(vehicle.tractor_vehicle_id),
            benne_ids: (vehicle.benne_ids ?? []).map((id) => String(id)),
            is_active: Boolean(vehicle.is_active),
        });
        setIsModalOpen(true);
    };

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        };

        if (editingItem) {
            form.put(route('task.data.vehicles.update', editingItem.id), options);
            return;
        }

        form.post(route('task.data.vehicles.store'), options);
    };

    const isEnsembleSection = sectionKey === 'ensembles_pl';
    const modalEntityLabel = MODAL_ENTITY_LABEL[sectionKey] ?? 'un véhicule';

    return (
        <>
            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-sm">
                <div className="border-b border-[var(--app-border)] px-4 py-3 sm:px-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold text-[var(--app-text)]">{title}</h2>
                            <p className="text-sm text-[var(--app-muted)]">{hint}</p>
                        </div>

                        <div className="flex w-full max-w-xl items-center justify-end gap-2">
                            <div className="relative w-full max-w-sm">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--app-muted)]" />
                                <TextInput
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Rechercher un véhicule..."
                                    className="w-full pl-9"
                                />
                            </div>

                            {canManage ? (
                                <button
                                    type="button"
                                    onClick={openCreate}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                                >
                                    <Plus className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    Ajouter
                                </button>
                            ) : null}
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-[980px] w-full divide-y divide-[var(--app-border)] text-sm">
                        <thead className="bg-[var(--app-surface-soft)]">
                            <tr className="text-left text-[var(--app-muted)]">
                                <th className="px-4 py-2 font-semibold">Nom</th>
                                <th className="px-4 py-2 font-semibold">Immatriculation</th>
                                <th className="px-4 py-2 font-semibold">Type</th>
                                <th className="px-4 py-2 font-semibold">Dépôt</th>
                                <th className="px-4 py-2 font-semibold">Détails</th>
                                <th className="px-4 py-2 font-semibold text-center">Actif</th>
                                {canManage ? (
                                    <th className="px-4 py-2 font-semibold text-right">Actions</th>
                                ) : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--app-border)]">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={canManage ? 7 : 6}
                                        className="px-4 py-6 text-center text-[var(--app-muted)]"
                                    >
                                        Aucun élément.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((vehicle) => (
                                    <tr key={vehicle.id}>
                                        <td className="px-4 py-3 font-semibold">
                                            {vehicle.display_label || (
                                                <span className="text-[var(--app-muted)]">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {vehicle.registration || (
                                                <span className="text-[var(--app-muted)]">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {vehicle.type_label || (
                                                <span className="text-[var(--app-muted)]">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {vehicle.depot_name || (
                                                <span className="text-[var(--app-muted)]">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {sectionKey === 'ensembles_pl' ? (
                                                <div className="space-y-1 text-xs">
                                                    <p>
                                                        <span className="font-semibold">Camion:</span>{' '}
                                                        {vehicle.tractor_label || (
                                                            <span className="text-[var(--app-muted)]">-</span>
                                                        )}
                                                    </p>
                                                    <p>
                                                        <span className="font-semibold">Remorque:</span>{' '}
                                                        {(vehicle.bennes_labels ?? []).length ? (
                                                            vehicle.bennes_labels.join(', ')
                                                        ) : (
                                                            <span className="text-[var(--app-muted)]">-</span>
                                                        )}
                                                    </p>
                                                </div>
                                            ) : (
                                                <div className="space-y-1 text-xs">
                                                    <p>
                                                        <span className="font-semibold">Code Zeendoc:</span>{' '}
                                                        {vehicle.code_zeendoc ? (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        copyZeendoc(
                                                                            vehicle.id,
                                                                            vehicle.code_zeendoc,
                                                                        )
                                                                    }
                                                                    className="underline decoration-dotted underline-offset-2"
                                                                    title="Cliquer pour copier"
                                                                >
                                                                    {vehicle.code_zeendoc}
                                                                </button>
                                                                {copiedZeendocId === vehicle.id ? (
                                                                    <span className="ml-2 text-[10px] font-bold text-green-600">
                                                                        Copié
                                                                    </span>
                                                                ) : null}
                                                            </>
                                                        ) : (
                                                            <span className="text-[var(--app-muted)]">-</span>
                                                        )}
                                                    </p>
                                                    <p>
                                                        <span className="font-semibold">Garage:</span>{' '}
                                                        {vehicle.garage_name || (
                                                            <span className="text-[var(--app-muted)]">-</span>
                                                        )}
                                                    </p>
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {activeBadge(Boolean(vehicle.is_active))}
                                        </td>
                                        {canManage ? (
                                            <td className="px-4 py-3 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(vehicle)}
                                                    className="inline-flex items-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-[11px] font-black uppercase tracking-[0.08em]"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                    Éditer
                                                </button>
                                            </td>
                                        ) : null}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            <Modal show={isModalOpen} onClose={closeModal} maxWidth="2xl">
                <form onSubmit={submit}>
                    <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                        <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                            {editingItem ? 'Modifier' : 'Ajouter'} {modalEntityLabel}
                        </h3>
                    </div>

                    <div className="grid gap-4 bg-[var(--app-surface)] px-5 py-4 sm:grid-cols-2">
                        {isEnsembleSection ? (
                            <>
                                <div>
                                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Camion (tracteur)
                                    </label>
                                    <select
                                        value={form.data.tractor_vehicle_id}
                                        onChange={(event) =>
                                            form.setData('tractor_vehicle_id', event.target.value)
                                        }
                                        className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                    >
                                        <option value="">Sélectionner</option>
                                        {tractorOptions.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError className="mt-1" message={form.errors.tractor_vehicle_id} />
                                </div>
                                <div>
                                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Remorque(s)
                                    </label>
                                    <select
                                        multiple
                                        value={form.data.benne_ids}
                                        onChange={(event) =>
                                            form.setData(
                                                'benne_ids',
                                                Array.from(event.target.selectedOptions).map(
                                                    (option) => option.value,
                                                ),
                                            )
                                        }
                                        className="mt-1 min-h-28 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                    >
                                        {remorqueOptions.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError className="mt-1" message={form.errors.benne_ids} />
                                </div>
                            </>
                        ) : (
                            <div>
                                <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                    Type
                                </label>
                                <select
                                    value={form.data.vehicle_type_id}
                                    onChange={(event) =>
                                        form.setData('vehicle_type_id', event.target.value)
                                    }
                                    disabled={typeOptions.length <= 1}
                                    className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm disabled:opacity-70"
                                >
                                    <option value="">Sélectionner</option>
                                    {typeOptions.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError className="mt-1" message={form.errors.vehicle_type_id} />
                            </div>
                        )}

                        <div>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Nom
                            </label>
                            <TextInput
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className="mt-1 w-full"
                            />
                            <InputError className="mt-1" message={form.errors.name} />
                        </div>

                        {!isEnsembleSection ? (
                            <>
                                <div>
                                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Immatriculation
                                    </label>
                                    <TextInput
                                        value={form.data.registration}
                                        onChange={(event) =>
                                            form.setData('registration', event.target.value)
                                        }
                                        className="mt-1 w-full"
                                    />
                                    <InputError className="mt-1" message={form.errors.registration} />
                                </div>

                                <div>
                                    <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Code Zeendoc
                                    </label>
                                    <TextInput
                                        value={form.data.code_zeendoc}
                                        onChange={(event) =>
                                            form.setData('code_zeendoc', event.target.value)
                                        }
                                        className="mt-1 w-full"
                                    />
                                    <InputError className="mt-1" message={form.errors.code_zeendoc} />
                                </div>
                            </>
                        ) : null}

                        <div>
                            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Dépôt
                            </label>
                            <select
                                value={form.data.depot_id}
                                onChange={(event) => form.setData('depot_id', event.target.value)}
                                className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                            >
                                <option value="">Aucun</option>
                                {depotOptions.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                            <InputError className="mt-1" message={form.errors.depot_id} />
                        </div>

                        <div className="flex items-end">
                            <label className="inline-flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.data.is_active)}
                                    onChange={(event) => form.setData('is_active', event.target.checked)}
                                />
                                <span>Actif</span>
                            </label>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                        <button
                            type="button"
                            onClick={closeModal}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                        >
                            Annuler
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-60"
                        >
                            <Save className="h-3.5 w-3.5" strokeWidth={2.2} />
                            {form.processing ? 'Enregistrement...' : 'Enregistrer'}
                        </button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
