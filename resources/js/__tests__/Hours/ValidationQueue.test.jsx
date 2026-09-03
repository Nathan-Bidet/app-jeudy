import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Carte « Heures à valider » : le valideur doit disposer, avant de se
 * prononcer, des horaires réellement saisis, des cases particulières déclarées
 * et de l'écart avec la durée normale de la journée.
 */

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
}));

import HoursValidationQueue from '@/Components/Hours/HoursValidationQueue';

/** Journée telle que la file de validation la reçoit du serveur. */
function queueRow(overrides = {}) {
    return {
        id: 1,
        work_date: '2026-09-02', // un mercredi, référence 8 h
        user_label: 'Nathan Bidet',
        morning_start: '08:00',
        morning_end: '12:00',
        afternoon_start: '14:00',
        afternoon_end: '19:00',
        is_continuous_day: false,
        total_minutes: 540,
        description: 'Entretien du matériel',
        is_not_worked: false,
        has_breakfast_before_5: false,
        has_lunch: false,
        has_dinner_after_21: false,
        has_long_night: false,
        status: 'pending',
        status_label: 'En attente de validation',
        validation_summary: [
            { level: 1, decision: null, label: 'En attente' },
            { level: 2, decision: null, label: 'En attente' },
        ],
        ...overrides,
    };
}

/**
 * Rend la file et déplie le groupe de l'utilisateur : les journées ne sont
 * visibles qu'une fois la personne dépliée.
 */
function renderQueue(rows) {
    const result = render(<HoursValidationQueue rows={rows} pendingCount={rows.length} />);

    // fireEvent enveloppe la mise à jour d'état dans act() ; un .click() brut
    // laisserait le groupe replié.
    fireEvent.click(screen.getByRole('button', { name: /Nathan Bidet/ }));

    return result;
}

describe('HoursValidationQueue — informations de la journée', () => {
    beforeEach(() => {
        global.route = vi.fn((name, params) => `/${name}/${params ?? ''}`);
    });

    afterEach(cleanup);

    it('affiche les horaires réellement saisis', () => {
        renderQueue([queueRow()]);

        expect(screen.getByText('08:00 - 12:00 / 14:00 - 19:00')).toBeInTheDocument();
    });

    it('rend une journée continue comme une plage unique', () => {
        renderQueue([queueRow({
            is_continuous_day: true,
            morning_end: null,
            afternoon_start: null,
            afternoon_end: '17:00',
            total_minutes: 540,
        })]);

        expect(screen.getByText('08:00 - 17:00 (journée continue)')).toBeInTheDocument();
    });

    it('n\'affiche pas de plage horaire inexistante sur une demi-journée', () => {
        renderQueue([queueRow({
            morning_start: null,
            morning_end: null,
            total_minutes: 300,
        })]);

        expect(screen.getByText('14:00 - 19:00')).toBeInTheDocument();
        expect(screen.queryByText(/--:--/)).not.toBeInTheDocument();
    });

    it('n\'affiche que les cases cochées', () => {
        renderQueue([queueRow({ has_lunch: true, has_long_night: true })]);

        expect(screen.getByText('Déjeuner · Nuit')).toBeInTheDocument();
        expect(screen.queryByText(/Casse-croûte/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Dîner/)).not.toBeInTheDocument();
    });

    it('annonce « Aucune » quand rien n\'est coché', () => {
        renderQueue([queueRow()]);

        expect(screen.getByText('Aucune')).toBeInTheDocument();
    });

    it('affiche le badge d\'heures supplémentaires, au même format que la saisie', () => {
        renderQueue([queueRow()]);

        // Mercredi, 9 h travaillées pour 8 h de référence.
        expect(screen.getByTitle(/Heures supplémentaires/)).toHaveTextContent('+1h00');
    });

    it('affiche +30 min pour une demi-heure de dépassement', () => {
        renderQueue([queueRow({ afternoon_end: '18:30', total_minutes: 510 })]);

        expect(screen.getByTitle(/Heures supplémentaires/)).toHaveTextContent('+30 min');
    });

    it('applique la référence de 7 h le vendredi', () => {
        // 2026-09-04 est un vendredi : 8 h travaillées font 1 h de plus.
        renderQueue([queueRow({ work_date: '2026-09-04', afternoon_end: '18:00', total_minutes: 480 })]);

        expect(screen.getByTitle(/Heures supplémentaires/)).toHaveTextContent('+1h00');
    });

    it('n\'affiche aucun badge sur une journée conforme à la durée normale', () => {
        renderQueue([queueRow({ afternoon_end: '18:00', total_minutes: 480 })]);

        expect(screen.queryByTitle(/Heures supplémentaires/)).not.toBeInTheDocument();
    });

    it('n\'affiche aucun badge sous la durée normale', () => {
        renderQueue([queueRow({ afternoon_end: '17:00', total_minutes: 420 })]);

        expect(screen.queryByTitle(/Heures supplémentaires/)).not.toBeInTheDocument();
    });

    it('traite une journée non travaillée sans horaires ni cases', () => {
        renderQueue([queueRow({
            is_not_worked: true,
            morning_start: null,
            morning_end: null,
            afternoon_start: null,
            afternoon_end: null,
            total_minutes: 0,
        })]);

        expect(screen.getByText('Journée non travaillée')).toBeInTheDocument();
        expect(screen.queryByText(/Horaires :/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Cases cochées :/)).not.toBeInTheDocument();
        expect(screen.queryByTitle(/Heures supplémentaires/)).not.toBeInTheDocument();
    });

    it('conserve l\'état des deux valideurs et les boutons de décision', () => {
        renderQueue([queueRow()]);

        expect(screen.getByText('Valideur 1 :')).toBeInTheDocument();
        expect(screen.getByText('Valideur 2 :')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Valider' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Refuser' })).toBeInTheDocument();
    });

    it('ne nomme aucun valideur', () => {
        const { container } = renderQueue([queueRow()]);

        expect(container.textContent).not.toMatch(/Alice|Floriane/);
        expect(within(container).getAllByText(/En attente/).length).toBeGreaterThan(0);
    });
});
