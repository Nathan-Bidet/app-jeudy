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

// Forme renvoyée par le serveur : mêmes cartes que la vue d'origine (MATIF, base, prix final),
// reconstituées à la date (relevé de 15 h + réglages d'alors).
const versionFor = (date, wheatName, matif, margin, extra = {}) => ({
    business_date: date,
    recorded_at: `${date}T15:00:04+02:00`,
    target_time: '15:00',
    market: {
        ...currentMarket,
        groups: [{
            ...group('EBM', wheatName, matif),
            harvests: {
                left: { year: 2026, rows: [{ ...group('EBM', wheatName, matif).harvests.left.rows[0], margin, margin_operation: 'subtract', final_price: matif - margin }] },
                right: { year: 2027, rows: [] },
            },
        }],
        cereal_info_html: `<p>Info du ${date}</p>`,
        fetched_at: `${date}T15:00:04+02:00`,
    },
    transport_grid: null,
    warnings: [],
    ...extra,
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
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5, 11.5) } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));

        const mode = await screen.findByTestId('cereal-history-mode');
        expect(mode).toHaveTextContent('cotations du 02/10/2026');
        expect(mode).toHaveTextContent('lecture seule');
        // Valeurs de la journée, pas les valeurs actuelles.
        expect(screen.getByText('Blé du 2 octobre')).toBeInTheDocument();
        expect(screen.getByTestId('cereal-history-notes')).toHaveTextContent('relevé de 15:00');
        // Info historisée de la date, pas le texte actuel.
        expect(screen.getByText('Info du 2026-10-02')).toBeInTheDocument();
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
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5, 11.5) } }),
            '/hist/2026-09-28': () => ({ status: 200, body: { version: versionFor('2026-09-28', 'Blé du 28 septembre', 180, 10) } }),
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
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5, 11.5) } }),
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

describe('Cotations céréales — rendu d’origine en mode historique', () => {
    it('affiche les mêmes colonnes que la vue actuelle avec base et prix final de la date', async () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5, 11.5) } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');

        fireEvent.click(screen.getByText('Blé du 2 octobre')); // déplie la carte, comme la vue d'origine

        ['Livraison', 'Échéance', 'MATIF', 'Base', 'Prix final'].forEach((header) => {
            expect(screen.getAllByText(header).length).toBeGreaterThan(0);
        });
        // prix final = MATIF 231,5 − base 11,5 = 220, et la base d'alors (11,5) est restituée
        expect(screen.getAllByText(/220/).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/231|232/).length).toBeGreaterThan(0);
    });

    it('affiche l’avertissement de reconstitution et reste en lecture seule', async () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé', 231.5, 11.5, { warnings: ["Le texte d'information de cette date n'a pas pu être reconstitué (journal tronqué à 4 000 caractères)."] }) } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));

        expect(await screen.findByRole('note')).toHaveTextContent('4 000 caractères');
        expect(screen.queryByRole('button', { name: /Modifier les cotations/ })).toBeNull();
    });

    it('journée sans relevé de 15 h : message clair, vue précédente conservée', async () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02', '2026-10-01'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé', 231.5, 11.5) } }),
            '/hist/2026-10-01': () => ({ status: 404, body: { status: 'no_target', message: "Le relevé de 15 h n'est pas disponible pour cette journée." } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');

        fireEvent.click(screen.getByRole('button', { name: /Plus ancienne/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent("Le relevé de 15 h n'est pas disponible");
        expect(screen.getByTestId('cereal-history-mode')).toHaveTextContent('cotations du 02/10/2026');
    });
});

describe('Cotations céréales — le MATIF change avec la date (régression « cours figé »)', () => {
    const row = (hash, label, matif, margin, op) => ({
        identity_hash: `line-${hash}`,
        market_identity_hash: hash,
        line_type: 'matif',
        product_code: 'ECO',
        product_name: 'Colza',
        display_label: `Ligne ${label}`,
        label,
        maturity_label: label,
        maturity_year: 2026,
        harvest_year: 2026,
        matif,
        margin,
        margin_operation: op,
        final_price: op === 'add' ? matif + margin : matif - margin,
    });

    const day = (date, rows) => ({
        business_date: date,
        recorded_at: `${date}T15:00:03+02:00`,
        target_time: '15:00',
        market: {
            ...currentMarket,
            groups: [{ code: 'ECO', name: 'Colza', sort: 10, harvests: { left: { year: 2026, rows }, right: { year: 2027, rows: [] } } }],
            cereal_info_html: '',
            options: [],
            fetched_at: `${date}T15:00:03+02:00`,
        },
        transport_grid: null,
        warnings: [],
    });

    // 12/08 : Novembre 500 (base −10) et Février 520 (base −5) ; 30/09 : Novembre 538,75 (base +10) et Février 555 (base +5).
    const AUG = day('2026-08-12', [row('h-nov', 'Novembre 2026', 500, 10, 'subtract'), row('h-feb', 'Février 2027', 520, 5, 'subtract')]);
    const SEP = day('2026-09-30', [row('h-nov', 'Novembre 2026', 538.75, 10, 'add'), row('h-feb', 'Février 2027', 555, 5, 'add')]);

    // Cours ACTUELS périmés pour les mêmes contrats : ils ne doivent jamais s'afficher sur une date passée.
    const staleOptions = [{
        code: 'ECO',
        harvests: { left: { year: 2026, rows: [
            { identity_hash: 'h-nov', matif: 490, label: 'Novembre 2026' },
            { identity_hash: 'h-feb', matif: 490, label: 'Février 2027' },
        ] }, right: { year: 2027, rows: [] } },
    }];

    const setup = () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-09-30', '2026-08-12'] } }),
            '/hist/2026-09-30': () => ({ status: 200, body: { version: SEP } }),
            '/hist/2026-08-12': () => ({ status: 200, body: { version: AUG } }),
        });
        render(<CotationsIndex {...props} marketData={{ ...currentMarket, options: staleOptions }} />);
    };

    const open = async () => {
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');
        fireEvent.click(screen.getByText('Colza'));
    };

    it('affiche les cours, bases et prix finaux de chaque journée, sans cours actuel ni 490 figé', async () => {
        setup();
        await open();

        // 30/09 : MATIF 538,75 / 555, bases +10 / +5, prix finaux 549 / 560.
        expect(screen.getByText(/538,75/)).toBeInTheDocument();
        expect(screen.getByText(/555,00/)).toBeInTheDocument();
        expect(screen.getAllByText(/549/).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/560/).length).toBeGreaterThan(0);
        expect(screen.queryByText(/490/)).toBeNull();

        // Bouton « Plus ancienne » → 12/08 : MATIF 500 / 520, bases −10 / −5, prix finaux 490 / 515.
        fireEvent.click(screen.getByRole('button', { name: /Plus ancienne/ }));
        await waitFor(() => expect(screen.getByTestId('cereal-history-mode')).toHaveTextContent('12/08/2026'));

        expect(screen.getByText(/500,00/)).toBeInTheDocument();
        expect(screen.getByText(/520,00/)).toBeInTheDocument();
        expect(screen.queryByText(/538,75/)).toBeNull();
        expect(screen.getAllByText(/515/).length).toBeGreaterThan(0);
    });

    it('met à jour tous les cours aussi via le calendrier, dans les deux sens', async () => {
        setup();
        await open();

        fireEvent.click(screen.getByRole('button', { name: /30\/09\/2026/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Mois précédent' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Mois précédent' }));
        fireEvent.click(screen.getByRole('button', { name: '12/08/2026, version disponible' }));
        await waitFor(() => expect(screen.getByTestId('cereal-history-mode')).toHaveTextContent('12/08/2026'));
        expect(screen.getByText(/500,00/)).toBeInTheDocument();
        expect(screen.queryByText(/555,00/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Plus récente/ }));
        await waitFor(() => expect(screen.getByTestId('cereal-history-mode')).toHaveTextContent('30/09/2026'));
        expect(screen.getByText(/538,75/)).toBeInTheDocument();
        expect(screen.queryByText(/500,00/)).toBeNull();
    });

    it('revient aux cotations actuelles à la fermeture, avec leurs propres valeurs', async () => {
        setup();
        await open();
        fireEvent.click(screen.getByRole('button', { name: "Fermer l'historique" }));

        expect(screen.queryByTestId('cereal-history-mode')).toBeNull();
        expect(screen.queryByText(/538,75/)).toBeNull();
        expect(screen.getByText('Information ACTUELLE')).toBeInTheDocument();
    });
});

describe('Cotations céréales — échéance échue : dernier cours repris', () => {
    it('affiche le cours repris, le prix final calculé et la date du dernier cours', async () => {
        const base = versionFor('2026-10-05', 'Colza', 490, 17);
        const row = {
            ...base.market.groups[0].harvests.left.rows[0],
            matif: 490,
            margin: 17,
            margin_operation: 'add',
            final_price: 507,
            carried_quote_at: '2026-07-30T15:00:05+02:00',
        };
        const version = {
            ...base,
            market: { ...base.market, groups: [{ ...base.market.groups[0], harvests: { ...base.market.groups[0].harvests, left: { year: 2026, rows: [row] } } }] },
            warnings: ["1 ligne(s) reprennent le dernier cours connu d'une échéance échue (date indiquée sous le cours) : ce n'est pas une nouvelle cotation du contrat."],
        };
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-05'] } }),
            '/hist/2026-10-05': () => ({ status: 200, body: { version } }),
        });
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');
        fireEvent.click(screen.getByText('Colza'));

        expect(screen.getByText(/490,00/)).toBeInTheDocument();
        expect(screen.getByText('dernier cours du 30/07/2026')).toBeInTheDocument();
        expect(screen.getAllByText(/507/).length).toBeGreaterThan(0);
        expect(screen.getByRole('note')).toHaveTextContent('reprennent le dernier cours connu');
    });
});


describe('Cotations céréales — panneau d’historique fixé pendant le défilement', () => {
    const twoDays = () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02', '2026-09-28'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5, 11.5) } }),
            '/hist/2026-09-28': () => ({ status: 200, body: { version: versionFor('2026-09-28', 'Blé du 28 septembre', 180, 10) } }),
        });
    };

    it('est sticky sous la navigation principale, sous la navigation (z-30) et sous le calendrier (z-120)', async () => {
        twoDays();
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        const panel = await screen.findByTestId('cereal-history-mode');

        expect(panel.className).toContain('sticky');
        expect(panel.className).toContain('top-[var(--app-navbar-height,72px)]');
        expect(panel.className).toContain('z-20');
        expect(panel.className).toContain('bg-[var(--app-surface)]'); // opaque : les tableaux ne transparaissent pas

        fireEvent.click(screen.getByRole('button', { name: /02\/10\/2026/ }));
        expect(screen.getByRole('dialog').className).toContain('z-[120]');
    });

    it('ne crée aucun conteneur de défilement entre le panneau et la page (sticky opérant) et ne change rien hors historique', async () => {
        twoDays();
        render(<CotationsIndex {...props} />);

        // Affichage actuel : comportement d'origine conservé (overflow-x-hidden), aucun panneau fixé.
        expect(document.querySelector('div.space-y-5.min-w-0').className).toContain('overflow-x-hidden');
        expect(screen.queryByTestId('cereal-history-mode')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        const panel = await screen.findByTestId('cereal-history-mode');

        // Historique : overflow-x-clip (pas de conteneur de défilement) et le panneau est enfant direct du conteneur de contenu.
        const container = document.querySelector('div.space-y-5.min-w-0');
        expect(container.className).toContain('overflow-x-clip');
        expect(container.className).not.toContain('overflow-x-hidden');
        for (let node = panel.parentElement; node && node !== container; node = node.parentElement) {
            expect(getComputedStyle(node).overflow).not.toMatch(/hidden|auto|scroll/);
        }

        fireEvent.click(screen.getByRole('button', { name: "Fermer l'historique" }));
        expect(document.querySelector('div.space-y-5.min-w-0').className).toContain('overflow-x-hidden');
    });

    it('change de journée sans remonter la page et garde la section ouverte pour comparer', async () => {
        twoDays();
        const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');
        fireEvent.click(screen.getByText('Blé du 2 octobre')); // ouvre la section

        expect(screen.getAllByText('Échéance').length).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('button', { name: /Plus ancienne/ }));
        await waitFor(() => expect(screen.getByTestId('cereal-history-mode')).toHaveTextContent('28/09/2026'));

        // Même section (même céréale) toujours ouverte, avec les valeurs de l'autre journée.
        expect(screen.getByText('Blé du 28 septembre')).toBeInTheDocument();
        expect(screen.getAllByText('Échéance').length).toBeGreaterThan(0);
        expect(scrollTo).not.toHaveBeenCalled();
    });

    it('réserve de la place pour le panneau lors d’un défilement vers une section (scroll-margin)', async () => {
        twoDays();
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        await screen.findByTestId('cereal-history-mode');

        const card = document.querySelector('[data-cereal-code="EBM"]');
        expect(card.style.scrollMarginTop).toContain('var(--app-navbar-height,72px)');
    });

    it('garde les boutons accessibles : libellés et ordre de tabulation (Plus ancienne, date, Plus récente, Fermer)', async () => {
        twoDays();
        render(<CotationsIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Historique/ }));
        const panel = await screen.findByTestId('cereal-history-mode');

        const labels = [...panel.querySelectorAll('button')].map((b) => b.textContent.trim());
        expect(labels).toEqual(['Plus ancienne', '02/10/2026', 'Plus récente', "Fermer l'historique"]);
    });
});

describe('Cotations céréales — graphiques dans l’historique', () => {
    const SERIES = {
        from: '2026-09-03',
        to: '2026-10-02',
        dates: ['2026-09-28', '2026-10-02'],
        cereals: [{ code: 'EBM', name: 'Blé', series: [{ key: 's1', display_label: 'Moisson', maturity_label: 'Septembre 2026', harvest_year: 2026, points: [
            { date: '2026-09-28', price: 180, matif: 190, carried_quote_at: null },
            { date: '2026-10-02', price: 220, matif: 231.5, carried_quote_at: null },
        ] }] }],
    };

    const setup = () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02', '2026-09-28'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5, 11.5) } }),
            '/hist/2026-09-28': () => ({ status: 200, body: { version: versionFor('2026-09-28', 'Blé du 28 septembre', 180, 10) } }),
            default: (url) => (url.startsWith('/hist/series') ? { status: 200, body: SERIES } : { status: 404, body: {} }),
        });
        render(<CotationsIndex {...props} routes={{ ...routes, cereal_history_series: '/hist/series' }} />);
    };

    const openHistory = async () => {
        fireEvent.click(screen.getByRole('button', { name: /^Historique/ }));
        return screen.findByTestId('cereal-history-mode');
    };

    it('ouvre les graphiques sous la barre (30 derniers jours se terminant à la date consultée), sans quitter les tableaux', async () => {
        setup();
        const panel = await openHistory();

        // Bouton placé entre « Plus récente » et « Fermer l'historique », barre toujours fixée.
        const labels = [...panel.querySelectorAll('button')].map((b) => b.textContent.trim());
        expect(labels).toEqual(['Plus ancienne', '02/10/2026', 'Plus récente', 'Graphiques', "Fermer l'historique"]);
        expect(panel.className).toContain('sticky');
        expect(screen.queryByTestId('cereal-history-charts')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Graphiques' }));

        const charts = await screen.findByTestId('cereal-history-charts');
        const url = new URL(global.fetch.mock.calls.filter(([u]) => String(u).startsWith('/hist/series')).at(-1)[0], 'http://x');
        expect(url.searchParams.get('from')).toBe('2026-09-03'); // 30 jours se terminant le 02/10
        expect(url.searchParams.get('to')).toBe('2026-10-02');
        expect(charts).toBeInTheDocument();
        expect(await screen.findByRole('region', { name: 'Évolution des prix — Blé' })).toBeInTheDocument();
        // Les tableaux restent consultables.
        expect(screen.getByText('Blé du 2 octobre')).toBeInTheDocument();
    });

    it('suit la date consultée, puis se referme avec le bouton Graphiques', async () => {
        setup();
        await openHistory();
        fireEvent.click(screen.getByRole('button', { name: 'Graphiques' }));
        await screen.findByTestId('cereal-history-charts');

        fireEvent.click(screen.getByRole('button', { name: /Plus ancienne/ }));
        await waitFor(() => {
            const calls = global.fetch.mock.calls.filter(([u]) => String(u).startsWith('/hist/series'));
            expect(new URL(calls.at(-1)[0], 'http://x').searchParams.get('to')).toBe('2026-09-28');
        });
        expect(screen.getByTestId('cereal-history-charts')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Graphiques' }));
        expect(screen.queryByTestId('cereal-history-charts')).toBeNull();
        expect(screen.getByTestId('cereal-history-mode')).toBeInTheDocument();
    });

    it('« Fermer l’historique » referme aussi les graphiques et revient aux cotations actuelles', async () => {
        setup();
        await openHistory();
        fireEvent.click(screen.getByRole('button', { name: 'Graphiques' }));
        await screen.findByTestId('cereal-history-charts');

        fireEvent.click(screen.getByRole('button', { name: "Fermer l'historique" }));

        expect(screen.queryByTestId('cereal-history-charts')).toBeNull();
        expect(screen.queryByTestId('cereal-history-mode')).toBeNull();
        expect(screen.getByText('Information ACTUELLE')).toBeInTheDocument();

        // Une nouvelle ouverture de l'historique repart sans graphiques.
        fireEvent.click(screen.getByRole('button', { name: /^Historique/ }));
        await screen.findByTestId('cereal-history-mode');
        expect(screen.queryByTestId('cereal-history-charts')).toBeNull();
    });

    it('sans route de graphiques, le bouton n’apparaît pas', async () => {
        mockFetch({
            '/hist/dates': () => ({ status: 200, body: { dates: ['2026-10-02'] } }),
            '/hist/2026-10-02': () => ({ status: 200, body: { version: versionFor('2026-10-02', 'Blé du 2 octobre', 231.5, 11.5) } }),
        });
        render(<CotationsIndex {...props} />);
        await openHistory();

        expect(screen.queryByRole('button', { name: 'Graphiques' })).toBeNull();
    });
});
