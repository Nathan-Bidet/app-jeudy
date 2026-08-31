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

afterEach(cleanup);

describe('colonne d’actions de la carte', () => {
    it('empile les cinq actions dans l’ordre attendu', () => {
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

        expect(actionNames()).toEqual([
            'Modifier',
            'Supprimer',
            'Pointage partiel',
            'Pointage définitif',
            'Dater le 1er pointage',
        ]);
    });

    it('affiche la date du premier pointage à la place de l’invite quand elle existe', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({ can_point: true, can_edit_pointing_date: true, first_pointed_on_label: '04/09/2026' })}
            />,
        );

        expect(actionNames()).toContain('1er pointage 04/09/2026');
        expect(actionNames()).not.toContain('Dater le 1er pointage');
    });

    it('ne montre que le pointage partiel à la personne affectée', () => {
        render(<MaintenanceTaskCard task={baseTask({ can_partial_point: true })} />);

        expect(actionNames()).toEqual(['Pointage partiel']);
    });

    it('montre au responsable l’état du partiel en lecture seule', () => {
        render(<MaintenanceTaskCard task={baseTask({ can_point: true, partially_pointed: true })} />);

        // L'état du partiel n'est pas un bouton : il ne peut pas être basculé.
        expect(actionNames()).toEqual(['Pointage définitif']);
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
        fireEvent.click(screen.getByRole('button', { name: 'Pointage partiel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Pointage définitif' }));
        fireEvent.click(screen.getByRole('button', { name: 'Dater le 1er pointage' }));

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
        expect(screen.getByRole('button', { name: 'Pointage partiel' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Pointage définitif' })).toBeDisabled();
    });
});
