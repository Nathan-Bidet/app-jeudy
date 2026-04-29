import AdminLayout from '@/Layouts/AdminLayout';
import Tabs from '@/Pages/Admin/Entities/Components/Tabs';
import DepotsPanel from '@/Pages/Admin/Entities/Components/DepotsPanel';
import GaragesPanel from '@/Pages/Admin/Entities/Components/GaragesPanel';
import VehiclesPanel from '@/Pages/Admin/Entities/Components/VehiclesPanel';
import VehicleTypesPanel from '@/Pages/Admin/Entities/Components/VehicleTypesPanel';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const TAB_ITEMS = [
    { key: 'vehicle-types', label: 'Types véhicules' },
    { key: 'vehicles', label: 'Véhicules' },
    { key: 'depots', label: 'Dépôts' },
    { key: 'garages', label: 'Garages' },
];

export default function AdminEntitiesIndex({
    vehicleTypes = [],
    vehicles = [],
    depots = [],
    garages = [],
    lookups = {},
}) {
    const urlState = useMemo(() => {
        if (typeof window === 'undefined') {
            return { tab: 'vehicle-types', depotId: '' };
        }

        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab') ?? 'vehicle-types';
        const depotId = params.get('depot_id') ?? '';
        const allowedTabs = new Set(TAB_ITEMS.map((item) => item.key));

        return {
            tab: allowedTabs.has(tab) ? tab : 'vehicle-types',
            depotId,
        };
    }, []);

    const [activeTab, setActiveTab] = useState(urlState.tab);

    return (
        <AdminLayout title="Admin - Entités">
            <Head title="Admin - Entités" />

            <div className="space-y-5">
                <Tabs tabs={TAB_ITEMS} active={activeTab} onChange={setActiveTab} />

                {activeTab === 'vehicle-types' ? (
                    <VehicleTypesPanel vehicleTypes={vehicleTypes} />
                ) : null}

                {activeTab === 'vehicles' ? (
                    <VehiclesPanel
                        vehicles={vehicles}
                        vehicleTypes={lookups.vehicle_types ?? []}
                        depots={depots}
                        garages={garages}
                        users={lookups.users ?? []}
                        initialDepotFilter={urlState.depotId}
                    />
                ) : null}

                {activeTab === 'depots' ? (
                    <DepotsPanel depots={depots} />
                ) : null}

                {activeTab === 'garages' ? (
                    <GaragesPanel garages={garages} />
                ) : null}
            </div>
        </AdminLayout>
    );
}
