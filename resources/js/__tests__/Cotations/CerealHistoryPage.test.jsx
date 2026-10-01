import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { useState } from 'react';

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ header, children }) => <div><div data-testid="header">{header}</div>{children}</div>,
}));
vi.mock('@/Components/Cotations/CotationMailModal', () => ({ default: () => null }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((current) => (typeof key === 'object' ? { ...current, ...key } : { ...current, [key]: value })),
            put: vi.fn(),
            post: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            processing: false,
            errors: {},
        };
    },
}));

import CotationsIndex from '@/Pages/Cotations/Index';

const group = (code, name, price) => ({
    code,
    name,
    sort: 10,
    harvests: {
        left: { year: 2026, rows: [{ identity_hash: `h-${code}`, product_code: code, product_name: name, maturity_label: 'Sept', maturity_year: 2026, harvest_year: 2026, matif: price, margin: 0, final_price: price, line_type: 'matif' }] },
        right: { year: 2027, rows: [] },
    },
});

const currentMarket = {
    groups: [group('EBM', 'Blé actuel', 600)],
    fetched_at: '2026-10-05T10:00:00+02:00',
    harvest_years: { left: 2026, right: 2027 },
    cereal_info_html: '<p>Information ACTUELLE</p>',
    cereal_table_labels: {},
    options: [],
    custom_cereals: [],
};

// Forme renvoyée par le serveur : cotations de marché reconstituées depuis l'historique existant.
const versionFor = (date, wheatName, price) => ({
    business_date: date,
    recorded_at: `${date}T18:00:00+02:00`,
    row_count: 1,
    groups: [{
        code: 'EBM',
        name: wheatName,
        sort: 20,
        harvests: [{ year: 2026, rows: [{ label: 'Septembre', maturity_year: 2026, contract_code: null, price, quoted_at: null, recorded_at: `${date}T18:00:00+02:00` }] }],
    }],
    not_historized: ['Marges et prix finaux', "Texte d'information"],
});

const routes = {
    market_data: '/market',
    export_pdf: '/pdf',
    send_mail: '/send',
    mail_draft: '/draft',
    mail_base: '/mail',
    cereal_history_dates: '/hist/dates',
    cereal_history_base: '/hist',
};

// Références stables : comme avec Inertia, les props ne changent pas à chaque rendu.
const props = {
    transportGrid: {},
    fuelGrid: {},
    marketData: currentMarket,
    manualSettings: { display: [] },
    cerealLabels: {},
    cerealTableLabels: {},
    permissions: { can_view_cereals: true, can_manage: true },
    routes,
};

function mockFetch(handlers) {
    global.fetch = vi.fn(async (url) => {
        const handler = handlers[url] ?? handlers.default;
        const result = handler ? handler(url) : { status: 404, body: { message: 'x' } };
        return { ok: result.status === 200, status: result.status, json: async () => result.body };
    });
}

beforeEach(() => {
    window.route = (name) => `/${name}`;
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('Cotations céréales — mode historique', () => {
    it('sans historique : message clair, comportement actuel conservé', async () => {
        mockFetch({ '/hist/dates': () => ({ status: 200, body: { dates: [] } }) });
        render(<CotationsIndex {...props} />);

        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));

        expect(await screen.findByText('Aucun historique disponible pour le moment.')).toBeInTheDocument();
        expect(screen.queryByTestId('cereal-history-mode')).toBeNull();
        expect(screen.getByText('Information ACTUELLE')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Actualiser/ })).toBeInTheDocument();
    });

    it('affiche l’instantané de la dernière date, bloque les actions, puis revient au présent', async () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02', '2026-09-28'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5) } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));

        const mode = await screen.findByTestId('cereal-history-mode');
        expect(mode).toHaveTextContent('cotations du 02/10/2026');
        expect(mode).toHaveTextContent('lecture seule');
        // Valeurs de la journée, pas les valeurs actuelles.
        expect(screen.getByText('Blé du 2 octobre')).toBeInTheDocument();
        expect(screen.getByText('231,50')).toBeInTheDocument();
        expect(screen.getByRole('note')).toHaveTextContent('Marges et prix finaux');
        expect(screen.queryByText('Information ACTUELLE')).toBeNull();
        expect(screen.queryByText('Blé actuel')).toBeNull();
        // Aucune action pouvant écraser ou envoyer : Actualiser, Modifier, Export PDF, Envoyer.
        ['Actualiser', 'Modifier les cotations', 'Export PDF', 'Envoyer'].forEach((name) => {
            expect(screen.queryByRole('button', { name: new RegExp(name) })).toBeNull();
        });

        // Navigation : la plus ancienne n'existe pas au-delà de la dernière.
        expect(screen.getByRole('button', { name: /Plus récente/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Plus ancienne/ })).toBeEnabled();

        fireEvent.click(screen.getByRole('button', { name: "Fermer l'historique" }));

        expect(screen.queryByTestId('cereal-history-mode')).toBeNull();
        expect(screen.getByText('Information ACTUELLE')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Actualiser/ })).toBeInTheDocument();
        expect(window.location.search).toBe('');
    });

    it('charge la version choisie dans le calendrier et met la date à jour', async () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02', '2026-09-28'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5) } }),
            '/hist/2026-09-28': () => ({ status: 200, body: { version: versionFor('2026-09-28', 'Blé du 28 septembre', 180) } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');

        fireEvent.click(screen.getByRole('button', { name: /02\/10\/2026/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Mois précédent' }));
        fireEvent.click(screen.getByRole('button', { name: '28/09/2026, version disponible' }));

        expect(await screen.findByText('Blé du 28 septembre')).toBeInTheDocument();
        expect(screen.getByTestId('cereal-history-mode')).toHaveTextContent('cotations du 28/09/2026');
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('version supprimée entre le calendrier et le clic : message clair, vue précédente conservée', async () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02', '2026-09-28'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5) } }),
            '/hist/2026-09-28': () => ({ status: 404, body: { message: "Cette version historique n'est plus disponible." } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');

        fireEvent.click(screen.getByRole('button', { name: /Plus ancienne/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent("Cette version historique n'est plus disponible.");
        expect(screen.getByTestId('cereal-history-mode')).toHaveTextContent('cotations du 02/10/2026');
        expect(screen.getByText('Blé du 2 octobre')).toBeInTheDocument();
    });

    it('sans permission de consultation, aucun bouton Historique', () => {
        render(<CotationsIndex {...props} permissions={{ can_view_cereals: false, can_view_fuel: true }} />);

        expect(screen.queryByRole('button', { name: /Historique/ })).toBeNull();
    });
});
