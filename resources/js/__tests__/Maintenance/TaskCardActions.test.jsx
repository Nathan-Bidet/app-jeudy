import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

import MaintenanceTaskCard from '@/Components/Maintenance/TaskCard';

/**
 * Regroupement des actions dans la colonne de droite.
 *
 * La disposition change, pas les règles : chaque bouton reste conditionné
 * exactement aux mêmes drapeaux calculés par le serveur.
 */

function baseTask(overrides = {}) {
    return {
        id: 1,
        task: 'Révision du compresseur',
        date_label: '10/09/2026',
        comment: null,
        comment_hidden: false,
        place: null,
        partially_pointed: false,
        pointed: false,
        can_update: false,
        can_delete: false,
        can_partial_point: false,
        can_point: false,
        can_edit_pointing_date: false,
        first_pointed_on_label: null,
        created_by: 'Léa Martin',
        ...overrides,
    };
}

function actionNames() {
    return screen.getAllByRole('button').map((node) => node.textContent.trim());
}

function accessibleNames() {
    return screen
        .getAllByRole('button')
        .map((node) => node.getAttribute('aria-label') || node.textContent.trim());
}

afterEach(cleanup);

describe('colonne d’actions de la carte', () => {
    it('réunit Pointer, Modifier et Supprimer sur la première ligne', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({
                    can_update: true,
                    can_delete: true,
                    can_partial_point: true,
                    can_point: true,
                    can_edit_pointing_date: true,
                })}
            />,
        );

        // Les trois boutons de tête n'ont plus de texte : leur nom accessible
        // vient de aria-label, et reste donc identifiable.
        for (const name of ['Pointer', 'Modifier', 'Supprimer']) {
            expect(screen.getByRole('button', { name })).toHaveTextContent('');
        }

        expect(accessibleNames()).toEqual(['Pointer', 'Modifier', 'Supprimer', 'Partiel', 'Dater']);
        expect(actionNames()).toEqual(['', '', '', 'Partiel', 'Dater']);
    });

    it('reflète l’état du pointage définitif sur son icône', () => {
        const { rerender } = render(<MaintenanceTaskCard task={baseTask({ can_point: true })} />);

        expect(screen.getByRole('button', { name: 'Pointer' })).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByRole('button', { name: 'Pointer' }))
            .toHaveAttribute('title', 'Pointer définitivement');

        rerender(<MaintenanceTaskCard task={baseTask({ can_point: true, pointed: true })} />);

        expect(screen.getByRole('button', { name: 'Pointer' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Pointer' }))
            .toHaveAttribute('title', 'Retirer le pointage définitif');
    });

    it('garde le libellé « Dater » et porte la date dans l’infobulle', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({ can_point: true, can_edit_pointing_date: true, first_pointed_on_label: '04/09/2026' })}
            />,
        );

        const dater = screen.getByRole('button', { name: 'Dater' });

        expect(dater).toHaveAttribute('title', expect.stringContaining('04/09/2026'));
        // La valeur reste lisible dans l'historique, à gauche.
        expect(screen.getByText(/Premier pointage le 04\/09\/2026/)).toBeInTheDocument();
    });

    it('ne montre que le pointage partiel à la personne affectée', () => {
        render(<MaintenanceTaskCard task={baseTask({ can_partial_point: true })} />);

        expect(actionNames()).toEqual(['Partiel']);
    });

    it('montre au responsable l’état du partiel en lecture seule', () => {
        render(<MaintenanceTaskCard task={baseTask({ can_point: true, partially_pointed: true })} />);

        // L'état du partiel n'est pas un bouton : il ne peut pas être basculé.
        expect(accessibleNames()).toEqual(['Pointer']);
        expect(screen.getByTitle(/Pointé partiellement par la personne affectée/i)).toBeInTheDocument();
    });

    it('n’affiche aucune action à un simple lecteur', () => {
        render(<MaintenanceTaskCard task={baseTask()} />);

        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(screen.getByText('Révision du compresseur')).toBeInTheDocument();
    });

    it('déclenche les bons gestionnaires sans changer de comportement', () => {
        const onEdit = vi.fn();
        const onDelete = vi.fn();
        const onTogglePartialPoint = vi.fn();
        const onTogglePoint = vi.fn();
        const onEditPointingDate = vi.fn();

        const task = baseTask({
            can_update: true,
            can_delete: true,
            can_partial_point: true,
            can_point: true,
            can_edit_pointing_date: true,
        });

        render(
            <MaintenanceTaskCard
                task={task}
                onEdit={onEdit}
                onDelete={onDelete}
                onTogglePartialPoint={onTogglePartialPoint}
                onTogglePoint={onTogglePoint}
                onEditPointingDate={onEditPointingDate}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
        fireEvent.click(screen.getByRole('button', { name: 'Supprimer' }));
        fireEvent.click(screen.getByRole('button', { name: 'Partiel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Pointer' }));
        fireEvent.click(screen.getByRole('button', { name: 'Dater' }));

        expect(onEdit).toHaveBeenCalledWith(task);
        expect(onDelete).toHaveBeenCalledWith(task);
        expect(onTogglePartialPoint).toHaveBeenCalledWith(task, true);
        expect(onTogglePoint).toHaveBeenCalledWith(task, true);
        expect(onEditPointingDate).toHaveBeenCalledWith(task);
    });

    it('désactive les actions pendant un enregistrement en cours', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({ can_delete: true, can_partial_point: true, can_point: true })}
                deleting
                saving
            />,
        );

        expect(screen.getByRole('button', { name: 'Supprimer' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Partiel' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Pointer' })).toBeDisabled();
    });
});
