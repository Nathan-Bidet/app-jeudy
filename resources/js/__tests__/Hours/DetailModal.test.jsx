import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Détail d'une journée d'heures, ouvert par le lien de la notification de refus.
 *
 * Deux niveaux sont éprouvés : la modale elle-même, et son ouverture depuis la
 * page Heures — c'est cette dernière que la notification déclenche, via le
 * paramètre `highlight` de l'URL.
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

import HourSheetDetailModal from '@/Components/Hours/HourSheetDetailModal';
import HoursIndex from '@/Pages/Hours/Index';

/** Journée refusée, telle que le serveur la sert au salarié. */
function refusedSheet(overrides = {}) {
    return {
        id: 42,
        work_date: '2026-09-02', // un mercredi, référence 8 h
        morning_start: '08:00',
        morning_end: '12:00',
        afternoon_start: '14:00',
        afternoon_end: '19:00',
        is_continuous_day: false,
        total_minutes: 540,
        description: 'Entretien du matériel',
        is_not_worked: false,
        has_breakfast_before_5: false,
        has_lunch: true,
        has_dinner_after_21: false,
        has_long_night: false,
        status: 'refused',
        status_label: 'Refusé',
        refusal_reason: 'Horaires du soir incohérents',
        ...overrides,
    };
}

describe('HourSheetDetailModal', () => {
    afterEach(cleanup);

    it('ne rend rien tant qu\'aucune journée n\'est demandée', () => {
        render(<HourSheetDetailModal sheet={null} />);

        expect(screen.queryByText('Motif du refus')).not.toBeInTheDocument();
    });

    it('met le motif du refus en évidence', () => {
        render(<HourSheetDetailModal sheet={refusedSheet()} />);

        expect(screen.getByText('Motif du refus')).toBeInTheDocument();
        expect(screen.getByText('Horaires du soir incohérents')).toBeInTheDocument();
    });

    it('le dit clairement quand le refus n\'a pas de motif', () => {
        render(<HourSheetDetailModal sheet={refusedSheet({ refusal_reason: null })} />);

        expect(screen.getByText('Aucun motif n’a été indiqué.')).toBeInTheDocument();
    });

    it('affiche le détail de la journée concernée', () => {
        render(<HourSheetDetailModal sheet={refusedSheet()} />);

        expect(screen.getByText('Mercredi 2 septembre 2026')).toBeInTheDocument();
        expect(screen.getByText('08:00 - 12:00 / 14:00 - 19:00')).toBeInTheDocument();
        expect(screen.getByText('Entretien du matériel')).toBeInTheDocument();
        expect(screen.getByText('Déjeuner')).toBeInTheDocument();
        expect(screen.getByText('Refusée')).toBeInTheDocument();
    });

    it('affiche le badge d\'heures supplémentaires, au même format qu\'ailleurs', () => {
        render(<HourSheetDetailModal sheet={refusedSheet()} />);

        // Mercredi, 9 h travaillées pour 8 h de référence.
        expect(screen.getByTitle(/Heures supplémentaires/)).toHaveTextContent('+1h00');
    });

    it('rend une journée continue comme une plage unique', () => {
        render(<HourSheetDetailModal sheet={refusedSheet({
            is_continuous_day: true,
            morning_end: null,
            afternoon_start: null,
            afternoon_end: '17:00',
            total_minutes: 540,
        })} />);

        expect(screen.getByText('08:00 - 17:00 (journée continue)')).toBeInTheDocument();
    });

    it('traite une journée non travaillée sans horaires ni cases', () => {
        render(<HourSheetDetailModal sheet={refusedSheet({
            is_not_worked: true,
            morning_start: null,
            morning_end: null,
            afternoon_start: null,
            afternoon_end: null,
            total_minutes: 0,
        })} />);

        expect(screen.getByText('Journée non travaillée')).toBeInTheDocument();
        expect(screen.queryByText('Horaires')).not.toBeInTheDocument();
        expect(screen.queryByText('Cases cochées')).not.toBeInTheDocument();
    });

    it('se ferme à la demande', () => {
        const onClose = vi.fn();
        render(<HourSheetDetailModal sheet={refusedSheet()} onClose={onClose} />);

        fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));

        expect(onClose).toHaveBeenCalled();
    });
});

// Jeudi 3 septembre 2026 : la journée refusée est la veille.
const TODAY = '2026-09-03';

function renderPage(props = {}) {
    return render(
        <HoursIndex
            hourSheets={[refusedSheet()]}
            approvedLeaveDays={{}}
            // Borne à aujourd'hui : une seule carte de saisie est rendue, au
            // lieu des sept de la semaine, chacune portant quatre listes de 96
            // créneaux.
            minVisibleDate={TODAY}
            canCreate
            canExport={false}
            hourSheetsToValidate={[]}
            pendingValidationCount={0}
            {...props}
        />,
    );
}

describe('Hours/Index — ouverture du détail', () => {
    beforeEach(() => {
        global.route = vi.fn((name, params) => `/${name}/${params ?? ''}`);
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(`${TODAY}T10:00:00`));
    });

    afterEach(() => {
        vi.useRealTimers();
        cleanup();
    });

    it('ouvre le détail de la journée désignée par le lien de la notification', () => {
        renderPage({ highlightId: 42 });

        expect(screen.getByText('Motif du refus')).toBeInTheDocument();
        expect(screen.getByText('Horaires du soir incohérents')).toBeInTheDocument();
    });

    it('n\'ouvre rien sans lien', () => {
        renderPage();

        expect(screen.queryByText('Motif du refus')).not.toBeInTheDocument();
    });

    it('n\'ouvre rien pour une journée qui n\'est pas celle du lecteur', () => {
        // Un identifiant absent de ses journées ne correspond à rien : la page
        // s'affiche normalement, sans modale.
        renderPage({ highlightId: 9999 });

        expect(screen.queryByText('Motif du refus')).not.toBeInTheDocument();
    });

    it('ouvre le détail depuis la carte d\'une journée refusée', () => {
        renderPage();

        // Une journée refusée a quitté « Mes heures en validation » pour
        // l'historique, replié par défaut.
        fireEvent.click(screen.getByRole('button', { name: 'Afficher l\'historique' }));
        fireEvent.click(screen.getByRole('button', { name: 'Voir le détail' }));

        expect(screen.getByText('Motif du refus')).toBeInTheDocument();
    });
});
