import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Détail d'une journée d'heures, ouvert par le lien d'une notification, et
 * carte de l'historique du salarié.
 *
 * Le salarié ne voit plus l'issue de la validation : une journée dont le
 * circuit est clos lui apparaît « Traitée », sans motif. Le serveur ne lui
 * transmet d'ailleurs ni l'issue ni le motif (HourSheetOwnerView) ; ces tests
 * vérifient que l'écran n'en invente pas non plus.
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
import { HourSheetStatusBadge } from '@/Components/Hours/HourSheetBadges';
import HoursIndex from '@/Pages/Hours/Index';

/** Journée close (validée OU refusée), telle que le serveur la sert au salarié. */
function processedSheet(overrides = {}) {
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
        status: 'processed',
        status_label: 'Traitée',
        ...overrides,
    };
}

describe('HourSheetStatusBadge', () => {
    afterEach(cleanup);

    it('affiche « Traitée » en jaune plein, texte noir', () => {
        render(<HourSheetStatusBadge sheet={processedSheet()} />);
        const badge = screen.getByText('Traitée');

        expect(badge).toHaveAttribute('data-status', 'processed');
        expect(badge.className).toContain('bg-[#fde047]');
        expect(badge.className).toContain('text-black');
        expect(badge.className).toContain('border-[#eab308]');
    });

    it('garde « En validation » distinct tant que le circuit est ouvert', () => {
        render(<HourSheetStatusBadge sheet={processedSheet({ status: 'pending', status_label: 'En attente de validation' })} />);
        const badge = screen.getByText('En validation');

        expect(badge).toHaveAttribute('data-status', 'pending');
        expect(badge.className).toContain('bg-white');
        expect(badge.className).toContain('text-[#a16207]');
        expect(badge.className).not.toContain('text-black');
    });

    it('conserve le libellé d\'une saisie antérieure à la validation', () => {
        render(<HourSheetStatusBadge sheet={processedSheet({ status: null, status_label: 'Saisie antérieure à la validation' })} />);

        expect(screen.getByText('Saisie antérieure à la validation')).toBeInTheDocument();
    });
});

describe('HourSheetDetailModal', () => {
    afterEach(cleanup);

    it('ne rend rien tant qu\'aucune journée n\'est demandée', () => {
        render(<HourSheetDetailModal sheet={null} />);

        expect(screen.queryByRole('button', { name: 'Fermer' })).not.toBeInTheDocument();
    });

    it('affiche le détail de la journée avec le statut « Traitée », sans issue ni motif', () => {
        render(<HourSheetDetailModal sheet={processedSheet()} />);

        expect(screen.getByText('Mercredi 2 septembre 2026')).toBeInTheDocument();
        expect(screen.getByText('08:00 - 12:00 / 14:00 - 19:00')).toBeInTheDocument();
        expect(screen.getByText('Entretien du matériel')).toBeInTheDocument();
        expect(screen.getByText('Déjeuner')).toBeInTheDocument();
        expect(screen.getByText('Traitée')).toBeInTheDocument();
        expect(document.body.textContent).not.toMatch(/Motif|Refus|Validée/);
    });

    it('n\'affiche jamais un motif, même s\'il en recevait un', () => {
        render(<HourSheetDetailModal sheet={processedSheet({ status: 'refused', refusal_reason: 'Motif confidentiel' })} />);

        expect(screen.queryByText('Motif confidentiel')).not.toBeInTheDocument();
        expect(screen.queryByText(/Motif du refus/)).not.toBeInTheDocument();
    });

    it('affiche le badge d\'heures supplémentaires, au même format qu\'ailleurs', () => {
        render(<HourSheetDetailModal sheet={processedSheet()} />);

        // Mercredi, 9 h travaillées pour 8 h de référence.
        expect(screen.getByTitle(/Heures supplémentaires/)).toHaveTextContent('+1h00');
    });

    it('rend une journée continue comme une plage unique', () => {
        render(<HourSheetDetailModal sheet={processedSheet({
            is_continuous_day: true,
            morning_end: null,
            afternoon_start: null,
            afternoon_end: '17:00',
            total_minutes: 540,
        })} />);

        expect(screen.getByText('08:00 - 17:00 (journée continue)')).toBeInTheDocument();
    });

    it('traite une journée non travaillée sans horaires ni cases', () => {
        render(<HourSheetDetailModal sheet={processedSheet({
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
        render(<HourSheetDetailModal sheet={processedSheet()} onClose={onClose} />);

        fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));

        expect(onClose).toHaveBeenCalled();
    });
});

// Jeudi 3 septembre 2026 : la journée traitée est la veille.
const TODAY = '2026-09-03';

function renderPage(props = {}) {
    return render(
        <HoursIndex
            hourSheets={[processedSheet()]}
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

describe('Hours/Index — vue du salarié', () => {
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

        expect(screen.getByRole('button', { name: 'Fermer' })).toBeInTheDocument();
        expect(screen.getAllByText('Traitée').length).toBeGreaterThan(0);
    });

    it('n\'ouvre rien sans lien', () => {
        renderPage();

        expect(screen.queryByRole('button', { name: 'Fermer' })).not.toBeInTheDocument();
    });

    it('n\'ouvre rien pour une journée qui n\'est pas celle du lecteur', () => {
        renderPage({ highlightId: 9999 });

        expect(screen.queryByRole('button', { name: 'Fermer' })).not.toBeInTheDocument();
    });

    it('range une journée traitée dans l\'historique, badge « Traitée », sans motif ni lien de refus', () => {
        renderPage();

        // Une journée close a quitté « Mes heures en validation » pour
        // l'historique, replié par défaut.
        fireEvent.click(screen.getByRole('button', { name: 'Afficher l\'historique' }));

        const badge = screen.getByText('Traitée');
        expect(badge).toHaveAttribute('data-status', 'processed');
        expect(screen.queryByText(/Motif du refus/)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Voir le détail' })).not.toBeInTheDocument();
        expect(screen.queryByText(/Refusée|Validée/)).not.toBeInTheDocument();
    });

    it('garde « En validation » pour une journée encore ouverte', () => {
        renderPage({ hourSheets: [processedSheet({ status: 'pending', status_label: 'En attente de validation' })] });

        const section = screen.getByText('Mes heures en validation').closest('section') ?? document.body;
        expect(within(section).getByText('En validation')).toHaveAttribute('data-status', 'pending');
    });
});
