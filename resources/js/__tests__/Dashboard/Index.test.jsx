import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Régression React #185 ("Maximum update depth exceeded") : la page
 * d'accueil plantait en écran blanc lorsqu'une annonce longue et déjà lue
 * (donc repliée) était présente, à cause d'une synchronisation d'état
 * enfant → parent par effet (CollapsibleAnnouncementBody signalait son
 * état replié au parent via un callback, qui remontait l'état, ce qui
 * recréait la prop `children` — un nouvel objet JSX à chaque rendu — et
 * redéclenchait l'effet de mesure de CollapsibleAnnouncementBody en boucle).
 *
 * Le correctif dérive l'ordre des blocs directement pendant le rendu à
 * partir de `announcement.has_been_viewed` (valeur stable fournie par le
 * serveur), sans état local ni effet dédié à cet ordre.
 */

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ children }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>{children}</a>
    ),
}));

import DashboardIndex from '@/Pages/Dashboard/Index';

function longHtml(lines = 12) {
    return Array.from({ length: lines }, (_, i) => `<p>Ligne ${i + 1} pour depasser le seuil de 5 lignes.</p>`).join('');
}

function baseDashboard(announcementOverrides) {
    return {
        widgets: [{ key: 'quick-access', title: 'Accès rapides', type: 'quick_links', links: [] }],
        dashboard_announcement: announcementOverrides === null ? null : {
            id: 1,
            title: 'Titre annonce',
            body_html: longHtml(),
            has_been_viewed: false,
            poll: null,
            ...announcementOverrides,
        },
    };
}

describe('Dashboard/Index — ordre Annonce / Accès rapides', () => {
    let errorSpy;

    beforeEach(() => {
        global.route = vi.fn((name, params) => `/${name}/${params ?? ''}`);
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }));
        global.IntersectionObserver = class {
            observe() {}
            disconnect() {}
        };
        global.ResizeObserver = class {
            observe() {}
            disconnect() {}
        };
        document.cookie = '';
        errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        // jsdom n'exécute aucune mise en page réelle : scrollHeight vaut
        // toujours 0. On simule une hauteur proportionnelle au nombre de
        // lignes de contenu (<p>/<li>) pour exercer fidèlement la logique de
        // repli (le calcul lui-même utilise le line-height CSS réel, non
        // testable ici — vérifié manuellement dans un vrai navigateur).
        Object.defineProperty(HTMLElement.prototype, 'scrollHeight', {
            configurable: true,
            get() {
                return this.querySelectorAll('p, li').length * 25;
            },
        });
    });

    afterEach(() => {
        errorSpy.mockRestore();
        cleanup();
        vi.restoreAllMocks();
    });

    function assertNoRenderLoop() {
        const loopErrors = errorSpy.mock.calls.filter((args) =>
            args.some((arg) => typeof arg === 'string' && arg.includes('Maximum update depth')),
        );
        expect(loopErrors).toHaveLength(0);
    }

    it('places the announcement before Accès rapides when it is new/unread (expanded)', () => {
        render(<DashboardIndex dashboard={baseDashboard({ has_been_viewed: false })} />);

        const order = screen.getByTestId('app-layout').textContent;
        expect(order.indexOf('Titre annonce')).toBeGreaterThanOrEqual(0);
        expect(order.indexOf('Titre annonce')).toBeLessThan(order.indexOf('Accès rapides'));
        assertNoRenderLoop();
    });

    it('places Accès rapides before an already-viewed, long (collapsed) announcement without an infinite render loop', () => {
        render(<DashboardIndex dashboard={baseDashboard({ has_been_viewed: true })} />);

        const order = screen.getByTestId('app-layout').textContent;
        expect(order.indexOf('Accès rapides')).toBeLessThan(order.indexOf('Titre annonce'));
        expect(screen.getByText('Afficher plus')).toBeInTheDocument();
        assertNoRenderLoop();
    });

    it('renders Accès rapides alone, with no reserved empty space, when there is no announcement', () => {
        render(<DashboardIndex dashboard={baseDashboard(null)} />);

        expect(screen.getByText('Accès rapides')).toBeInTheDocument();
        expect(screen.queryByText('Titre annonce')).not.toBeInTheDocument();
        assertNoRenderLoop();
    });

    it('moves Accès rapides above an already-viewed short announcement too (order follows has_been_viewed, not the visual collapse)', () => {
        // Choix assumé : l'ordre dérive de has_been_viewed (valeur stable),
        // pas de isClamped (qui nécessite une vraie mesure de mise en page
        // et avait causé la boucle de rendu). Une courte annonce déjà lue
        // reste donc affichée entièrement, mais Accès rapides passe quand
        // même au-dessus.
        render(<DashboardIndex dashboard={baseDashboard({ has_been_viewed: true, body_html: '<p>Courte annonce.</p>' })} />);

        const order = screen.getByTestId('app-layout').textContent;
        expect(order.indexOf('Accès rapides')).toBeLessThan(order.indexOf('Titre annonce'));
        expect(screen.queryByText('Afficher plus')).not.toBeInTheDocument();
        assertNoRenderLoop();
    });

    it('does not loop when a new unread announcement replaces a previously read one (rerender)', () => {
        const { rerender } = render(<DashboardIndex dashboard={baseDashboard({ id: 1, has_been_viewed: true })} />);
        rerender(<DashboardIndex dashboard={baseDashboard({ id: 2, has_been_viewed: false })} />);

        const order = screen.getByTestId('app-layout').textContent;
        expect(order.indexOf('Titre annonce')).toBeLessThan(order.indexOf('Accès rapides'));
        assertNoRenderLoop();
    });

    it('does not loop when toggling the announcement open/closed manually', () => {
        render(<DashboardIndex dashboard={baseDashboard({ has_been_viewed: true })} />);

        fireEvent.click(screen.getByText('Afficher plus'));
        fireEvent.click(screen.getByText('Réduire'));

        assertNoRenderLoop();
    });
});
