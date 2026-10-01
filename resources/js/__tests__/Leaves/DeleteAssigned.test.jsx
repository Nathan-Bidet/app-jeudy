import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import '@testing-library/jest-dom';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Pages/Leaves/Components/LeaveRequestForm', () => ({ default: () => null }));
vi.mock('@/Components/LeaveRequestDetailModal', () => ({ default: () => null }));
vi.mock('@/Components/Validation/ValidatorDecisions', () => ({ default: () => null }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { delete: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));

import { router } from '@inertiajs/react';
import LeavesIndex from '@/Pages/Leaves/Index';

const leave = (overrides) => ({
    id: 1,
    target_user_id: 5,
    target_label: 'Paul Martin',
    leave_type_label: 'Congés payés',
    start_at: '2026-07-06',
    end_at: '2026-07-10',
    status: 'pending',
    status_label: 'En attente',
    can_delete: false,
    ...overrides,
});

beforeEach(() => {
    window.route = (name, id) => `/${name}/${id ?? ''}`;
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    router.delete.mockClear();
});
afterEach(() => cleanup());

const renderPage = (props) => render(<LeavesIndex {...props} />);

describe('Congés — suppression pour les collaborateurs affectés', () => {
    it('n’affiche aucun bouton ni section sans droit', () => {
        renderPage({ myLeaveRequests: [leave({ can_delete: false })], assignedLeaveRequests: [] });

        expect(screen.queryByRole('button', { name: 'Supprimer' })).toBeNull();
        expect(screen.queryByTestId('assigned-leaves')).toBeNull();
    });

    it('affiche le bouton seulement sur les lignes supprimables', () => {
        renderPage({
            myLeaveRequests: [leave({ id: 1, can_delete: true }), leave({ id: 2, can_delete: false })],
        });

        expect(screen.getAllByRole('button', { name: 'Supprimer' })).toHaveLength(1);
    });

    it('liste les congés des collaborateurs affectés et distingue en attente / validé dans la confirmation', () => {
        renderPage({
            assignedLeaveRequests: [
                leave({ id: 10, status: 'pending', can_delete: true }),
                leave({ id: 11, status: 'approved', status_label: 'Validé', can_delete: true }),
            ],
        });

        const section = screen.getByTestId('assigned-leaves');
        const [pendingBtn, approvedBtn] = within(section).getAllByRole('button', { name: 'Supprimer' });

        fireEvent.click(pendingBtn);
        expect(window.confirm).toHaveBeenLastCalledWith(expect.stringContaining('demande de congé en attente de Paul Martin'));
        expect(router.delete).toHaveBeenLastCalledWith('/leaves.destroy/10');

        fireEvent.click(approvedBtn);
        expect(window.confirm).toHaveBeenLastCalledWith(expect.stringContaining('congé DÉJÀ VALIDÉ de Paul Martin'));
        expect(router.delete).toHaveBeenLastCalledWith('/leaves.destroy/11');
    });

    it('ne supprime rien si la confirmation est refusée', () => {
        window.confirm.mockReturnValue(false);
        renderPage({ assignedLeaveRequests: [leave({ can_delete: true })] });

        fireEvent.click(screen.getByRole('button', { name: 'Supprimer' }));
        expect(router.delete).not.toHaveBeenCalled();
    });
});
