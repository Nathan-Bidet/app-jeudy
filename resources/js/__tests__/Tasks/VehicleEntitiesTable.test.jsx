import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Colonne "Chauffeur" du tableau Tâches > Données (Camions/Remorques/Ensemble
 * PL/VL). La donnée provient directement de driver_name, déjà fournie par
 * TasksDataController::index() via la relation Vehicle::driver() — même
 * source que Admin > Entités, aucune donnée dupliquée ni requête par ligne.
 */

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        errors: {},
        processing: false,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        put: vi.fn(),
        post: vi.fn(),
    }),
}));

import VehicleEntitiesTable from '@/Components/Tasks/Data/VehicleEntitiesTable';

beforeEach(() => {
    global.route = vi.fn((name, params) => `/${name}/${params ?? ''}`);
});

afterEach(() => {
    cleanup();
});

function vehicle(overrides = {}) {
    return {
        id: 1,
        display_label: 'Camion 1',
        registration: 'AA-123-BB',
        type_label: 'Camion',
        depot_name: 'Dépôt Nord',
        driver_user_id: null,
        driver_name: '',
        code_zeendoc: '',
        garage_name: '',
        is_active: true,
        ...overrides,
    };
}

describe('VehicleEntitiesTable — colonne Chauffeur', () => {
    it('places the "Chauffeur" column header between "Type" and "Dépôt"', () => {
        render(
            <VehicleEntitiesTable
                title="Camions"
                hint=""
                vehicles={[vehicle()]}
                sectionKey="camions"
                canManage={false}
                formOptions={{}}
            />,
        );

        const headers = screen.getAllByRole('columnheader').map((th) => th.textContent);
        const typeIndex = headers.indexOf('Type');
        const driverIndex = headers.indexOf('Chauffeur');
        const depotIndex = headers.indexOf('Dépôt');

        expect(typeIndex).toBeGreaterThanOrEqual(0);
        expect(driverIndex).toBe(typeIndex + 1);
        expect(depotIndex).toBe(driverIndex + 1);
    });

    it('displays the attached driver name from driver_name', () => {
        render(
            <VehicleEntitiesTable
                title="Camions"
                hint=""
                vehicles={[vehicle({ driver_user_id: 42, driver_name: 'Adrien Rabet' })]}
                sectionKey="camions"
                canManage={false}
                formOptions={{}}
            />,
        );

        const row = screen.getByText('Camion 1').closest('tr');
        expect(within(row).getByText('Adrien Rabet')).toBeInTheDocument();
    });

    it('displays "-" when no driver is attached', () => {
        render(
            <VehicleEntitiesTable
                title="Camions"
                hint=""
                vehicles={[vehicle({ driver_user_id: null, driver_name: '' })]}
                sectionKey="camions"
                canManage={false}
                formOptions={{}}
            />,
        );

        const row = screen.getByText('Camion 1').closest('tr');
        const cells = within(row).getAllByRole('cell');
        // Nom, Immatriculation, Type, Chauffeur, Dépôt...
        expect(cells[3].textContent).toBe('-');
    });

    it.each(['camions', 'remorques', 'ensembles_pl', 'vl'])(
        'renders the driver name for the %s section',
        (sectionKey) => {
            render(
                <VehicleEntitiesTable
                    title="Section"
                    hint=""
                    vehicles={[vehicle({ display_label: 'Élément 1', driver_name: 'Jean Dupont' })]}
                    sectionKey={sectionKey}
                    canManage={false}
                    formOptions={{}}
                />,
            );

            const row = screen.getByText('Élément 1').closest('tr');
            expect(within(row).getByText('Jean Dupont')).toBeInTheDocument();
            cleanup();
        },
    );

    it('filters rows by driver name through the existing search input, consistently with other displayed columns', () => {
        render(
            <VehicleEntitiesTable
                title="Camions"
                hint=""
                vehicles={[
                    vehicle({ id: 1, display_label: 'Camion 1', driver_name: 'Adrien Rabet' }),
                    vehicle({ id: 2, display_label: 'Camion 2', driver_name: 'Marie Curie' }),
                ]}
                sectionKey="camions"
                canManage={false}
                formOptions={{}}
            />,
        );

        expect(screen.getByText('Camion 1')).toBeInTheDocument();
        expect(screen.getByText('Camion 2')).toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText('Rechercher un véhicule...'), {
            target: { value: 'Marie' },
        });

        expect(screen.queryByText('Camion 1')).not.toBeInTheDocument();
        expect(screen.getByText('Camion 2')).toBeInTheDocument();
    });
});
