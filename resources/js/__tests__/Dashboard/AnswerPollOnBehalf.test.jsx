import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * "Répondre au nom de" doit être disponible partout où le sondage et ses
 * résultats sont affichés — pas seulement sur la page détaillée des
 * annonces — en réutilisant le même hook/endpoint/modal. Vérifié ici pour
 * la page d'accueil (DashboardIndex).
 */

const postMock = vi.hoisted(() => vi.fn((url, data, options) => options?.onSuccess?.()));

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ children }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: postMock },
    usePage: () => ({ props: { errors: {} } }),
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));

import DashboardIndex from '@/Pages/Dashboard/Index';

function dashboardWithPoll(overrides = {}) {
    return {
        widgets: [],
        dashboard_announcement: {
            id: 42,
            title: 'Sondage sécurité',
            body_html: '<p>Merci de répondre.</p>',
            has_been_viewed: true,
            status: 'sent',
            poll: {
                id: 7,
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
                ...overrides.poll,
            },
        },
    };
}

beforeEach(() => {
    global.route = vi.fn((name, params) => `/${name}/${Array.isArray(params) ? params.join('/') : (params ?? '')}`);
    postMock.mockClear();
});

afterEach(() => {
    cleanup();
});

describe('DashboardIndex — répondre au nom d\'un destinataire depuis l\'accueil', () => {
    it('makes pending/responded users clickable when can_answer_for_others is true', () => {
        render(<DashboardIndex dashboard={dashboardWithPoll()} />);

        expect(screen.getByRole('button', { name: 'Alice Martin' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Bob Dupont' })).toBeInTheDocument();
    });

    it('does not make users clickable when can_answer_for_others is false', () => {
        render(<DashboardIndex dashboard={dashboardWithPoll({ poll: { can_answer_for_others: false } })} />);

        expect(screen.queryByRole('button', { name: 'Alice Martin' })).not.toBeInTheDocument();
        expect(screen.getByText('Alice Martin')).toBeInTheDocument();
    });

    it('opens the on-behalf modal for a pending user, with no option preselected', () => {
        render(<DashboardIndex dashboard={dashboardWithPoll()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));

        expect(screen.getByText(/Répondre au nom de Bob Dupont/)).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Oui' })).not.toBeChecked();
        expect(screen.getByRole('radio', { name: 'Non' })).not.toBeChecked();
    });

    it('opens the on-behalf modal for a responded user, preselecting their existing answer', () => {
        render(<DashboardIndex dashboard={dashboardWithPoll()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Alice Martin' }));

        expect(screen.getByText(/Modifier la réponse de Alice Martin/)).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Oui' })).toBeChecked();
    });

    it('submits to the shared annonces.poll-response-for endpoint with the expected concurrency snapshot', async () => {
        render(<DashboardIndex dashboard={dashboardWithPoll()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Alice Martin' }));
        fireEvent.click(screen.getByRole('radio', { name: 'Non' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la réponse' }));

        await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1));

        const [url, data] = postMock.mock.calls[0];
        expect(url).toBe('/annonces.poll-response-for/42/10');
        expect(data.selected_option_ids).toEqual([2]);
        expect(data.expected_exists).toBe(true);
        expect(data.expected_selected_option_ids).toEqual([1]);
    });

    it('closes the modal after a successful submission', async () => {
        render(<DashboardIndex dashboard={dashboardWithPoll()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Bob Dupont' }));
        fireEvent.click(screen.getByRole('radio', { name: 'Oui' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la réponse' }));

        await waitFor(() => {
            expect(screen.queryByText(/Répondre au nom de Bob Dupont/)).not.toBeInTheDocument();
        });
    });
});
