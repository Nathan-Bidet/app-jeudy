import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';

import CerealHistoryBar from '@/Components/Cotations/CerealHistoryBar';
import { buildMonthGrid, formatFrDate, neighborDate } from '@/Support/cerealHistory';

afterEach(() => cleanup());

const DATES = ['2026-10-02', '2026-10-01', '2026-09-28']; // du plus récent au plus ancien

describe('utilitaires', () => {
    it('formate la date au format français et jamais « Présent »', () => {
        expect(formatFrDate('2026-10-01')).toBe('01/10/2026');
        expect(formatFrDate('')).toBe('');
    });

    it('trouve le voisin plus ancien / plus récent, null aux extrémités', () => {
        expect(neighborDate(DATES, '2026-10-01', 'older')).toBe('2026-09-28');
        expect(neighborDate(DATES, '2026-10-01', 'newer')).toBe('2026-10-02');
        expect(neighborDate(DATES, '2026-09-28', 'older')).toBeNull();
        expect(neighborDate(DATES, '2026-10-02', 'newer')).toBeNull();
    });

    it('construit un mois du lundi au dimanche', () => {
        const grid = buildMonthGrid(2026, 9); // octobre 2026 : le 1er est un jeudi
        expect(grid[0].slice(0, 4)).toEqual([null, null, null, '2026-10-01']);
        expect(grid.every((week) => week.length === 7)).toBe(true);
        expect(grid.flat().filter(Boolean)).toHaveLength(31);
    });
});

describe('CerealHistoryBar', () => {
    const setup = (props = {}) => {
        const handlers = { onSelectDate: vi.fn(), onClose: vi.fn() };
        render(<CerealHistoryBar dates={DATES} activeDate="2026-10-01" {...handlers} {...props} />);

        return handlers;
    };

    it('affiche la date consultée au centre, jamais « Présent »', () => {
        setup();

        expect(screen.getByRole('button', { name: /01\/10\/2026/ })).toBeInTheDocument();
        expect(screen.queryByText('Présent')).toBeNull();
        expect(screen.getByRole('button', { name: "Fermer l'historique" })).toBeInTheDocument();
    });

    it('navigue entre les journées réellement disponibles', () => {
        const { onSelectDate } = setup();

        fireEvent.click(screen.getByRole('button', { name: /Plus ancienne/ }));
        expect(onSelectDate).toHaveBeenLastCalledWith('2026-09-28');
        fireEvent.click(screen.getByRole('button', { name: /Plus récente/ }));
        expect(onSelectDate).toHaveBeenLastCalledWith('2026-10-02');
    });

    it('désactive « Plus ancienne » sur la première date et « Plus récente » sur la dernière', () => {
        cleanup();
        setup({ activeDate: '2026-09-28' });
        expect(screen.getByRole('button', { name: /Plus ancienne/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Plus récente/ })).toBeEnabled();

        cleanup();
        setup({ activeDate: '2026-10-02' });
        expect(screen.getByRole('button', { name: /Plus récente/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Plus ancienne/ })).toBeEnabled();
    });

    it('ouvre un calendrier où seules les dates avec historique sont sélectionnables', () => {
        const { onSelectDate } = setup();
        fireEvent.click(screen.getByRole('button', { name: /01\/10\/2026/ }));

        const dialog = screen.getByRole('dialog', { name: /Choisir une date/ });
        expect(within(dialog).getByText('Octobre 2026')).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: '01/10/2026, version disponible' })).toBeEnabled();
        expect(within(dialog).getByRole('button', { name: '02/10/2026, version disponible' })).toBeEnabled();
        expect(within(dialog).getByRole('button', { name: '03/10/2026, aucun historique' })).toBeDisabled();
        expect(within(dialog).getByRole('button', { name: '01/10/2026, version disponible' })).toHaveAttribute('aria-current', 'date');

        // Une date sans historique ne déclenche rien.
        fireEvent.click(within(dialog).getByRole('button', { name: '03/10/2026, aucun historique' }));
        expect(onSelectDate).not.toHaveBeenCalled();

        // Une date disponible est sélectionnée et ferme le calendrier.
        fireEvent.click(within(dialog).getByRole('button', { name: '02/10/2026, version disponible' }));
        expect(onSelectDate).toHaveBeenCalledWith('2026-10-02');
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('permet de changer de mois pour atteindre les archives plus anciennes, dans les bornes', () => {
        const { onSelectDate } = setup();
        fireEvent.click(screen.getByRole('button', { name: /01\/10\/2026/ }));
        const dialog = screen.getByRole('dialog');

        expect(within(dialog).getByRole('button', { name: 'Mois suivant' })).toBeDisabled();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Mois précédent' }));
        expect(within(dialog).getByText('Septembre 2026')).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Mois précédent' })).toBeDisabled();

        fireEvent.click(within(dialog).getByRole('button', { name: '28/09/2026, version disponible' }));
        expect(onSelectDate).toHaveBeenCalledWith('2026-09-28');
    });

    it('est utilisable au clavier : focus initial sur la date, flèches, PageUp, Échap', () => {
        setup();
        const trigger = screen.getByRole('button', { name: /01\/10\/2026/ });
        fireEvent.click(trigger);
        const dialog = screen.getByRole('dialog');

        const selected = within(dialog).getByRole('button', { name: '01/10/2026, version disponible' });
        expect(selected).toHaveFocus();

        fireEvent.keyDown(selected, { key: 'ArrowRight' });
        expect(within(dialog).getByRole('button', { name: '02/10/2026, version disponible' })).toHaveFocus();
        fireEvent.keyDown(document.activeElement, { key: 'ArrowRight' });
        expect(within(dialog).getByRole('button', { name: '02/10/2026, version disponible' })).toHaveFocus();

        fireEvent.keyDown(document.activeElement, { key: 'PageUp' });
        expect(within(dialog).getByText('Septembre 2026')).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(trigger).toHaveFocus();
    });

    it('rend le calendrier dans <body>, en position fixe (reste visible à l’écran)', () => {
        const { container } = render(<div style={{ overflow: 'hidden' }}><CerealHistoryBar dates={DATES} activeDate="2026-10-01" onSelectDate={() => {}} onClose={() => {}} /></div>);
        fireEvent.click(container.querySelector('button[aria-haspopup="dialog"]'));

        const dialog = screen.getByRole('dialog');
        expect(container.contains(dialog)).toBe(false);
        expect(dialog.style.position).toBe('fixed');
    });

    it('« Fermer l’historique » ferme le calendrier ouvert puis quitte le mode', () => {
        const { onClose } = setup();
        fireEvent.click(screen.getByRole('button', { name: /01\/10\/2026/ }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: "Fermer l'historique" }));
        expect(onClose).toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
