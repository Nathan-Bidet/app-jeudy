import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';

import CerealHistoryBar from '@/Components/Cotations/CerealHistoryBar';
import CerealHistoryCharts from '@/Components/Cotations/CerealHistoryCharts';
import { buildSegments, computeRange, enumerateDays, seriesLabel, validateCustomRange } from '@/Support/cerealHistory';

afterEach(() => cleanup());

describe('périodes', () => {
    it('calcule les cinq périodes se terminant à la date consultée', () => {
        expect(computeRange('7d', '2026-09-30')).toEqual({ from: '2026-09-24', to: '2026-09-30' });
        expect(computeRange('30d', '2026-09-30')).toEqual({ from: '2026-09-01', to: '2026-09-30' });
        expect(computeRange('3m', '2026-09-30')).toEqual({ from: '2026-07-01', to: '2026-09-30' });
        expect(computeRange('1y', '2026-09-30')).toEqual({ from: '2025-10-01', to: '2026-09-30' });
        expect(computeRange('custom', '2026-09-30')).toBeNull();
        expect(enumerateDays('2026-09-24', '2026-09-30')).toHaveLength(7);
    });

    it('gère les fins de mois et les années bissextiles', () => {
        expect(computeRange('3m', '2026-05-31')).toEqual({ from: '2026-03-01', to: '2026-05-31' });
        expect(computeRange('1y', '2028-02-29')).toEqual({ from: '2027-03-01', to: '2028-02-29' });
    });

    it('valide la plage personnalisée', () => {
        expect(validateCustomRange('', '2026-09-30')).toMatch(/Indiquez/);
        expect(validateCustomRange('2026-09-30', '2026-09-01')).toMatch(/postérieure ou égale/);
        expect(validateCustomRange('2024-01-01', '2026-01-01')).toMatch(/un an/);
        expect(validateCustomRange('2026-09-01', '2026-09-30')).toBe('');
    });

    it('interrompt la courbe quand une journée n’a pas de cotation', () => {
        const days = enumerateDays('2026-09-28', '2026-10-02');
        const points = ['2026-09-28', '2026-09-29', '2026-10-01', '2026-10-02'].map((date) => ({ date }));

        expect(buildSegments(points, days).map((segment) => segment.map((p) => p.date))).toEqual([
            ['2026-09-28', '2026-09-29'],
            ['2026-10-01', '2026-10-02'],
        ]);
    });

    it('nomme une série par sa ligne de cotation et sa récolte', () => {
        expect(seriesLabel({ display_label: 'Moisson', maturity_label: 'Août 2026', harvest_year: 2026 })).toBe('Moisson — Août 2026 · récolte 2026');
        expect(seriesLabel({ display_label: null, maturity_label: 'Novembre 2026', harvest_year: 2026 })).toBe('Novembre 2026 · récolte 2026');
    });
});

const series = (key, display, maturity, harvest, points) => ({ key, display_label: display, maturity_label: maturity, harvest_year: harvest, points });
const pt = (date, price, extra = {}) => ({ date, price, matif: price, carried_quote_at: null, ...extra });

const PAYLOAD = {
    from: '2026-09-01',
    to: '2026-09-30',
    dates: ['2026-09-01', '2026-09-02', '2026-09-04'],
    cereals: [
        {
            code: 'ECO',
            name: 'Colza',
            series: [
                series('a', 'Moisson', 'Août 2026', 2026, [pt('2026-09-01', 507, { carried_quote_at: '2026-07-30' }), pt('2026-09-02', 507, { carried_quote_at: '2026-07-30' }), pt('2026-09-04', 510)]),
                series('b', 'Nov - Dec', 'Novembre 2026', 2026, [pt('2026-09-01', 540), pt('2026-09-02', 545)]),
            ],
        },
        { code: 'EBM', name: 'Blé', series: [series('c', 'Moisson blé', 'Décembre 2026', 2026, [pt('2026-09-02', 210)])] },
    ],
};

function mockFetch(handler) {
    global.fetch = vi.fn(async (url) => {
        const result = handler(String(url));
        return { ok: result.status === 200, status: result.status, json: async () => result.body };
    });
}
const lastUrl = () => new URL(global.fetch.mock.calls.at(-1)[0], 'http://x');
const renderCharts = (endDate = '2026-09-30') => render(<CerealHistoryCharts seriesUrl="/series" endDate={endDate} />);

describe('CerealHistoryCharts', () => {
    beforeEach(() => mockFetch(() => ({ status: 200, body: PAYLOAD })));

    it('charge par défaut les 30 derniers jours se terminant à la date consultée', async () => {
        renderCharts();
        await screen.findByRole('group', { name: 'Courbes des prix finaux — Colza' });

        expect(lastUrl().pathname).toBe('/series');
        expect(lastUrl().searchParams.get('from')).toBe('2026-09-01');
        expect(lastUrl().searchParams.get('to')).toBe('2026-09-30');
        expect(screen.getByRole('button', { name: '30 derniers jours' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByTestId('chart-range')).toHaveTextContent('3 journée(s) avec cotation');
    });

    it('propose les cinq périodes et recharge avec la bonne plage', async () => {
        renderCharts();
        await screen.findByText('Colza');

        for (const [label, from] of [['7 derniers jours', '2026-09-24'], ['3 derniers mois', '2026-07-01'], ['1 an', '2025-10-01']]) {
            fireEvent.click(screen.getByRole('button', { name: label }));
            await waitFor(() => expect(lastUrl().searchParams.get('from')).toBe(from));
            expect(lastUrl().searchParams.get('to')).toBe('2026-09-30');
        }
        expect(screen.getByRole('button', { name: 'Période personnalisée' })).toBeInTheDocument();
    });

    it('période personnalisée : validation des dates puis chargement de la plage', async () => {
        renderCharts();
        await screen.findByText('Colza');
        const calls = global.fetch.mock.calls.length;

        fireEvent.click(screen.getByRole('button', { name: 'Période personnalisée' }));
        fireEvent.click(screen.getByRole('button', { name: 'Valider' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Indiquez une date de début');

        fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-09-20' } });
        fireEvent.change(screen.getByLabelText('Au'), { target: { value: '2026-09-10' } });
        fireEvent.click(screen.getByRole('button', { name: 'Valider' }));
        expect(screen.getByRole('alert')).toHaveTextContent('postérieure ou égale');
        expect(global.fetch.mock.calls.length).toBe(calls); // aucune requête avec une plage invalide

        fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-08-01' } });
        fireEvent.change(screen.getByLabelText('Au'), { target: { value: '2026-08-31' } });
        fireEvent.click(screen.getByRole('button', { name: 'Valider' }));
        await waitFor(() => expect(lastUrl().searchParams.get('from')).toBe('2026-08-01'));
        expect(lastUrl().searchParams.get('to')).toBe('2026-08-31');
    });

    it('suit la date consultée pour les périodes prédéfinies', async () => {
        const { rerender } = renderCharts('2026-09-30');
        await screen.findByText('Colza');

        rerender(<CerealHistoryCharts seriesUrl="/series" endDate="2026-09-15" />);
        await waitFor(() => expect(lastUrl().searchParams.get('to')).toBe('2026-09-15'));
        expect(lastUrl().searchParams.get('from')).toBe('2026-08-17');
    });

    it('affiche un graphique par céréale avec courbes et points, séries distinctes et légende', async () => {
        renderCharts();
        const colza = await screen.findByRole('region', { name: 'Évolution des prix — Colza' });

        expect(screen.getByRole('region', { name: 'Évolution des prix — Blé' })).toBeInTheDocument();
        expect(colza.querySelectorAll('[data-point]')).toHaveLength(5);          // 3 + 2 points
        expect(colza.querySelectorAll('[data-series]')).toHaveLength(2);          // 2 séries = 2 lignes de cotation
        expect(colza.querySelectorAll('polyline').length).toBe(2);               // une courbe par série (les points isolés restent visibles)
        const legend = within(colza).getByRole('list', { name: 'Légende — Colza' });
        expect(legend).toHaveTextContent('Moisson — Août 2026 · récolte 2026');
        expect(legend).toHaveTextContent('Nov - Dec — Novembre 2026 · récolte 2026');
        const colors = [...colza.querySelectorAll('[data-series] polyline')].map((line) => line.getAttribute('stroke'));
        expect(new Set(colors).size).toBe(2);
    });

    it('infobulle au survol : date, prix en €/t, céréale et série ; échéance échue signalée', async () => {
        renderCharts();
        const colza = await screen.findByRole('region', { name: 'Évolution des prix — Colza' });

        fireEvent.mouseEnter(colza.querySelector('[data-point="a|2026-09-01"]'));
        const tip = screen.getByTestId('chart-tooltip');
        expect(tip).toHaveTextContent('Colza');
        expect(tip).toHaveTextContent('Moisson — Août 2026 · récolte 2026');
        expect(tip).toHaveTextContent('01/09/2026');
        expect(tip).toHaveTextContent(/507\s€\/t/);
        expect(tip).toHaveTextContent('dernier cours du 30/07/2026');

        fireEvent.mouseEnter(colza.querySelector('[data-point="b|2026-09-02"]'));
        expect(screen.getByTestId('chart-tooltip')).toHaveTextContent(/545\s€\/t/);
        expect(screen.getByTestId('chart-tooltip')).not.toHaveTextContent('dernier cours du');
    });

    it('au toucher/clic : l’infobulle reste affichée jusqu’à un second clic', async () => {
        renderCharts();
        const colza = await screen.findByRole('region', { name: 'Évolution des prix — Colza' });
        const point = colza.querySelector('[data-point="b|2026-09-01"]');

        fireEvent.click(point);
        fireEvent.mouseLeave(point.closest('div'));
        expect(screen.getByTestId('chart-tooltip')).toHaveTextContent(/540\s€\/t/);

        fireEvent.click(point);
        expect(screen.queryByTestId('chart-tooltip')).toBeNull();
    });

    it('au clavier : focus sur un point, flèches entre les points d’une série, Échap', async () => {
        renderCharts();
        const colza = await screen.findByRole('region', { name: 'Évolution des prix — Colza' });
        const first = colza.querySelector('[data-point="a|2026-09-01"]');

        expect(first).toHaveAttribute('tabindex', '0');
        expect(first).toHaveAttribute('aria-label', 'Colza, Moisson — Août 2026 · récolte 2026, 01/09/2026 : 507 €/t');
        act(() => first.focus());
        expect(screen.getByTestId('chart-tooltip')).toHaveTextContent('01/09/2026');

        act(() => { fireEvent.keyDown(first, { key: 'ArrowRight' }); });
        expect(colza.querySelector('[data-point="a|2026-09-02"]')).toHaveFocus();
        act(() => { fireEvent.keyDown(document.activeElement, { key: 'ArrowRight' }); });
        expect(colza.querySelector('[data-point="a|2026-09-04"]')).toHaveFocus();

        fireEvent.keyDown(document.activeElement, { key: 'Escape' });
        expect(screen.queryByTestId('chart-tooltip')).toBeNull();
    });

    it('relie seulement les journées consécutives : un trou de cotation coupe la courbe (le point isolé reste visible)', async () => {
        renderCharts();
        const colza = await screen.findByRole('region', { name: 'Évolution des prix — Colza' });
        const moisson = colza.querySelector('[data-series="a"]');

        // 01/09 et 02/09 consécutifs → un segment ; 04/09 isolé (le 03/09 n'a pas de cotation) → pas de trait vers lui.
        expect(moisson.querySelectorAll('polyline')).toHaveLength(1);
        expect(moisson.querySelector('polyline').getAttribute('points').split(' ')).toHaveLength(2);
        expect(moisson.querySelectorAll('[data-point]')).toHaveLength(3);
    });

    it('période sans résultat : message clair', async () => {
        mockFetch(() => ({ status: 200, body: { from: '2026-09-01', to: '2026-09-30', dates: [], cereals: [] } }));
        renderCharts();

        expect(await screen.findByText('Aucune cotation sur cette période.')).toBeInTheDocument();
    });

    it('erreur serveur (plage refusée) : message de validation affiché', async () => {
        mockFetch(() => ({ status: 422, body: { errors: { to: ['La période ne peut pas dépasser un an (366 jours).'] } } }));
        renderCharts();

        expect(await screen.findByRole('alert')).toHaveTextContent('La période ne peut pas dépasser un an (366 jours).');
    });

    it('reste lisible sur mobile : graphique à largeur fluide (viewBox), légende qui passe à la ligne', async () => {
        renderCharts();
        const colza = await screen.findByRole('region', { name: 'Évolution des prix — Colza' });
        const svg = colza.querySelector('svg');

        expect(svg.getAttribute('viewBox')).toBe('0 0 720 300');
        expect(svg.getAttribute('class')).toContain('w-full');
        expect(within(colza).getByRole('list', { name: 'Légende — Colza' }).className).toContain('flex-wrap');
    });
});

describe('barre d’historique — bouton Graphiques', () => {
    const DATES = ['2026-10-02', '2026-10-01'];

    it('est placé exactement entre « Plus récente » et « Fermer l’historique »', () => {
        render(<CerealHistoryBar dates={DATES} activeDate="2026-10-01" onToggleCharts={() => {}} onSelectDate={() => {}} onClose={() => {}} />);

        const labels = within(screen.getByRole('group', { name: /Navigation/ })).getAllByRole('button').map((b) => b.textContent.trim());
        expect(labels).toEqual(['Plus ancienne', '01/10/2026', 'Plus récente', 'Graphiques', "Fermer l'historique"]);
    });

    it('bascule l’ouverture des graphiques (aria-pressed) et reste absent sans gestionnaire', () => {
        const toggle = vi.fn();
        const { rerender } = render(<CerealHistoryBar dates={DATES} activeDate="2026-10-01" chartsOpen={false} onToggleCharts={toggle} onSelectDate={() => {}} onClose={() => {}} />);

        const button = screen.getByRole('button', { name: 'Graphiques' });
        expect(button).toHaveAttribute('aria-pressed', 'false');
        fireEvent.click(button);
        expect(toggle).toHaveBeenCalledTimes(1);

        rerender(<CerealHistoryBar dates={DATES} activeDate="2026-10-01" chartsOpen onToggleCharts={toggle} onSelectDate={() => {}} onClose={() => {}} />);
        expect(screen.getByRole('button', { name: 'Graphiques' })).toHaveAttribute('aria-pressed', 'true');

        rerender(<CerealHistoryBar dates={DATES} activeDate="2026-10-01" onSelectDate={() => {}} onClose={() => {}} />);
        expect(screen.queryByRole('button', { name: 'Graphiques' })).toBeNull();
    });
});
