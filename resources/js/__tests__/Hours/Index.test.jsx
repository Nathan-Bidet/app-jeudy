import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Régression « écran blanc » : ReferenceError: sheet is not defined.
 *
 * Le rendu d'une journée avait été extrait dans `renderDayEntry()`, ce qui a
 * dédenté son corps. Les deux blocs « Total heures travaillées » — celui de la
 * saisie de la semaine et celui de l'édition en ligne — se sont retrouvés avec
 * des indentations inversées par rapport à leur imbrication réelle. Le badge
 * d'heures supplémentaires a donc été branché sur la mauvaise variable de
 * chaque côté : `sheet` dans la carte de saisie (qui ne connaît que `day`), et
 * `day` dans le formulaire d'édition (qui ne connaît que `sheet`).
 *
 * Ces tests rendent réellement la page : ils échouent si une variable
 * référencée n'existe pas dans sa portée, ce qu'aucun build ne détecte.
 */

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ children, header }) => (
        <div data-testid="app-layout">
            {header}
            {children}
        </div>
    ),
}));

vi.mock('@/Layouts/AppShell/TitleCaps', () => ({
    default: ({ text }) => <span>{text}</span>,
}));

vi.mock('@/Components/Hours/HoursValidationQueue', () => ({
    default: () => null,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn(), get: vi.fn() },
    usePage: () => ({ props: { flash: {}, errors: {} } }),
}));

import HoursIndex from '@/Pages/Hours/Index';

/** Journée déjà enregistrée, telle que le serveur la renvoie. */
function hourSheet(overrides = {}) {
    return {
        id: 1,
        work_date: '2026-09-02', // un mercredi
        morning_start: '08:00',
        morning_end: '12:00',
        afternoon_start: '14:00',
        afternoon_end: '19:00',
        total_minutes: 540,
        description: 'Entretien du matériel',
        is_not_worked: false,
        is_continuous_day: false,
        has_breakfast_before_5: false,
        has_lunch: false,
        has_dinner_after_21: false,
        has_long_night: false,
        status: 'pending',
        status_label: 'En attente de validation',
        refusal_reason: null,
        ...overrides,
    };
}

// Jeudi 3 septembre 2026. La journée déjà saisie est la veille, un mercredi :
// elle alimente la section « en validation » sans disparaître de la grille.
const TODAY = '2026-09-03';

function renderPage(props = {}) {
    return render(
        <HoursIndex
            hourSheets={[hourSheet()]}
            approvedLeaveDays={{}}
            // Borne à aujourd'hui : la grille de saisie ne rend qu'une seule
            // carte. Chaque carte contient quatre listes déroulantes de 96
            // créneaux ; en rendre sept coûterait une demi-minute par test pour
            // une couverture identique.
            minVisibleDate={TODAY}
            canCreate
            canExport={false}
            hourSheetsToValidate={[]}
            pendingValidationCount={0}
            {...props}
        />,
    );
}

describe('Hours/Index — rendu de la page', () => {
    let errorSpy;

    beforeEach(() => {
        global.route = vi.fn((name, params) => `/${name}/${params ?? ''}`);
        // On ne fige QUE `Date` : figer aussi les timers bloquerait
        // l'ordonnanceur de React et Testing Library expirerait.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(`${TODAY}T10:00:00`));
        errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.useRealTimers();
        errorSpy.mockRestore();
        cleanup();
    });

    /**
     * Une ReferenceError pendant le rendu fait échouer `render()` lui-même :
     * c'est la détection qui compte. On vérifie en plus qu'aucune erreur React
     * n'a été journalisée, en ignorant les avertissements sans rapport.
     */
    function expectNoRenderError() {
        const realErrors = errorSpy.mock.calls
            .map((call) => String(call[0] ?? ''))
            .filter((message) => /is not defined|Can't find variable|Cannot read/.test(message));

        expect(realErrors).toEqual([]);
    }

    it('se rend sans erreur : la grille de saisie de la semaine s\'affiche', () => {
        renderPage();

        // Si une variable manquait dans la portée d'une carte, le rendu
        // lèverait avant d'arriver ici — c'est le cas qui produisait l'écran
        // blanc.
        expect(screen.getAllByText('Total heures travaillées').length).toBeGreaterThan(0);
        expectNoRenderError();
    });

    it('affiche la section des journées en validation, au-dessus de l\'historique', () => {
        renderPage();

        expect(screen.getByText('Mes heures en validation')).toBeInTheDocument();
    });

    it('rend le formulaire d\'édition en ligne sans erreur', () => {
        renderPage();

        // « Modifier » ouvre le formulaire d'édition : c'est l'autre bloc où le
        // badge était branché sur la mauvaise variable.
        const editButtons = screen.getAllByRole('button', { name: 'Modifier' });
        expect(editButtons.length).toBeGreaterThan(0);

        fireEvent.click(editButtons[0]);

        expect(screen.getAllByText('Total heures travaillées').length).toBeGreaterThan(0);
        expectNoRenderError();
    });

    it('affiche le badge d\'heures supplémentaires sur une journée dépassant la normale', () => {
        renderPage();

        // Mercredi 08:00-12:00 / 14:00-19:00 = 9 h, soit 1 h de plus que les 8 h
        // de référence.
        expect(screen.getAllByText('+1h00').length).toBeGreaterThan(0);
    });

    it('n\'affiche aucun badge sur une journée conforme à la durée normale', () => {
        renderPage({
            hourSheets: [hourSheet({ afternoon_end: '18:00', total_minutes: 480 })],
        });

        expect(screen.queryByTitle(/Heures supplémentaires/)).not.toBeInTheDocument();
    });

    it('se rend sans erreur lorsqu\'aucune journée n\'est enregistrée', () => {
        renderPage({ hourSheets: [] });

        expect(screen.queryByText('Mes heures en validation')).not.toBeInTheDocument();
        expectNoRenderError();
    });
});
