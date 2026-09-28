import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import ValidatorDecisions, { ValidatorDecisionBadge } from '@/Components/Validation/ValidatorDecisions';

/**
 * Badges de décision individuelle des valideurs (heures et congés).
 * Le badge suit `decision` — la décision enregistrée pour le rang — et garde
 * toujours un texte explicite à côté de la couleur.
 */

afterEach(() => cleanup());

function badgeOf(level) {
    return within(screen.getByTestId(`validator-${level}`)).getByText(/Validé|Refusé|En attente/).closest('[data-decision]');
}

describe('ValidatorDecisionBadge', () => {
    it('rend « Validé » en vert', () => {
        render(<ValidatorDecisionBadge decision="approved" label="Validé" />);
        const badge = screen.getByText('Validé');

        expect(badge).toHaveAttribute('data-decision', 'approved');
        expect(badge.className).toContain('border-[#22c55e]');
        expect(badge.className).toContain('text-[#15803d]');
    });

    it('rend « Refusé » en rouge', () => {
        render(<ValidatorDecisionBadge decision="refused" label="Refusé" />);
        const badge = screen.getByText('Refusé');

        expect(badge).toHaveAttribute('data-decision', 'refused');
        expect(badge.className).toContain('border-[#ef4444]');
        expect(badge.className).toContain('text-[#b91c1c]');
    });

    it('rend « En attente » de façon distincte, même sans décision', () => {
        render(<ValidatorDecisionBadge decision={null} label="En attente" />);
        const badge = screen.getByText('En attente');

        expect(badge).toHaveAttribute('data-decision', 'pending');
        expect(badge.className).toContain('border-[#eab308]');
        expect(badge.className).not.toContain('#22c55e');
        expect(badge.className).not.toContain('#ef4444');
    });

    it('garde un texte explicite si le libellé manque', () => {
        render(<ValidatorDecisionBadge decision="refused" />);

        expect(screen.getByText('Refusé')).toBeInTheDocument();
    });
});

describe('ValidatorDecisions', () => {
    it('affiche des décisions différentes pour les deux valideurs, avec leur nom', () => {
        render(
            <ValidatorDecisions
                summary={[
                    { level: 1, decision: null, label: 'En attente', validator: { name: 'Alice Blanchet', state: 'active' } },
                    { level: 2, decision: 'refused', label: 'Refusé', validator: { name: 'Floriane Blanchet', state: 'active' } },
                ]}
            />,
        );

        expect(screen.getByTestId('validator-1')).toHaveTextContent('Valideur 1 :Alice BlanchetEn attente');
        expect(screen.getByTestId('validator-2')).toHaveTextContent('Valideur 2 :Floriane BlanchetRefusé');
        expect(badgeOf(1)).toHaveAttribute('data-decision', 'pending');
        expect(badgeOf(2)).toHaveAttribute('data-decision', 'refused');
    });

    it('suit la décision du rang, pas un statut global', () => {
        render(
            <ValidatorDecisions
                summary={[
                    { level: 1, decision: 'refused', label: 'Refusé' },
                    { level: 2, decision: 'approved', label: 'Validé' },
                ]}
            />,
        );

        expect(badgeOf(1)).toHaveAttribute('data-decision', 'refused');
        expect(badgeOf(2)).toHaveAttribute('data-decision', 'approved');
    });

    it('reste anonyme sans nom transmis', () => {
        render(<ValidatorDecisions summary={[{ level: 1, decision: 'approved', label: 'Validé' }]} />);

        expect(screen.getByTestId('validator-1')).toHaveTextContent(/^Valideur 1 :Validé$/);
    });

    it('laisse un nom très long passer à la ligne sans pousser le badge hors du cadre', () => {
        const longName = 'Marie-Christine de La Rochefoucauld-Montmorency-Laval';
        render(
            <ValidatorDecisions
                summary={[{ level: 1, decision: 'approved', label: 'Validé', validator: { name: longName, state: 'active' } }]}
            />,
        );

        const row = screen.getByTestId('validator-1');
        expect(row.className).toContain('flex-wrap');
        expect(screen.getByText(longName).className).toContain('break-words');
        expect(badgeOf(1).className).toContain('shrink-0');
    });

    it('ne rend rien sans résumé', () => {
        const { container } = render(<ValidatorDecisions summary={null} />);

        expect(container).toBeEmptyDOMElement();
    });
});
