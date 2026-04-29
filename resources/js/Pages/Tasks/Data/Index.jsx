import AppLayout from '@/Layouts/AppLayout';
import SideMenu from '@/Components/Tasks/Data/SideMenu';
import JeudyPersonnelTable from '@/Components/Tasks/Data/JeudyPersonnelTable';
import TransportersTable from '@/Components/Tasks/Data/TransportersTable';
import DepotsTable from '@/Components/Tasks/Data/DepotsTable';
import VehicleEntitiesTable from '@/Components/Tasks/Data/VehicleEntitiesTable';
import { Head, router } from '@inertiajs/react';
import { Database } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function EmptySection({ message }) {
    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-5 shadow-sm">
            <div className="flex items-start gap-3 rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4">
                <span className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]">
                    <Database className="h-4 w-4" strokeWidth={2.2} />
                </span>
                <p className="text-sm text-[var(--app-muted)]">{message}</p>
            </div>
        </section>
    );
}

export default function TaskDataIndex({
    sections = [],
    active_section,
    permissions = {},
    lookups = {},
    jeudy_personnel = [],
    transporters = [],
    depots = [],
    vehicle_sections = {},
    vehicle_form_options = {},
}) {
    const availableSections = useMemo(
        () => sections.filter((section) => Boolean(section?.can_view)),
        [sections],
    );

    const fallbackSection = availableSections[0]?.key ?? null;
    const [currentSection, setCurrentSection] = useState(active_section || fallbackSection);

    useEffect(() => {
        if (!availableSections.some((section) => section.key === currentSection)) {
            setCurrentSection(active_section || fallbackSection);
        }
    }, [active_section, availableSections, currentSection, fallbackSection]);

    const selectSection = (nextSection) => {
        if (!nextSection || nextSection === currentSection) {
            return;
        }

        setCurrentSection(nextSection);

        router.get(
            route('task.data.index', { section: nextSection }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: [
                    'sections',
                    'active_section',
                    'permissions',
                    'lookups',
                    'jeudy_personnel',
                    'transporters',
                    'depots',
                    'vehicle_sections',
                    'vehicle_form_options',
                ],
            },
        );
    };

    const renderContent = () => {
        if (currentSection === 'jeudy') {
            if (!permissions.jeudy_view) {
                return <EmptySection message="Aucun accès à la section Personnels Jeudy." />;
            }

            return (
                <JeudyPersonnelTable
                    users={jeudy_personnel}
                    depots={lookups.depots ?? []}
                    canManage={Boolean(permissions.jeudy_manage)}
                />
            );
        }

        if (currentSection === 'transporters') {
            if (!permissions.transporters_view) {
                return <EmptySection message="Aucun accès à la section Transporteurs." />;
            }

            return (
                <TransportersTable
                    transporters={transporters}
                    canManage={Boolean(permissions.transporters_manage)}
                />
            );
        }

        if (currentSection === 'depots') {
            if (!permissions.depots_view) {
                return <EmptySection message="Aucun accès à la section Dépôts." />;
            }

            return <DepotsTable depots={depots} canManage={Boolean(permissions.depots_manage)} />;
        }

        if (currentSection === 'camions') {
            return (
                <VehicleEntitiesTable
                    sectionKey="camions"
                    title="Camions"
                    hint="Véhicules des types tracteur et porteur."
                    vehicles={vehicle_sections.camions ?? []}
                    canManage={Boolean(permissions.vehicles_manage)}
                    formOptions={vehicle_form_options}
                />
            );
        }

        if (currentSection === 'remorques') {
            return (
                <VehicleEntitiesTable
                    sectionKey="remorques"
                    title="Remorques"
                    hint="Véhicules de type benne."
                    vehicles={vehicle_sections.remorques ?? []}
                    canManage={Boolean(permissions.vehicles_manage)}
                    formOptions={vehicle_form_options}
                />
            );
        }

        if (currentSection === 'ensembles_pl') {
            return (
                <VehicleEntitiesTable
                    sectionKey="ensembles_pl"
                    title="Ensemble PL"
                    hint="Ensembles PL avec camion et remorque associée."
                    vehicles={vehicle_sections.ensembles_pl ?? []}
                    canManage={Boolean(permissions.vehicles_manage)}
                    formOptions={vehicle_form_options}
                />
            );
        }

        if (currentSection === 'vl') {
            return (
                <VehicleEntitiesTable
                    sectionKey="vl"
                    title="VL"
                    hint="Véhicules légers (VL)."
                    vehicles={vehicle_sections.vl ?? []}
                    canManage={Boolean(permissions.vehicles_manage)}
                    formOptions={vehicle_form_options}
                />
            );
        }

        return <EmptySection message="Sélectionner une section." />;
    };

    return (
        <AppLayout title="Données - Tâches">
            <Head title="Données - Tâches" />

            <div className="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start">
                <div className={classNames('lg:sticky lg:top-24')}>
                    <SideMenu
                        sections={availableSections}
                        activeSection={currentSection}
                        onChange={selectSection}
                    />
                </div>

                <div className="space-y-4">{renderContent()}</div>
            </div>
        </AppLayout>
    );
}
