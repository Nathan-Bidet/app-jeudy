import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
            // Simule une sauvegarde réussie.
            put: (_url, options) => options?.onSuccess?.(),
            post: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            processing: false,
            errors: {},
        };
    },
}));

import CotationsIndex from '@/Pages/Cotations/Index';

const group = (code, name) => ({
    code,
    name,
    sort: 10,
    harvests: {
        left: { year: 2026, rows: [{ identity_hash: `h-${code}`, product_code: code, product_name: name, maturity_label: 'Sept', maturity_year: 2026, harvest_year: 2026, matif: 200, base: 0, base_operation: 'subtract', final_price: 200, line_type: 'matif' }] },
        right: { year: 2027, rows: [] },
    },
});

const payload = (groups, hidden = []) => ({
    ok: true,
    groups,
    hidden_cereal_codes: hidden,
    harvest_years: { left: 2026, right: 2027 },
    cereal_order: groups.map((item) => item.code),
    cereal_labels: {},
    cereal_table_labels: {},
    custom_cereals: [],
    options: [],
    cereal_info_html: '',
    fetched_at: '2026-10-12T10:00:00Z',
});

// Ce que le serveur renvoie : consultation = sans Maïs ; édition (?edit=1) = tout, Maïs masqué.
const consultation = payload([group('ECO', 'Colza'), group('EBM', 'Blé')]);
const edition = payload([group('ECO', 'Colza'), group('EBM', 'Blé'), group('EMA', 'Maïs')], ['EMA']);

const routes = {
    market_data: '/market',
    export_pdf: '/pdf',
    send_mail: '/send',
    mail_draft: '/draft',
    mail_base: '/mail',
    cereal_visibility_base: '/cotations/cereals',
};
const props = {
    transportGrid: {},
    fuelGrid: {},
    marketData: consultation,
    manualSettings: { display: [] },
    cerealLabels: {},
    cerealTableLabels: {},
    permissions: { can_view_cereals: true, can_manage: true },
    routes,
};

let calls;
function mockFetch(visibilityResponse = null) {
    calls = [];
    global.fetch = vi.fn(async (url, options = {}) => {
        calls.push({ url, method: options.method || 'GET', body: options.body });
        if (String(url).startsWith('/market')) {
            return { ok: true, status: 200, json: async () => (String(url).includes('edit=1') ? edition : consultation) };
        }
        const result = visibilityResponse ?? { status: 200, body: { ok: true, code: 'EMA', visible: true, hidden_cereal_codes: [] } };
        return { ok: result.status === 200, status: result.status, json: async () => result.body };
    });
}

beforeEach(() => {
    window.route = (name) => `/${name}`;
    mockFetch();
});
afterEach(() => {
    vi.useRealTimers();
    cleanup();
    vi.restoreAllMocks();
});

describe('Cotations céréales — masquage', () => {
    it('consultation : pas de Maïs, pas de bouton œil, même pour un utilisateur autorisé à modifier', async () => {
        render(<CotationsIndex {...props} />);

        expect(await screen.findByText('Colza')).toBeInTheDocument();
        expect(screen.queryByText('Maïs')).toBeNull();
        expect(screen.queryByRole('button', { name: /Afficher Maïs|Masquer/ })).toBeNull();
        expect(calls.some((call) => String(call.url).includes('edit=1'))).toBe(false);
    });

    it('édition : charge la vue d’édition (?edit=1), montre le Maïs « Masquée » avec son œil barré', async () => {
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');

        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));

        expect(await screen.findByText('Masquée')).toBeInTheDocument();
        expect(calls.some((call) => call.url === '/market?edit=1')).toBe(true);
        expect(screen.getByRole('button', { name: /Afficher Maïs/ })).toHaveAttribute('title', 'Afficher cette céréale');
        expect(screen.getByRole('button', { name: /Masquer Colza/ })).toHaveAttribute('title', 'Masquer cette céréale');
    });

    it('réaffiche une céréale en un clic (PUT explicite) puis le Maïs reparaît en consultation après sortie d’édition', async () => {
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        fireEvent.click(await screen.findByRole('button', { name: /Afficher Maïs/ }));

        await waitFor(() => expect(screen.queryByText('Masquée')).toBeNull());
        const put = calls.find((call) => call.method === 'PUT');
        expect(put.url).toBe('/cotations/cereals/EMA/visibility');
        expect(JSON.parse(put.body)).toEqual({ visible: true });
        expect(await screen.findByText('Céréale rendue visible dans les cotations.')).toBeInTheDocument();
    });

    it('échec d’enregistrement : l’état précédent est rétabli avec un message clair', async () => {
        mockFetch({ status: 500, body: { message: 'Erreur serveur.' } });
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        fireEvent.click(await screen.findByRole('button', { name: /Afficher Maïs/ }));

        expect(await screen.findByText('Erreur serveur.')).toBeInTheDocument();
        expect(screen.getByText('Masquée')).toBeInTheDocument();
    });

    it('sortie d’édition : le Maïs masqué disparaît de la consultation sans rechargement de page', async () => {
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        await screen.findByText('Masquée');
        // En édition le nom est un champ de saisie.
        expect(screen.getByDisplayValue('Maïs')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Annuler/ }));

        await waitFor(() => expect(screen.queryByDisplayValue('Maïs')).toBeNull());
        expect(screen.queryByText('Maïs')).toBeNull();
        expect(screen.getByText('Colza')).toBeInTheDocument();
        expect(screen.queryByText('Masquée')).toBeNull();
    });

    describe('durée des bannières', () => {
        const openEdit = async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            render(<CotationsIndex {...props} />);
            await screen.findByText('Colza');
            fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
            return screen.findByRole('button', { name: /Afficher Maïs/ });
        };
        const advance = (ms) => act(() => { vi.advanceTimersByTime(ms); });

        it('une confirmation reste affichée exactement 10 secondes', async () => {
            fireEvent.click(await openEdit());
            await screen.findByText('Céréale rendue visible dans les cotations.');

            advance(9900);
            expect(screen.getByText('Céréale rendue visible dans les cotations.')).toBeInTheDocument();
            advance(200);
            expect(screen.queryByText('Céréale rendue visible dans les cotations.')).toBeNull();
        });

        it('un second message relance le délai : l’ancien timer ne le retire pas trop tôt', async () => {
            fireEvent.click(await openEdit());
            await screen.findByText('Céréale rendue visible dans les cotations.');
            advance(6000);

            fireEvent.click(await screen.findByRole('button', { name: /Masquer Maïs/ }));
            await screen.findByText('Céréale masquée des cotations.');
            advance(6000); // 12 s depuis le premier message, 6 s depuis le second
            expect(screen.getByText('Céréale masquée des cotations.')).toBeInTheDocument();
            advance(4100);
            expect(screen.queryByText('Céréale masquée des cotations.')).toBeNull();
        });

        it('une erreur n’est pas retirée automatiquement', async () => {
            mockFetch({ status: 500, body: { message: 'Erreur serveur.' } });
            fireEvent.click(await openEdit());
            await screen.findByText('Erreur serveur.');

            advance(60000);
            expect(screen.getByText('Erreur serveur.')).toBeInTheDocument();
        });
    });
});

describe('Cotations céréales — suppression', () => {
    // DELETE : réponse configurable ; le reste comme mockFetch.
    const mockDelete = (deleteResult) => {
        calls = [];
        global.fetch = vi.fn(async (url, options = {}) => {
            calls.push({ url, method: options.method || 'GET', body: options.body });
            if (options.method === 'DELETE') {
                return { ok: deleteResult.status === 200, status: deleteResult.status, json: async () => deleteResult.body };
            }
            return { ok: true, status: 200, json: async () => (String(url).includes('edit=1') ? edition : consultation) };
        });
    };
    const openEdit = async () => {
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        await screen.findByText('Masquée');
    };

    it('la corbeille n’existe qu’en édition et demande confirmation avant toute suppression', async () => {
        mockDelete({ status: 200, body: { ok: true } });
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        expect(screen.queryByRole('button', { name: /Supprimer Colza/ })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        await screen.findByText('Masquée');

        const trash = screen.getByRole('button', { name: /Supprimer Maïs/ });
        expect(trash).toHaveAttribute('title', 'Supprimer cette céréale');
        fireEvent.click(trash);

        expect(await screen.findByText('Supprimer la céréale ?')).toBeInTheDocument();
        expect(screen.getByText(/« Maïs »/)).toBeInTheDocument();
        expect(screen.getByText(/Son historique et ses anciennes données seront conservés/)).toBeInTheDocument();
        expect(calls.some((call) => call.method === 'DELETE')).toBe(false);

        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Annuler' }));
        await waitFor(() => expect(screen.queryByText('Supprimer la céréale ?')).toBeNull());
        expect(calls.some((call) => call.method === 'DELETE')).toBe(false);
        expect(screen.getByDisplayValue('Maïs')).toBeInTheDocument();
    });

    it('confirmer supprime la céréale (une seule requête malgré les clics répétés) et affiche le message', async () => {
        mockDelete({ status: 200, body: { ok: true, code: 'EMA' } });
        await openEdit();
        fireEvent.click(screen.getByRole('button', { name: /Supprimer Maïs/ }));
        const confirm = await screen.findByRole('button', { name: 'Supprimer la céréale' });

        fireEvent.click(confirm);
        fireEvent.click(confirm);
        fireEvent.click(confirm);

        expect(await screen.findByText('Céréale supprimée. Son historique a été conservé.')).toBeInTheDocument();
        expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(1);
        expect(calls.find((call) => call.method === 'DELETE').url).toBe('/cotations/cereals/EMA');
        await waitFor(() => expect(screen.queryByDisplayValue('Maïs')).toBeNull());
        expect(screen.getByDisplayValue('Colza')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Blé')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByText('Supprimer la céréale ?')).toBeNull());
    });

    it('échec : la céréale reste, l’erreur s’affiche dans la fenêtre et on peut réessayer', async () => {
        mockDelete({ status: 500, body: { message: 'Suppression impossible.' } });
        await openEdit();
        fireEvent.click(screen.getByRole('button', { name: /Supprimer Maïs/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Supprimer la céréale' }));

        expect(await screen.findByText('Suppression impossible.')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Maïs')).toBeInTheDocument();

        mockDelete({ status: 200, body: { ok: true } });
        fireEvent.click(screen.getByRole('button', { name: 'Supprimer la céréale' }));
        await waitFor(() => expect(screen.queryByDisplayValue('Maïs')).toBeNull());
    });
});

