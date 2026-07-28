import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Centre de notifications : les deux actions globales "Tout marquer comme lu"
 * et "Tout supprimer" sont exclusives, dérivées de unreadCount et de la
 * liste déjà chargée (pas de nouvel état local dupliqué) :
 * - unreadCount > 0            -> "Tout marquer comme lu" seul
 * - unreadCount === 0 et non vide -> "Tout supprimer" seul
 * - liste vide                 -> aucun des deux
 */

let pageProps;

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
    router: {
        post: vi.fn((url, data, options) => options?.onSuccess?.()),
        delete: vi.fn((url, options) => options?.onSuccess?.()),
    },
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));

import { NotificationsMenu } from '@/Layouts/AppShell/Navbar';

function notification(overrides = {}) {
    return {
        id: 'n-1',
        type: 'leave_request_approved',
        title: null,
        message: 'Votre demande a été approuvée.',
        full_message: null,
        body_html: null,
        announcement_author: null,
        poll: null,
        period: { start_at: null, end_at: null },
        requester_label: null,
        leave_request_id: null,
        announcement_id: null,
        url: null,
        created_at: new Date().toISOString(),
        read_at: null,
        ...overrides,
    };
}

function setPageProps(items, unreadCount) {
    pageProps = {
        errors: {},
        notifications: {
            items,
            unread_count: unreadCount,
        },
    };
}

beforeEach(() => {
    global.route = vi.fn((name, params) => `/${name}/${params ?? ''}`);
    // Le polling au montage (refreshNotifications) appelle fetch() de façon
    // asynchrone : on renvoie l'état courant de pageProps plutôt qu'une valeur
    // figée, pour ne pas écraser l'état posé par chaque test avec une liste
    // vide une fois la promesse résolue.
    global.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                notifications: pageProps.notifications.items,
                unread_count: pageProps.notifications.unread_count,
            }),
        }),
    );
    global.window.confirm = vi.fn(() => true);
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('NotificationsMenu — actions globales exclusives', () => {
    it('shows only "Tout marquer comme lu" when at least one notification is unread', async () => {
        setPageProps([notification({ id: '1', read_at: null }), notification({ id: '2', read_at: new Date().toISOString() })], 1);
        render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);

        expect(await screen.findByText('Tout marquer comme lu')).toBeInTheDocument();
        expect(screen.queryByText('Tout supprimer')).not.toBeInTheDocument();
    });

    it('shows only "Tout supprimer" when notifications exist and are all read', async () => {
        setPageProps([notification({ id: '1', read_at: new Date().toISOString() })], 0);
        render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);

        expect(await screen.findByText('Tout supprimer')).toBeInTheDocument();
        expect(screen.queryByText('Tout marquer comme lu')).not.toBeInTheDocument();
    });

    // Même composant, même rendu sur mobile et ordinateur (pas de variante
    // dédiée) : le panneau autorise un retour à la ligne contrôlé de l'action
    // plutôt que sa troncature/compression lorsque l'espace manque (petits
    // écrans), et le libellé du bouton ne peut jamais se couper en deux
    // lignes (whitespace-nowrap) au milieu d'un mot.
    it('lets the header row wrap to a second line and keeps the action label on one line', async () => {
        setPageProps([notification({ id: '1', read_at: new Date().toISOString() })], 0);
        render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);

        const deleteAllButton = await screen.findByText('Tout supprimer');
        const header = deleteAllButton.closest('div');

        expect(header.className).toContain('flex-wrap');
        expect(deleteAllButton.className).toContain('whitespace-nowrap');
    });

    it('shows neither action when there are no notifications', async () => {
        setPageProps([], 0);
        render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);

        await screen.findByText('Aucune notification pour le moment.');
        expect(screen.queryByText('Tout marquer comme lu')).not.toBeInTheDocument();
        expect(screen.queryByText('Tout supprimer')).not.toBeInTheDocument();
    });

    it('replaces "Tout marquer comme lu" with "Tout supprimer" immediately after marking all as read', async () => {
        setPageProps([notification({ id: '1', read_at: null })], 1);
        render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);

        const markAllButton = await screen.findByText('Tout marquer comme lu');
        fireEvent.click(markAllButton);

        await waitFor(() => {
            expect(screen.queryByText('Tout marquer comme lu')).not.toBeInTheDocument();
            expect(screen.getByText('Tout supprimer')).toBeInTheDocument();
        });
    });

    it('asks for confirmation, then deletes all and shows the empty state', async () => {
        setPageProps([notification({ id: '1', read_at: new Date().toISOString() })], 0);
        render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);

        const deleteAllButton = await screen.findByText('Tout supprimer');
        fireEvent.click(deleteAllButton);

        expect(window.confirm).toHaveBeenCalledWith('Supprimer définitivement toutes vos notifications lues ?');

        await waitFor(() => {
            expect(screen.getByText('Aucune notification pour le moment.')).toBeInTheDocument();
            expect(screen.queryByText('Tout supprimer')).not.toBeInTheDocument();
        });
    });

    it('does not delete anything when the confirmation is cancelled', async () => {
        window.confirm = vi.fn(() => false);
        setPageProps([notification({ id: '1', read_at: new Date().toISOString() })], 0);
        render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);

        const deleteAllButton = await screen.findByText('Tout supprimer');
        fireEvent.click(deleteAllButton);

        expect(screen.getByText('Tout supprimer')).toBeInTheDocument();
        expect(screen.queryByText('Aucune notification pour le moment.')).not.toBeInTheDocument();
    });

    it('re-shows "Tout marquer comme lu" if a new unread notification arrives while "Tout supprimer" is visible', async () => {
        setPageProps([notification({ id: '1', read_at: new Date().toISOString() })], 0);
        const { rerender } = render(<NotificationsMenu />);

        const [bellButton] = screen.getAllByRole('button');
        fireEvent.click(bellButton);
        expect(await screen.findByText('Tout supprimer')).toBeInTheDocument();

        setPageProps(
            [notification({ id: '1', read_at: new Date().toISOString() }), notification({ id: '2', read_at: null })],
            1,
        );
        rerender(<NotificationsMenu />);

        await waitFor(() => {
            expect(screen.getByText('Tout marquer comme lu')).toBeInTheDocument();
            expect(screen.queryByText('Tout supprimer')).not.toBeInTheDocument();
        });
    });
});
