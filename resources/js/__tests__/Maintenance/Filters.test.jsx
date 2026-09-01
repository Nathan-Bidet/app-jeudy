import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Filtres automatiques de la page Maintenance, calqués sur À Prévoir :
 * recherche et dates temporisées, listes déroulantes appliquées aussitôt,
 * navigation Inertia sans rechargement.
 */

globalThis.route = vi.fn((name) => `/${String(name).replace(/\./g, '/')}`);

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ header, children }) => (
        <div>
            {header}
            {children}
        </div>
    ),
}));

const routerMock = { get: vi.fn(), patch: vi.fn(), delete: vi.fn(), post: vi.fn(), put: vi.fn() };

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        Head: ({ children }) => <>{children}</>,
        router: routerMock,
        useForm: () => ({
            data: {},
            errors: {},
            processing: false,
            setData: vi.fn(),
            setDefaults: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            transform: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
        }),
    };
});

const { default: MaintenanceIndex } = await import('@/Pages/Maintenance/Index');

function renderPage(filters = {}) {
    return render(
        <MaintenanceIndex
            groups={[]}
            meta={{ count_tasks: 0, count_groups: 0 }}
            filters={filters}
            reference={{ assignee_users: [], depots: [], depot_place_map: {}, place_suggestions: [] }}
            permissions={{ can_create: true }}
        />,
    );
}

/** Le champ de recherche de la barre desktop. */
function searchInput() {
    return screen.getAllByPlaceholderText(/Tâche, personne, lieu/)[0];
}

function lastVisit() {
    return routerMock.get.mock.calls[routerMock.get.mock.calls.length - 1];
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    routerMock.get.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
    cleanup();
});

describe('filtres automatiques', () => {
    it('n’expose plus de bouton Appliquer dans la barre de filtres', () => {
        renderPage();

        expect(screen.queryByRole('button', { name: 'Appliquer' })).not.toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Effacer' }).length).toBeGreaterThan(0);

        // Le modal mobile, à brouillon, en conserve un — comme À Prévoir.
        fireEvent.click(screen.getByRole('button', { name: /filtres/i }));

        expect(screen.getByRole('button', { name: 'Appliquer' })).toBeInTheDocument();
    });

    it('lance la recherche pendant la saisie, après temporisation', () => {
        renderPage();

        fireEvent.change(searchInput(), { target: { value: 'm' } });
        expect(routerMock.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);

        expect(routerMock.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1].search).toBe('m');
    });

    it('ne produit qu’une requête pour une saisie rapide', () => {
        renderPage();

        for (const value of ['m', 'mo', 'mon', 'mont', 'montet']) {
            fireEvent.change(searchInput(), { target: { value } });
            vi.advanceTimersByTime(80);
        }

        expect(routerMock.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);

        expect(routerMock.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1].search).toBe('montet');
    });

    it('retire le filtre quand la recherche est vidée', () => {
        renderPage({ search: 'montet' });

        fireEvent.change(searchInput(), { target: { value: '' } });
        vi.advanceTimersByTime(300);

        expect(lastVisit()[1].search).toBeUndefined();
    });

    it('garde le focus dans le champ pendant la recherche', () => {
        renderPage();

        const input = searchInput();
        input.focus();

        fireEvent.change(input, { target: { value: 'mon' } });
        vi.advanceTimersByTime(300);

        expect(document.activeElement).toBe(input);
    });

    it('applique aussitôt un changement de liste déroulante', () => {
        renderPage();

        fireEvent.change(screen.getAllByDisplayValue('Toutes origines')[0], {
            target: { value: 'request' },
        });

        // Aucun délai : la liste se met à jour à la sélection.
        expect(routerMock.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1].origin).toBe('request');

        fireEvent.change(screen.getAllByDisplayValue('À faire')[0], { target: { value: 'all' } });

        expect(routerMock.get).toHaveBeenCalledTimes(2);
        expect(lastVisit()[1].pointed_filter).toBe('all');
    });

    it('temporise les dates et n’envoie que la valeur complète', () => {
        renderPage();

        const [from, to] = screen.getAllByTitle('Date début').concat(screen.getAllByTitle('Date fin'));

        // Une saisie manuelle passe par des valeurs vides intermédiaires.
        fireEvent.change(from, { target: { value: '' } });
        fireEvent.change(from, { target: { value: '2026-09-01' } });
        vi.advanceTimersByTime(300);

        expect(routerMock.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1].date_from).toBe('2026-09-01');

        fireEvent.change(to, { target: { value: '2026-09-30' } });
        vi.advanceTimersByTime(300);

        expect(lastVisit()[1].date_from).toBe('2026-09-01');
        expect(lastVisit()[1].date_to).toBe('2026-09-30');

        // Vider une date retire le filtre.
        fireEvent.change(to, { target: { value: '' } });
        vi.advanceTimersByTime(300);

        expect(lastVisit()[1].date_to).toBeUndefined();
    });

    it('combine recherche, dates et listes déroulantes', () => {
        renderPage();

        fireEvent.change(screen.getAllByTitle('Date début')[0], { target: { value: '2026-09-01' } });
        vi.advanceTimersByTime(300);

        fireEvent.change(screen.getAllByDisplayValue('À faire')[0], { target: { value: 'pointed' } });
        fireEvent.change(searchInput(), { target: { value: 'montet' } });
        vi.advanceTimersByTime(300);

        expect(lastVisit()[1]).toMatchObject({
            date_from: '2026-09-01',
            pointed_filter: 'pointed',
            search: 'montet',
        });
    });

    it('remet tout à zéro d’un seul clic sur Effacer', () => {
        renderPage({ search: 'montet', date_from: '2026-09-01', origin: 'request', pointed_filter: 'all' });

        fireEvent.click(screen.getAllByRole('button', { name: 'Effacer' })[0]);

        const [, params] = lastVisit();

        expect(params.search).toBeUndefined();
        expect(params.date_from).toBeUndefined();
        expect(params.date_to).toBeUndefined();
        expect(params.origin).toBeUndefined();
        expect(params.pointed_filter).toBe('unpointed');
    });

    it('navigue en Inertia sans rechargement ni entrée d’historique par frappe', () => {
        renderPage();

        fireEvent.change(searchInput(), { target: { value: 'mon' } });
        vi.advanceTimersByTime(300);

        const [url, , options] = lastVisit();

        expect(url).toContain('maintenance');
        expect(options).toMatchObject({
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });
});
