import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { useState } from 'react';

/**
 * Intégration fidèle au modal "Réponses" (parent, ex. AnnouncementDetailModal)
 * empilé avec le modal "Répondre au nom de" (enfant) — mêmes composants
 * (Modal, PollDisplay, AnswerPollOnBehalfModal) et le même hook partagé
 * qu'en production, pour vérifier que le second n'affecte jamais le premier.
 */

let pageProps;
const postMock = vi.hoisted(() => vi.fn((url, data, options) => options?.onSuccess?.()));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
    router: { post: postMock },
}));

import Modal from '@/Components/Modal';
import PollDisplay from '@/Components/Announcements/PollDisplay';
import AnswerPollOnBehalfModal from '@/Components/Announcements/AnswerPollOnBehalfModal';
import useAnswerPollOnBehalf from '@/hooks/useAnswerPollOnBehalf';

function baseAnnouncement(overrides = {}) {
    return {
        id: 7,
        title: 'Sécurité chantier',
        poll: {
            id: 3,
            poll_type: 'single',
            title: 'Portez-vous vos EPI ?',
            allow_other: false,
            other_label: 'Autre',
            options: [
                { id: 1, label: 'Oui' },
                { id: 2, label: 'Non' },
            ],
            can_respond: false,
            can_answer_for_others: true,
            results: {
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
            },
        },
        ...overrides,
    };
}

// Mime fidèlement AnnouncementDetailModal + AnswerPollOnBehalfModal empilés
// comme dans resources/js/Pages/Annonces/Index.jsx.
function ParentAndChildModals({ announcement }) {
    const [open, setOpen] = useState(true);
    const { context, processing, open: openChild, close: closeChild, reset: resetChild, submit } = useAnswerPollOnBehalf([announcement]);

    const closeParent = () => {
        setOpen(false);
        resetChild();
    };

    return (
        <>
            <Modal show={open} onClose={closeParent} maxWidth="2xl">
                <div>
                    <p>Réponses (parent)</p>
                    <button type="button" onClick={closeParent}>Fermer le parent</button>
                    <PollDisplay
                        poll={announcement.poll}
                        variant="full"
                        canAnswerForOthers={Boolean(announcement.poll.can_answer_for_others)}
                        onAnswerForUser={(user) => openChild(announcement.id, user)}
                    />
                </div>
            </Modal>
            <AnswerPollOnBehalfModal
                context={context}
                onClose={closeChild}
                onSubmit={submit}
                processing={processing}
                zIndexClass="z-[60]"
                errors={pageProps.errors}
            />
        </>
    );
}

beforeEach(() => {
    global.route = vi.fn((name, params) => `/${name}/${Array.isArray(params) ? params.join('/') : (params ?? '')}`);
    global.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    pageProps = { errors: {} };
    postMock.mockClear();
});

afterEach(() => {
    cleanup();
});

describe('Modal "Réponses" + modal "Répondre au nom de" — isolation complète', () => {
    it('opens the child from a pending user, preselecting nothing', () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));

        expect(screen.getByText(/Répondre au nom de Bob Dupont/)).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Oui' })).not.toBeChecked();
    });

    it('opens the child from a responded user, preselecting the existing answer', () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Alice Martin' }));

        expect(screen.getByText(/Modifier la réponse de Alice Martin/)).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Oui' })).toBeChecked();
    });

    it('selecting an option in the child does not close either modal', () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));

        fireEvent.click(screen.getByRole('radio', { name: 'Non' }));

        expect(screen.getByText(/Répondre au nom de Bob Dupont/)).toBeInTheDocument();
        expect(screen.getByText('Réponses (parent)')).toBeInTheDocument();
    });

    it('cancelling the child closes only the child, parent stays open', () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));

        fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));

        expect(screen.queryByText(/Répondre au nom de Bob Dupont/)).not.toBeInTheDocument();
        expect(screen.getByText('Réponses (parent)')).toBeInTheDocument();
    });

    it('a pointerdown/pointerup on the child overlay closes only the child', () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));

        const [, childDialog] = screen.getAllByRole('dialog', { hidden: true });
        const overlay = childDialog.querySelector('.bg-gray-500\\/75');
        fireEvent.pointerDown(overlay);
        fireEvent.pointerUp(overlay);

        expect(screen.queryByText(/Répondre au nom de Bob Dupont/)).not.toBeInTheDocument();
        expect(screen.getByText('Réponses (parent)')).toBeInTheDocument();
    });

    it('Escape closes only the child while it is open', () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));

        fireEvent.keyDown(document, { key: 'Escape', code: 'Escape' });

        expect(screen.queryByText(/Répondre au nom de Bob Dupont/)).not.toBeInTheDocument();
        expect(screen.getByText('Réponses (parent)')).toBeInTheDocument();
    });

    it('a successful submission closes only the child and keeps the parent open', async () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));

        fireEvent.click(screen.getByRole('radio', { name: 'Oui' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la réponse' }));

        await waitFor(() => {
            expect(screen.queryByText(/Répondre au nom de Bob Dupont/)).not.toBeInTheDocument();
        });
        expect(screen.getByText('Réponses (parent)')).toBeInTheDocument();
        expect(postMock).toHaveBeenCalledTimes(1);
    });

    it('a server error keeps both modals open with the selection and re-enables the submit button', async () => {
        postMock.mockImplementationOnce((url, data, options) => {
            pageProps = { errors: { selected_option_ids: 'Erreur serveur.' } };
            options?.onError?.({ selected_option_ids: 'Erreur serveur.' });
            options?.onFinish?.();
        });

        render(<ParentAndChildModals announcement={baseAnnouncement()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));
        fireEvent.click(screen.getByRole('radio', { name: 'Oui' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la réponse' }));

        expect(screen.getByText(/Répondre au nom de Bob Dupont/)).toBeInTheDocument();
        expect(screen.getByText('Réponses (parent)')).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Oui' })).toBeChecked();
        expect(screen.getByRole('button', { name: 'Enregistrer la réponse' })).not.toBeDisabled();
    });

    it('closing the parent while the child is still open resets the child (no stale target user survives)', async () => {
        render(<ParentAndChildModals announcement={baseAnnouncement()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));
        expect(screen.getByText(/Répondre au nom de Bob Dupont/)).toBeInTheDocument();

        // Fermeture directe du parent (ex. bouton "Fermer" du header) pendant
        // que l'enfant est encore ouvert par-dessus (le parent est alors
        // marqué aria-hidden/inerte, d'où { hidden: true } pour le trouver).
        fireEvent.click(screen.getByRole('button', { name: 'Fermer le parent', hidden: true }));

        await waitFor(() => {
            expect(screen.queryByText('Réponses (parent)')).not.toBeInTheDocument();
        });
        expect(screen.queryByText(/Répondre au nom de Bob Dupont/)).not.toBeInTheDocument();
    });
});
