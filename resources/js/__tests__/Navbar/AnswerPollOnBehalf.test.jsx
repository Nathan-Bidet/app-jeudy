import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * "Répondre au nom de" doit aussi être disponible depuis une annonce ouverte
 * via le centre de notifications (vue complète du sondage dans le modal
 * d'annonce existant), avec le même endpoint/hook/modal que la page
 * détaillée et l'accueil — pas une troisième implémentation.
 */

let pageProps;
const postMock = vi.hoisted(() => vi.fn((url, data, options) => options?.onSuccess?.()));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
    router: {
        post: postMock,
        delete: vi.fn((url, options) => options?.onSuccess?.()),
    },
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));

import { NotificationsMenu } from '@/Layouts/AppShell/Navbar';

function pollNotification({ poll: pollOverrides, ...overrides } = {}) {
    return {
        id: 'n-1',
        type: 'announcement',
        title: 'Sécurité chantier',
        message: 'Nouvelle annonce',
        full_message: null,
        body_html: '<p>Merci de répondre au sondage.</p>',
        announcement_author: 'Chef de service',
        announcement_id: 99,
        period: { start_at: null, end_at: null },
        requester_label: null,
        leave_request_id: null,
        url: null,
        created_at: new Date().toISOString(),
        read_at: new Date().toISOString(),
        poll: {
            id: 5,
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
            ...pollOverrides,
        },
        ...overrides,
    };
}

function setPageProps(items, unreadCount) {
    pageProps = { errors: {}, notifications: { items, unread_count: unreadCount } };
}

beforeEach(() => {
    global.route = vi.fn((name, params) => `/${name}/${Array.isArray(params) ? params.join('/') : (params ?? '')}`);
    // HeadlessUI Dialog (le modal d'annonce complet) mesure ses conteneurs
    // via ResizeObserver, absent de jsdom.
    global.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    global.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                notifications: pageProps.notifications.items,
                unread_count: pageProps.notifications.unread_count,
            }),
        }),
    );
    postMock.mockClear();
});

afterEach(() => {
    cleanup();
});

async function openAnnouncementModal() {
    const [bellButton] = screen.getAllByRole('button');
    fireEvent.click(bellButton);
    const row = await screen.findByText('Sécurité chantier');
    fireEvent.click(row);
    // La vue complète (titre + résultats du sondage) doit être ouverte. Le
    // viewer (admin) n'est pas forcément lui-même destinataire du sondage :
    // seuls "Réponses reçues"/les listes sont garantis, pas le formulaire de
    // vote personnel.
    await screen.findByText('Réponses reçues');
}

describe('NotificationsMenu — répondre au nom d\'un destinataire depuis une annonce ouverte', () => {
    it('makes pending/responded users clickable in the full announcement view when authorized', async () => {
        setPageProps([pollNotification()], 0);
        render(<NotificationsMenu />);

        await openAnnouncementModal();

        expect(screen.getByRole('button', { name: 'Alice Martin' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Bob Dupont' })).toBeInTheDocument();
    });

    it('leaves names as plain text when the viewer is not authorized', async () => {
        setPageProps([pollNotification({ poll: { can_answer_for_others: false } })], 0);
        render(<NotificationsMenu />);

        await openAnnouncementModal();

        expect(screen.queryByRole('button', { name: 'Alice Martin' })).not.toBeInTheDocument();
        expect(screen.getByText('Alice Martin')).toBeInTheDocument();
    });

    it('opens the on-behalf modal above the announcement view and submits to the shared endpoint', async () => {
        setPageProps([pollNotification()], 0);
        render(<NotificationsMenu />);

        await openAnnouncementModal();

        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));
        expect(await screen.findByText(/Répondre au nom de Bob Dupont/)).toBeInTheDocument();

        // Les deux vues (annonce + réponse au nom de) restent utilisables simultanément.
        expect(screen.getAllByText('Sécurité chantier').length).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('radio', { name: 'Oui' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la réponse' }));

        await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1));
        const [url, data] = postMock.mock.calls[0];
        expect(url).toBe('/annonces.poll-response-for/99/11');
        expect(data.selected_option_ids).toEqual([1]);
        expect(data.expected_exists).toBe(false);
    });

    it('preselects the existing response when reopening for an already-responded user', async () => {
        setPageProps([pollNotification()], 0);
        render(<NotificationsMenu />);

        await openAnnouncementModal();
        fireEvent.click(screen.getByRole('button', { name: 'Alice Martin' }));

        expect(await screen.findByText(/Modifier la réponse de Alice Martin/)).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Oui' })).toBeChecked();
    });
});
