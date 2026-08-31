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

    it('espace les trois icônes régulièrement dans un seul conteneur centré', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({ can_update: true, can_delete: true, can_point: true })}
            />,
        );

        const icons = ['Pointer', 'Modifier', 'Supprimer'].map((name) =>
            screen.getByRole('button', { name }),
        );

        // Sœurs d'un même flex : une seule gouttière gouverne les deux écarts,
        // qui ne peuvent donc pas différer.
        const [row] = new Set(icons.map((node) => node.parentElement));
        expect(row).toBeDefined();
        icons.forEach((node) => expect(node.parentElement).toBe(row));

        expect(row.className).toContain('gap-2');
        expect(row.className).toContain('justify-center');
        expect(row.className).not.toContain('ml-auto');

        // Et elles se suivent dans l'ordre attendu.
        expect(Array.from(row.children)).toEqual(icons);
    });

    it('garde les icônes sur une seule ligne quand une permission manque', () => {
        const { rerender } = render(
            <MaintenanceTaskCard task={baseTask({ can_point: true, can_delete: true })} />,
        );

        let row = screen.getByRole('button', { name: 'Pointer' }).parentElement;
        expect(Array.from(row.children)).toHaveLength(2);
        expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument();

        rerender(<MaintenanceTaskCard task={baseTask({ can_update: true })} />);

        row = screen.getByRole('button', { name: 'Modifier' }).parentElement;
        expect(Array.from(row.children)).toHaveLength(1);
        expect(screen.queryByRole('button', { name: 'Pointer' })).not.toBeInTheDocument();
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

describe('ligne de suivi', () => {
    function trackingRow() {
        return screen.getByText(/Créé par|Demandé par/).closest('div');
    }

    it('place les auteurs à gauche et la trace de pointage à droite', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({
                    created_by: 'Nathan Bidet',
                    updated_by: 'Alice Blanchet',
                    partially_pointed: true,
                    partially_pointed_by: 'Alice Blanchet',
                    partially_pointed_at_label: '31/08/2026 à 14:46',
                })}
            />,
        );

        const row = trackingRow();

        expect(row.className).toContain('justify-between');
        expect(row.className).toContain('flex-wrap');

        // Les deux blocs sont bien deux enfants du même conteneur, donc sur la
        // même ligne tant que la place le permet.
        expect(row.children).toHaveLength(2);
        expect(row.children[0]).toHaveTextContent('Créé par Nathan Bidet • Modifié par Alice Blanchet');
        expect(row.children[1]).toHaveTextContent('Partiel par Alice Blanchet le 31/08/2026 à 14:46');
    });

    it('applique la même logique au pointage définitif', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({
                    created_by: 'Nathan Bidet',
                    pointed: true,
                    pointed_by: 'Alice Blanchet',
                    pointed_at_label: '31/08/2026 à 14:46',
                })}
            />,
        );

        expect(trackingRow().children[1])
            .toHaveTextContent('Pointé par Alice Blanchet le 31/08/2026 à 14:46');
    });

    it('ne réserve aucun espace à droite sans pointage', () => {
        render(<MaintenanceTaskCard task={baseTask({ created_by: 'Nathan Bidet' })} />);

        const row = trackingRow();

        expect(row.children).toHaveLength(1);
        expect(row).toHaveTextContent('Créé par Nathan Bidet');
        expect(row).not.toHaveTextContent(/Partiel par|Pointé par|Premier pointage/);
    });

    it('empile les traces à droite quand il y en a plusieurs', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({
                    created_by: 'Nathan Bidet',
                    partially_pointed: true,
                    partially_pointed_by: 'Alice Blanchet',
                    pointed: true,
                    pointed_by: 'Nathan Bidet',
                    first_pointed_on_label: '04/09/2026',
                })}
            />,
        );

        const right = trackingRow().children[1];

        expect(right.children).toHaveLength(3);
        expect(right).toHaveTextContent('Partiel par Alice Blanchet');
        expect(right).toHaveTextContent('Pointé par Nathan Bidet');
        expect(right).toHaveTextContent('Premier pointage le 04/09/2026');
    });

    it('n’affiche pas « Modifié par » quand l’auteur n’a pas changé', () => {
        render(
            <MaintenanceTaskCard
                task={baseTask({ created_by: 'Nathan Bidet', updated_by: 'Nathan Bidet' })}
            />,
        );

        expect(trackingRow()).not.toHaveTextContent('Modifié par');
    });
});
