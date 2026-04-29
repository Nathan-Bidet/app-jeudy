import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

function MappingAutocomplete({ options = [], targetType = '', targetId = '', onChange, placeholder = 'Rechercher...' }) {
    const selectedOption = useMemo(() => (
        options.find((option) => (
            option.target_type === (targetType || null)
            && Number(option.target_id) === Number(targetId || 0)
        )) ?? null
    ), [options, targetId, targetType]);

    const [query, setQuery] = useState(selectedOption?.label ?? '');
    const [open, setOpen] = useState(false);

    useEffect(() => {
        setQuery(selectedOption?.label ?? '');
    }, [selectedOption?.id, selectedOption?.label]);

    const filtered = useMemo(() => {
        const normalized = query.trim().toLowerCase();

        if (normalized === '') {
            return options.slice(0, 12);
        }

        return options
            .filter((option) => {
                const haystack = (option.search_text ?? option.label ?? '').toLowerCase();

                return haystack.includes(normalized);
            })
            .slice(0, 12);
    }, [options, query]);

    const pick = (option) => {
        onChange(option ? {
            target_type: option.target_type,
            target_id: Number(option.target_id),
        } : {
            target_type: '',
            target_id: '',
        });
        setQuery(option?.label ?? '');
        setOpen(false);
    };

    return (
        <div className="relative">
            <div className="flex gap-2">
                <input
                    type="text"
                    value={query}
                    onFocus={() => setOpen(true)}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                    }}
                    onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                    placeholder={placeholder}
                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-text)]"
                />
                <button
                    type="button"
                    onClick={() => pick(null)}
                    className="rounded-lg border border-[var(--app-border)] px-3 py-2 text-xs font-semibold text-[var(--app-muted)]"
                >
                    Vider
                </button>
            </div>

            {open ? (
                <div className="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] p-1 shadow-lg">
                    {filtered.length > 0 ? filtered.map((option) => (
                        <button
                            key={option.id}
                            type="button"
                            className="block w-full rounded-md px-2 py-2 text-left text-sm text-[var(--app-text)] hover:bg-[var(--app-bg)]"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => pick(option)}
                        >
                            {option.label}
                        </button>
                    )) : (
                        <p className="px-2 py-2 text-xs text-[var(--app-muted)]">Aucune suggestion</p>
                    )}
                </div>
            ) : null}
        </div>
    );
}

export default function AdminAprevoirImportIndex({
    stats = {},
    driverMappingOptions = [],
    userMappingOptions = [],
    vehicleMappingOptions = [],
    driverIdEntries = [],
    driverFreeEntries = [],
    vehicleEntries = [],
    createdByEntries = [],
    updatedByEntries = [],
    missingMappings = {},
    canImport = false,
    importReport = null,
}) {
    const form = useForm({
        user_mappings: [
            ...driverIdEntries.map((entry) => ({
                old_user_id: Number(entry.old_user_id),
                source_column: 'driver_id',
                target_type: entry.mapped_target_type ?? '',
                target_id: entry.mapped_target_id ?? '',
                new_user_id: entry.mapped_target_type === 'user' ? entry.mapped_target_id : '',
            })),
            ...createdByEntries.map((entry) => ({
                old_user_id: Number(entry.old_user_id),
                source_column: 'created_by',
                target_type: entry.mapped_target_type ?? '',
                target_id: entry.mapped_target_id ?? '',
                new_user_id: entry.mapped_target_type === 'user' ? entry.mapped_target_id : '',
            })),
            ...updatedByEntries.map((entry) => ({
                old_user_id: Number(entry.old_user_id),
                source_column: 'updated_by',
                target_type: entry.mapped_target_type ?? '',
                target_id: entry.mapped_target_id ?? '',
                new_user_id: entry.mapped_target_type === 'user' ? entry.mapped_target_id : '',
            })),
        ],
        vehicle_mappings: vehicleEntries.map((entry) => ({
            old_vehicle_id: Number(entry.old_vehicle_id),
            old_vehicle_free: entry.vehicle_free ?? '',
            target_type: entry.mapped_target_type ?? '',
            target_id: entry.mapped_target_id ?? '',
            new_vehicle_id: entry.mapped_target_type === 'vehicle' ? entry.mapped_target_id : '',
        })),
        driver_free_mappings: driverFreeEntries.map((entry) => ({
            old_driver_free: entry.old_driver_free ?? '',
            target_type: entry.mapped_target_type ?? '',
            target_id: entry.mapped_target_id ?? '',
            new_user_id: entry.mapped_target_type === 'user' ? entry.mapped_target_id : '',
        })),
    });

    const updateUserMapping = (oldUserId, sourceColumn, target) => {
        form.setData(
            'user_mappings',
            form.data.user_mappings.map((row) => (
                Number(row.old_user_id) === Number(oldUserId) && row.source_column === sourceColumn
                    ? {
                        ...row,
                        target_type: target?.target_type ?? '',
                        target_id: target?.target_id ?? '',
                        new_user_id: target?.target_type === 'user' ? Number(target.target_id) : '',
                    }
                    : row
            )),
        );
    };

    const updateVehicleMapping = (oldVehicleId, target) => {
        form.setData(
            'vehicle_mappings',
            form.data.vehicle_mappings.map((row) => (
                Number(row.old_vehicle_id) === Number(oldVehicleId)
                    ? {
                        ...row,
                        target_type: target?.target_type ?? '',
                        target_id: target?.target_id ?? '',
                        new_vehicle_id: target?.target_type === 'vehicle' ? Number(target.target_id) : '',
                    }
                    : row
            )),
        );
    };

    const updateDriverFreeMapping = (oldDriverFree, target) => {
        form.setData(
            'driver_free_mappings',
            form.data.driver_free_mappings.map((row) => (
                row.old_driver_free === oldDriverFree
                    ? {
                        ...row,
                        target_type: target?.target_type ?? '',
                        target_id: target?.target_id ?? '',
                        new_user_id: target?.target_type === 'user' ? Number(target.target_id) : '',
                    }
                    : row
            )),
        );
    };

    const submit = (event) => {
        event.preventDefault();

        form.put(route('admin.aprevoir-import.mappings.update'), {
            preserveScroll: true,
        });
    };

    const loadLegacyData = () => {
        router.post(route('admin.aprevoir-import.load-legacy'), {}, {
            preserveScroll: true,
        });
    };

    const runImport = (dryRun) => {
        router.post(route('admin.aprevoir-import.import'), {
            dry_run: dryRun,
        }, {
            preserveScroll: true,
        });
    };

    const findUserMapping = (oldUserId, sourceColumn) => (
        form.data.user_mappings.find((row) => (
            Number(row.old_user_id) === Number(oldUserId) && row.source_column === sourceColumn
        ))
    );

    const findVehicleMapping = (oldVehicleId) => (
        form.data.vehicle_mappings.find((row) => Number(row.old_vehicle_id) === Number(oldVehicleId))
    );

    const findDriverFreeMapping = (oldDriverFree) => (
        form.data.driver_free_mappings.find((row) => row.old_driver_free === oldDriverFree)
    );

    return (
        <AdminLayout title="Admin - Import À prévoir">
            <Head title="Admin - Import À prévoir" />

            <div className="space-y-4">
                <header className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h1 className="text-xl font-black tracking-tight text-[var(--app-text)] sm:text-2xl">Import À prévoir</h1>
                    <p className="mt-1 text-sm text-[var(--app-muted)]">
                        Préparation des correspondances entre l&apos;ancienne table <code>ldt_plan</code> et les données actuelles.
                    </p>
                    <div className="mt-3">
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={loadLegacyData}
                                className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)]"
                            >
                                Charger les données legacy
                            </button>
                            <button
                                type="button"
                                onClick={() => runImport(true)}
                                className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)]"
                            >
                                Tester l'import
                            </button>
                            <button
                                type="button"
                                onClick={() => runImport(false)}
                                className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)]"
                            >
                                Importer réellement
                            </button>
                        </div>
                    </div>
                </header>

                {importReport ? (
                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                        <h2 className="text-base font-semibold text-[var(--app-text)]">
                            Rapport {importReport.dry_run ? 'dry-run' : 'import'}
                        </h2>
                        <div className="mt-2 grid gap-2 text-sm text-[var(--app-text)] sm:grid-cols-2 lg:grid-cols-4">
                            <p>Batch: <span className="font-semibold">{importReport.batch_id}</span></p>
                            <p>En attente: <span className="font-semibold">{importReport.pending_total ?? 0}</span></p>
                            <p>Importables: <span className="font-semibold">{importReport.importable_count ?? 0}</span></p>
                            <p>Importées: <span className="font-semibold">{importReport.imported_count ?? 0}</span></p>
                        </div>
                        {(importReport.error_count ?? 0) > 0 ? (
                            <div className="mt-3">
                                <p className="text-sm font-semibold text-[var(--app-text)]">
                                    Erreurs ({importReport.error_count})
                                </p>
                                <ul className="mt-1 space-y-1 text-xs text-[var(--app-muted)]">
                                    {(importReport.errors ?? []).map((error, index) => (
                                        <li key={`${error.row_id}-${index}`}>
                                            Ligne #{error.row_id}
                                            {error.legacy_id ? ` (legacy #${error.legacy_id})` : ''}: {error.error}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                    </section>
                ) : null}

                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3">
                        <p className="text-xs uppercase tracking-wide text-[var(--app-muted)]">Lignes totales</p>
                        <p className="mt-1 text-xl font-bold text-[var(--app-text)]">{stats.total_rows ?? 0}</p>
                    </div>
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3">
                        <p className="text-xs uppercase tracking-wide text-[var(--app-muted)]">À importer</p>
                        <p className="mt-1 text-xl font-bold text-[var(--app-text)]">{stats.pending_rows ?? 0}</p>
                    </div>
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-3">
                        <p className="text-xs uppercase tracking-wide text-[var(--app-muted)]">Déjà importées</p>
                        <p className="mt-1 text-xl font-bold text-[var(--app-text)]">{stats.imported_rows ?? 0}</p>
                    </div>
                </section>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                    <h2 className="text-base font-semibold text-[var(--app-text)]">Mappings manquants</h2>
                    {canImport ? (
                        <p className="mt-2 text-sm text-[var(--app-text)]">Tous les mappings sont complets.</p>
                    ) : (
                        <div className="mt-3 space-y-3 text-sm text-[var(--app-text)]">
                            {(missingMappings.driver_id ?? []).length > 0 ? (
                                <div>
                                    <p className="font-semibold">driver_id</p>
                                    <p className="mt-1 text-[var(--app-muted)]">
                                        {(missingMappings.driver_id ?? []).map((entry) => entry.old_user_id).join(', ')}
                                    </p>
                                </div>
                            ) : null}
                            {(missingMappings.driver_free ?? []).length > 0 ? (
                                <div>
                                    <p className="font-semibold">driver_free</p>
                                    <p className="mt-1 text-[var(--app-muted)]">
                                        {(missingMappings.driver_free ?? []).map((entry) => entry.old_driver_free).join(', ')}
                                    </p>
                                </div>
                            ) : null}
                            {(missingMappings.vehicle_id ?? []).length > 0 ? (
                                <div>
                                    <p className="font-semibold">vehicle_id</p>
                                    <p className="mt-1 text-[var(--app-muted)]">
                                        {(missingMappings.vehicle_id ?? []).map((entry) => entry.old_vehicle_id).join(', ')}
                                    </p>
                                </div>
                            ) : null}
                            {(missingMappings.created_by ?? []).length > 0 ? (
                                <div>
                                    <p className="font-semibold">created_by</p>
                                    <p className="mt-1 text-[var(--app-muted)]">
                                        {(missingMappings.created_by ?? []).map((entry) => entry.old_user_id).join(', ')}
                                    </p>
                                </div>
                            ) : null}
                            {(missingMappings.updated_by ?? []).length > 0 ? (
                                <div>
                                    <p className="font-semibold">updated_by</p>
                                    <p className="mt-1 text-[var(--app-muted)]">
                                        {(missingMappings.updated_by ?? []).map((entry) => entry.old_user_id).join(', ')}
                                    </p>
                                </div>
                            ) : null}
                        </div>
                    )}
                    <p className="mt-3 text-xs text-[var(--app-muted)]">
                        canImport = {canImport ? 'true' : 'false'}
                    </p>
                </section>

                <form className="space-y-4" onSubmit={submit}>
                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                        <h2 className="text-base font-semibold text-[var(--app-text)]">Correspondance driver_id</h2>
                        <div className="mt-3 space-y-2">
                            {driverIdEntries.map((entry) => {
                                const row = findUserMapping(entry.old_user_id, 'driver_id');

                                return (
                                    <div key={entry.old_user_id} className="grid gap-2 rounded-xl border border-[var(--app-border)] p-3 md:grid-cols-2">
                                        <p className="text-sm text-[var(--app-text)]">
                                            Ancien driver_id : <span className="font-semibold">{entry.old_user_id}</span>
                                            {entry.driver_free ? ` (${entry.driver_free})` : ''}
                                        </p>
                                        <MappingAutocomplete
                                            options={driverMappingOptions}
                                            targetType={row?.target_type ?? ''}
                                            targetId={row?.target_id ?? ''}
                                            onChange={(target) => updateUserMapping(entry.old_user_id, 'driver_id', target)}
                                            placeholder="Rechercher un personnel, transporteur ou dépôt..."
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                        <h2 className="text-base font-semibold text-[var(--app-text)]">Correspondance driver_free</h2>
                        <div className="mt-3 space-y-2">
                            {driverFreeEntries.map((entry) => {
                                const row = findDriverFreeMapping(entry.old_driver_free);

                                return (
                                    <div key={entry.old_driver_free} className="grid gap-2 rounded-xl border border-[var(--app-border)] p-3 md:grid-cols-2">
                                        <p className="text-sm text-[var(--app-text)]">
                                            Ancien driver_free : <span className="font-semibold">{entry.old_driver_free}</span>
                                        </p>
                                        <MappingAutocomplete
                                            options={driverMappingOptions}
                                            targetType={row?.target_type ?? ''}
                                            targetId={row?.target_id ?? ''}
                                            onChange={(target) => updateDriverFreeMapping(entry.old_driver_free, target)}
                                            placeholder="Rechercher un personnel, transporteur ou dépôt..."
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                        <h2 className="text-base font-semibold text-[var(--app-text)]">Correspondance vehicle_id</h2>
                        <div className="mt-3 space-y-2">
                            {vehicleEntries.map((entry) => {
                                const row = findVehicleMapping(entry.old_vehicle_id);

                                return (
                                    <div key={entry.old_vehicle_id} className="grid gap-2 rounded-xl border border-[var(--app-border)] p-3 md:grid-cols-2">
                                        <p className="text-sm text-[var(--app-text)]">
                                            Ancien vehicle_id : <span className="font-semibold">{entry.old_vehicle_id}</span>
                                            {entry.vehicle_free ? ` (${entry.vehicle_free})` : ''}
                                        </p>
                                        <MappingAutocomplete
                                            options={vehicleMappingOptions}
                                            targetType={row?.target_type ?? ''}
                                            targetId={row?.target_id ?? ''}
                                            onChange={(target) => updateVehicleMapping(entry.old_vehicle_id, target)}
                                            placeholder="Rechercher un camion, une remorque, un ensemble PL ou un VL..."
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                        <h2 className="text-base font-semibold text-[var(--app-text)]">Correspondance created_by</h2>
                        <div className="mt-3 space-y-2">
                            {createdByEntries.map((entry) => {
                                const row = findUserMapping(entry.old_user_id, 'created_by');

                                return (
                                    <div key={`created-${entry.old_user_id}`} className="grid gap-2 rounded-xl border border-[var(--app-border)] p-3 md:grid-cols-2">
                                        <p className="text-sm text-[var(--app-text)]">
                                            Ancien created_by : <span className="font-semibold">{entry.old_user_id}</span>
                                        </p>
                                        <MappingAutocomplete
                                            options={userMappingOptions}
                                            targetType={row?.target_type ?? ''}
                                            targetId={row?.target_id ?? ''}
                                            onChange={(target) => updateUserMapping(entry.old_user_id, 'created_by', target)}
                                            placeholder="Rechercher un utilisateur..."
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4">
                        <h2 className="text-base font-semibold text-[var(--app-text)]">Correspondance updated_by</h2>
                        <div className="mt-3 space-y-2">
                            {updatedByEntries.map((entry) => {
                                const row = findUserMapping(entry.old_user_id, 'updated_by');

                                return (
                                    <div key={`updated-${entry.old_user_id}`} className="grid gap-2 rounded-xl border border-[var(--app-border)] p-3 md:grid-cols-2">
                                        <p className="text-sm text-[var(--app-text)]">
                                            Ancien updated_by : <span className="font-semibold">{entry.old_user_id}</span>
                                        </p>
                                        <MappingAutocomplete
                                            options={userMappingOptions}
                                            targetType={row?.target_type ?? ''}
                                            targetId={row?.target_id ?? ''}
                                            onChange={(target) => updateUserMapping(entry.old_user_id, 'updated_by', target)}
                                            placeholder="Rechercher un utilisateur..."
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <div className="pt-1">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg border border-[var(--app-border)] px-4 py-2 text-sm font-semibold text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Enregistrer les correspondances
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
