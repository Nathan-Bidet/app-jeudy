import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

import PollDisplay, { PollResults } from '@/Components/Announcements/PollDisplay';

/**
 * "Répondre au nom de" : PollResults doit rendre les listes "Ont répondu" /
 * "En attente" cliquables uniquement quand canAnswerForOthers + un callback
 * sont fournis (sinon comportement inchangé, simple affichage) ; PollDisplay
 * doit pouvoir préremplir/soumettre pour un destinataire ciblé (respondingFor)
 * sans dépendre de poll.can_respond du viewer, en réutilisant exactement le
 * même formulaire de vote que l'auto-réponse.
 */

afterEach(() => {
    cleanup();
});

function baseResults(overrides = {}) {
    return {
        recipient_count: 2,
        response_count: 1,
        other_label: 'Autre',
        options: [
            { id: 1, label: 'Oui', votes: 1, percentage: 100, respondents: [{ id: 10, name: 'Alice Martin' }] },
            { id: 2, label: 'Non', votes: 0, percentage: 0, respondents: [] },
        ],
        other_responses: [],
        responded_users: [{ id: 10, name: 'Alice Martin', responded_at: '2026-01-01T10:00:00Z' }],
        pending_users: [{ id: 11, name: 'Bob Dupont' }],
        ...overrides,
    };
}

describe('PollResults — noms cliquables réservés aux personnes autorisées', () => {
    it('renders plain text (not clickable) when canAnswerForOthers is not set', () => {
        render(<PollResults results={baseResults()} />);

        expect(screen.getByText('Alice Martin').tagName).toBe('P');
        expect(screen.getByText('Bob Dupont').tagName).toBe('P');
        expect(screen.queryByRole('button', { name: /Alice Martin/ })).not.toBeInTheDocument();
    });

    it('renders clickable buttons in both lists when authorized, calling onAnswerForUser with the right user', () => {
        const onAnswerForUser = vi.fn();
        render(<PollResults results={baseResults()} canAnswerForOthers onAnswerForUser={onAnswerForUser} />);

        const respondedButton = screen.getByRole('button', { name: 'Alice Martin' });
        const pendingButton = screen.getByRole('button', { name: 'Bob Dupont' });

        fireEvent.click(respondedButton);
        expect(onAnswerForUser).toHaveBeenCalledWith({ id: 10, name: 'Alice Martin', responded_at: '2026-01-01T10:00:00Z' });

        fireEvent.click(pendingButton);
        expect(onAnswerForUser).toHaveBeenCalledWith({ id: 11, name: 'Bob Dupont' });
    });

    it('does not render clickable buttons when canAnswerForOthers is true but no callback is provided', () => {
        render(<PollResults results={baseResults()} canAnswerForOthers />);

        expect(screen.queryByRole('button', { name: /Alice Martin/ })).not.toBeInTheDocument();
    });
});

describe('PollDisplay — répondre au nom d\'un destinataire (respondingFor)', () => {
    const poll = {
        id: 1,
        poll_type: 'single',
        title: 'Question test',
        allow_other: false,
        other_label: 'Autre',
        options: [
            { id: 1, label: 'Oui' },
            { id: 2, label: 'Non' },
        ],
        can_respond: false, // le viewer (admin) lui-même n'est pas destinataire
    };

    it('shows the interactive form even when poll.can_respond is false, using respondingFor instead', () => {
        render(
            <PollDisplay
                poll={poll}
                variant="full"
                respondingFor={{ id: 11, name: 'Bob Dupont', response: null }}
                onSubmitResponse={vi.fn()}
            />,
        );

        expect(screen.getByRole('radio', { name: 'Oui' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Non' })).toBeInTheDocument();
    });

    it('preselects the target user\'s existing response instead of the viewer\'s own', () => {
        render(
            <PollDisplay
                poll={poll}
                variant="full"
                respondingFor={{
                    id: 10,
                    name: 'Alice Martin',
                    response: { selected_option_ids: [2], other_text: '' },
                }}
                onSubmitResponse={vi.fn()}
            />,
        );

        expect(screen.getByRole('radio', { name: 'Non' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'Oui' })).not.toBeChecked();
    });

    it('does not show the "your response was recorded" banner when answering on behalf of someone', () => {
        render(
            <PollDisplay
                poll={poll}
                variant="full"
                respondingFor={{ id: 10, name: 'Alice Martin', response: { selected_option_ids: [1], other_text: '' } }}
                onSubmitResponse={vi.fn()}
            />,
        );

        expect(screen.queryByText('Votre réponse a été enregistrée.')).not.toBeInTheDocument();
    });

    it('uses the custom submitLabel and calls onSubmitResponse with the selected option', () => {
        const onSubmitResponse = vi.fn();
        render(
            <PollDisplay
                poll={poll}
                variant="full"
                respondingFor={{ id: 11, name: 'Bob Dupont', response: null }}
                submitLabel="Enregistrer la réponse"
                onSubmitResponse={onSubmitResponse}
            />,
        );

        fireEvent.click(screen.getByRole('radio', { name: 'Oui' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la réponse' }));

        expect(onSubmitResponse).toHaveBeenCalledWith({ selected_option_ids: [1], other_text: '' });
    });

    it('hides the results block when hideResults is set, even if poll.results is present', () => {
        render(
            <PollDisplay
                poll={{ ...poll, results: baseResults() }}
                variant="full"
                hideResults
                respondingFor={{ id: 11, name: 'Bob Dupont', response: null }}
                onSubmitResponse={vi.fn()}
            />,
        );

        expect(screen.queryByText('Réponses reçues')).not.toBeInTheDocument();
    });

    it('still hides the vote form for a normal viewer without can_respond and without respondingFor (regression)', () => {
        render(<PollDisplay poll={poll} variant="full" onSubmitResponse={vi.fn()} />);

        expect(screen.queryByRole('radio', { name: 'Oui' })).not.toBeInTheDocument();
    });
});
