import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Formulaire de refus d'une journée d'heures : motif obligatoire.
 * Le serveur applique la même règle (RefuseHourSheetRequest) ; ces tests
 * vérifient que l'écran l'annonce et n'envoie rien d'invalide.
 */

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: (...args) => post(...args) },
}));

import HoursValidationQueue from '@/Components/Hours/HoursValidationQueue';

const row = {
    id: 7,
    work_date: '2026-09-02',
    user_label: 'Nathan Bidet',
    morning_start: '08:00',
    morning_end: '12:00',
    afternoon_start: '14:00',
    afternoon_end: '18:00',
    is_continuous_day: false,
    total_minutes: 480,
    description: 'Atelier',
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
};

function openRefusalForm() {
    render(<HoursValidationQueue rows={[row]} pendingCount={1} />);
    fireEvent.click(screen.getByRole('button', { name: /Nathan Bidet/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Refuser' }));

    return screen.getByLabelText(/Motif du refus/);
}

describe('HoursValidationQueue — motif de refus obligatoire', () => {
    beforeEach(() => {
        global.route = vi.fn((name, id) => `/${name}/${id}`);
        post.mockReset();
    });

    afterEach(cleanup);

    it('annonce clairement que le motif est obligatoire', () => {
        const field = openRefusalForm();

        expect(screen.getByText('Motif du refus', { exact: false })).toBeInTheDocument();
        expect(screen.queryByText(/facultatif/)).not.toBeInTheDocument();
        expect(screen.getByText('*')).toBeInTheDocument();
        expect(field).toBeRequired();
        expect(field).toHaveAttribute('aria-required', 'true');
        expect(field).toHaveAttribute('placeholder', 'Indiquez la raison du refus…');
        expect(field).toHaveFocus();
    });

    it('bloque la confirmation d\'un champ vide, avec un message près du champ', () => {
        const field = openRefusalForm();

        fireEvent.click(screen.getByRole('button', { name: 'Confirmer le refus' }));

        expect(post).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent('Le motif du refus est obligatoire.');
        expect(field).toHaveAttribute('aria-invalid', 'true');
    });

    it('traite un motif fait d\'espaces, tabulations et retours à la ligne comme vide', () => {
        const field = openRefusalForm();

        fireEvent.change(field, { target: { value: ' \t\n  ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirmer le refus' }));

        expect(post).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent('Le motif du refus est obligatoire.');
        expect(field).toHaveValue(' \t\n  ');
    });

    it('envoie un motif valide', () => {
        const field = openRefusalForm();

        fireEvent.change(field, { target: { value: 'Pause non déclarée' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirmer le refus' }));

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/hours.refuse/7');
        expect(post.mock.calls[0][1]).toEqual({ refusal_reason: 'Pause non déclarée' });
    });

    it('garde le texte et affiche l\'erreur renvoyée par le serveur', () => {
        post.mockImplementation((url, data, options) => {
            options.onError({ refusal_reason: 'Le motif du refus est obligatoire.' });
            options.onFinish();
        });
        const field = openRefusalForm();

        fireEvent.change(field, { target: { value: 'Motif' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirmer le refus' }));

        expect(screen.getByLabelText(/Motif du refus/)).toHaveValue('Motif');
        expect(screen.getByRole('alert')).toHaveTextContent('Le motif du refus est obligatoire.');
    });

    it('referme le formulaire après un refus enregistré', () => {
        post.mockImplementation((url, data, options) => {
            options.onSuccess();
            options.onFinish();
        });
        const field = openRefusalForm();

        fireEvent.change(field, { target: { value: 'Motif' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirmer le refus' }));

        expect(screen.queryByLabelText(/Motif du refus/)).not.toBeInTheDocument();
    });

    it('« Annuler » ferme le formulaire sans rien envoyer', () => {
        const field = openRefusalForm();

        fireEvent.change(field, { target: { value: 'Brouillon' } });
        fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));

        expect(post).not.toHaveBeenCalled();
        expect(screen.queryByLabelText(/Motif du refus/)).not.toBeInTheDocument();

        // Rouvert : champ vierge, sans erreur résiduelle.
        fireEvent.click(screen.getByRole('button', { name: 'Refuser' }));
        expect(screen.getByLabelText(/Motif du refus/)).toHaveValue('');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
