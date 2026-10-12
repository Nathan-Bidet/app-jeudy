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

describe('Cotations céréales — céréales supprimées', () => {
    const deletedList = [
        { code: 'EOB', name: 'Orge', type: 'matif', deleted_at: '2026-10-12T12:30:00Z', deleted_by_name: 'Administrateur' },
        { code: 'C1', name: 'Sarrasin', type: 'custom', deleted_at: '2026-10-11T09:00:00Z', deleted_by_name: null },
    ];
    const editionWithDeleted = (list) => ({ ...edition, deleted_cereals: list });

    // restoreResult : réponse du POST /restore ; après restauration, la vue d'édition contient l'Orge.
    const mockRestore = (restoreResult, list = deletedList) => {
        calls = [];
        let restored = false;
        global.fetch = vi.fn(async (url, options = {}) => {
            calls.push({ url, method: options.method || 'GET', body: options.body });
            if (options.method === 'POST') {
                restored = restoreResult.status === 200;
                return { ok: restoreResult.status === 200, status: restoreResult.status, json: async () => restoreResult.body };
            }
            const body = String(url).includes('edit=1')
                ? (restored
                    ? { ...editionWithDeleted([]), groups: [...edition.groups, group('EOB', 'Orge')], cereal_order: ['ECO', 'EBM', 'EMA', 'EOB'] }
                    : editionWithDeleted(list))
                : consultation;
            return { ok: true, status: 200, json: async () => body };
        });
    };
    const openEdit = async () => {
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        await screen.findByText('Masquée');
    };

    it('section repliée par défaut avec le nombre, absente de la consultation, détails à l’ouverture', async () => {
        mockRestore({ status: 200, body: { ok: true } });
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        expect(screen.queryByText(/Céréales supprimées/i)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        const header = await screen.findByText('Céréales supprimées (2)');
        expect(screen.queryByTestId('deleted-cereal')).toBeNull();

        fireEvent.click(header);
        const items = await screen.findAllByTestId('deleted-cereal');
        expect(items).toHaveLength(2);
        expect(items[0]).toHaveTextContent('Orge');
        expect(items[0]).toHaveTextContent('Type : MATIF');
        expect(items[0]).toHaveTextContent('Par : Administrateur');
        expect(items[0]).toHaveTextContent(/Supprimée le : \d{2}\/\d{2}\/2026 à \d{2}:\d{2}/);
        expect(items[1]).toHaveTextContent('Type : Personnalisée');
        // Auteur inconnu : rien d'inventé.
        expect(items[1]).toHaveTextContent('Par : non renseigné');
    });

    it('affiche « Aucune céréale supprimée. » quand le registre est vide', async () => {
        mockRestore({ status: 200, body: { ok: true } }, []);
        await openEdit();

        fireEvent.click(screen.getByText('Céréales supprimées (0)'));
        expect(await screen.findByText('Aucune céréale supprimée.')).toBeInTheDocument();
    });

    it('restaurer : confirmation, une seule requête malgré les clics répétés, la céréale revient en édition', async () => {
        mockRestore({ status: 200, body: { ok: true, deleted_cereals: [deletedList[1]] } });
        await openEdit();
        fireEvent.click(screen.getByText('Céréales supprimées (2)'));
        fireEvent.click(await screen.findByRole('button', { name: 'Restaurer Orge' }));

        const dialog = await screen.findByRole('alertdialog');
        expect(within(dialog).getByText('Restaurer cette céréale ?')).toBeInTheDocument();
        expect(within(dialog).getByText(/« Orge »/)).toBeInTheDocument();
        expect(calls.some((call) => call.method === 'POST')).toBe(false);

        const confirm = within(dialog).getByRole('button', { name: 'Restaurer la céréale' });
        fireEvent.click(confirm);
        fireEvent.click(confirm);

        expect(await screen.findByText('Céréale restaurée avec succès.')).toBeInTheDocument();
        expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1);
        expect(calls.find((call) => call.method === 'POST').url).toBe('/cotations/cereals/EOB/restore');
        // Céréale non éditable par son titre (produit MATIF hors Colza/Blé/Maïs) : titre en texte.
        await waitFor(() => expect(screen.getAllByText('Orge').length).toBeGreaterThan(0));
        // La section (restée ouverte) ne contient plus que Sarrasin.
        const remaining = screen.getAllByTestId('deleted-cereal');
        expect(remaining).toHaveLength(1);
        expect(remaining[0]).toHaveTextContent('Sarrasin');
        expect(screen.getByText('Céréales supprimées (1)')).toBeInTheDocument();
    });

    it('échec : la céréale reste dans les supprimées et l’erreur est expliquée', async () => {
        mockRestore({ status: 500, body: { message: 'Restauration impossible.' } });
        await openEdit();
        fireEvent.click(screen.getByText('Céréales supprimées (2)'));
        fireEvent.click(await screen.findByRole('button', { name: 'Restaurer Orge' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Restaurer la céréale' }));

        expect(await screen.findByText('Restauration impossible.')).toBeInTheDocument();
        expect(screen.getByText('Céréales supprimées (2)')).toBeInTheDocument();
    });
});

describe('Cotations céréales — bandeau repliable', () => {
    const customEdition = {
        ...edition,
        groups: [...edition.groups, group('C1', 'Sarrasin')],
        custom_cereals: [{ id: 1, code: 'C1', name: 'Sarrasin', base_product_code: 'EBM', sort_order: 100 }],
        cereal_order: ['ECO', 'EBM', 'EMA', 'C1'],
    };
    const mockEdition = () => {
        calls = [];
        global.fetch = vi.fn(async (url, options = {}) => {
            calls.push({ url, method: options.method || 'GET' });
            if (options.method === 'PUT') {
                return { ok: true, status: 200, json: async () => ({ ok: true, code: 'ECO', visible: false, hidden_cereal_codes: ['EMA', 'ECO'] }) };
            }
            return { ok: true, status: 200, json: async () => (String(url).includes('edit=1') ? customEdition : consultation) };
        });
    };
    const toggleOf = (section) => section.querySelector('[data-toggle-main]');
    const expanded = (section) => toggleOf(section).getAttribute('aria-expanded') === 'true';
    const sectionOf = (element) => element.closest('section');
    const headerOf = (section) => toggleOf(section).parentElement;

    it('consultation : un clic sur la zone libre du bandeau déplie, un second replie, sans double déclenchement', async () => {
        render(<CotationsIndex {...props} />);
        const section = sectionOf(await screen.findByText('Colza'));
        expect(expanded(section)).toBe(false);

        fireEvent.click(headerOf(section));
        expect(expanded(section)).toBe(true);
        fireEvent.click(headerOf(section));
        expect(expanded(section)).toBe(false);

        // La flèche (commande principale) bascule exactement une fois.
        fireEvent.click(section.querySelector('svg.lucide-chevron-right'));
        expect(expanded(section)).toBe(true);
        fireEvent.click(toggleOf(section));
        expect(expanded(section)).toBe(false);
    });

    it('édition : la zone libre bascule, les commandes du bandeau restent indépendantes', async () => {
        mockEdition();
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        const section = sectionOf(await screen.findByDisplayValue('Colza'));

        // Zones libres : en-tête, texte d'information à côté du nom.
        fireEvent.click(headerOf(section));
        expect(expanded(section)).toBe(true);
        fireEvent.click(screen.getAllByText(/Céréale MATIF d'origine/)[0]);
        expect(expanded(section)).toBe(false);

        // Champ du nom, œil, corbeille, poignée : aucun changement d'état.
        fireEvent.click(screen.getByDisplayValue('Colza'));
        expect(expanded(section)).toBe(false);
        fireEvent.click(within(section).getByRole('button', { name: /Masquer Colza/ }));
        expect(expanded(section)).toBe(false);
        await waitFor(() => expect(calls.some((call) => call.method === 'PUT')).toBe(true));
        fireEvent.click(within(section).getByRole('button', { name: /Supprimer Colza/ }));
        expect(expanded(section)).toBe(false);
        expect(await screen.findByText('Supprimer la céréale ?')).toBeInTheDocument();
        const handle = within(section).getByRole('button', { name: /Déplacer Colza/ });
        fireEvent.dragStart(handle, { dataTransfer: { setData: vi.fn(), effectAllowed: '' } });
        fireEvent.click(handle);
        expect(expanded(section)).toBe(false);
    });

    it('édition : céréale masquée et céréale personnalisée se déplient ; le sélecteur de référence ne déplie pas', async () => {
        mockEdition();
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        const hidden = sectionOf(await screen.findByDisplayValue('Maïs'));
        const custom = sectionOf(await screen.findByDisplayValue('Sarrasin'));

        fireEvent.click(headerOf(hidden));
        expect(expanded(hidden)).toBe(true);
        expect(within(hidden).getByText('Masquée')).toBeInTheDocument();

        const select = within(custom).getByLabelText('Céréale de base');
        fireEvent.click(select);
        fireEvent.change(select, { target: { value: 'EMA' } });
        expect(expanded(custom)).toBe(false);
        fireEvent.click(headerOf(custom));
        expect(expanded(custom)).toBe(true);
    });

    it('clavier : Entrée et Espace sur la commande principale, une seule bascule, aria-expanded cohérent', async () => {
        mockEdition();
        render(<CotationsIndex {...props} />);
        await screen.findByText('Colza');
        fireEvent.click(screen.getByRole('button', { name: /Modifier/ }));
        const section = sectionOf(await screen.findByDisplayValue('Colza'));
        const main = toggleOf(section);

        fireEvent.keyDown(main, { key: 'Enter' });
        expect(main).toHaveAttribute('aria-expanded', 'true');
        fireEvent.keyDown(main, { key: ' ' });
        expect(main).toHaveAttribute('aria-expanded', 'false');
        // Saisie dans le champ du nom : Espace/Entrée ne plient pas la céréale.
        fireEvent.keyDown(screen.getByDisplayValue('Colza'), { key: ' ' });
        fireEvent.keyDown(screen.getByDisplayValue('Colza'), { key: 'Enter' });
        expect(main).toHaveAttribute('aria-expanded', 'false');
    });
});

