import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

import DesktopTable from '@/Components/Aprevoir/DesktopTable';
import TaskRow from '@/Components/Aprevoir/TaskRow';

/**
 * Bouton "Envoyer" (SMS/e-mail) sous la date de chaque tâche Tâches/Engrais,
 * réutilisant le même format de message que le Livre du travail. Vérifie la
 * position sous la date, la lecture des coordonnées depuis group.assignee
 * (pas une copie sur la tâche), et les cas de coordonnées absentes — sur la
 * table (ordinateur) et la carte (mobile).
 */

function baseGroup(assigneeOverrides = {}) {
    return {
        key: 'g1',
        date: '2026-08-20',
        date_label: '20-08-2026',
        assignee: assigneeOverrides === null ? { type: 'none' } : {
            type: 'user',
            id: 5,
            name: 'Jean Dupont',
            phone: '0612345678',
            mobile_phone: '0612345678',
            email: 'jean.dupont@example.com',
            ...assigneeOverrides,
        },
    };
}

function baseTask(overrides = {}) {
    return {
        id: 1,
        task: 'Livraison engrais',
        fin_label: null,
        loading_place: null,
        delivery_place: null,
        comment: null,
        vehicle: null,
        remorque: null,
        book: null,
        style: {},
        ...overrides,
    };
}

beforeEach(() => {
    global.route = vi.fn((name, params) => `/${name}/${params ?? ''}`);
});

afterEach(() => {
    cleanup();
});

describe('DesktopTable — bouton Envoyer sous la date', () => {
    it('renders the "Envoyer" button directly under the date cell', () => {
        const group = baseGroup();
        render(<DesktopTable groups={[{ ...group, tasks: [baseTask()] }]} moduleTitle="Engrais / Tourteaux" />);

        const dateCell = screen.getByText('20-08-2026').closest('td');
        expect(dateCell).not.toBeNull();
        expect(dateCell.querySelector('button')).toHaveTextContent('Envoyer');
    });

    it('builds the SMS/e-mail links from group.assignee (not a copy on the task)', () => {
        const group = baseGroup();
        render(<DesktopTable groups={[{ ...group, tasks: [baseTask({ task: 'Livraison engrais Nord' })] }]} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        const smsLink = screen.getByRole('menuitem', { name: /Envoyer par SMS/ });
        expect(smsLink.getAttribute('href')).toContain('sms:0612345678?body=');
        expect(decodeURIComponent(smsLink.getAttribute('href'))).toContain('Livraison engrais Nord');

        const mailLink = screen.getByRole('menuitem', { name: /Envoyer par e-mail/ });
        expect(mailLink.getAttribute('href')).toContain(encodeURIComponent('jean.dupont@example.com'));
        expect(decodeURIComponent(mailLink.getAttribute('href'))).toContain('Engrais / Tourteaux - 20-08-2026 - Livraison engrais Nord');
    });

    it('still offers e-mail (recipient empty, subject/body prefilled) when no driver is attached — SMS stays unavailable', () => {
        const group = baseGroup(null);
        render(<DesktopTable groups={[{ ...group, tasks: [baseTask({ task: 'Livraison engrais Nord' })] }]} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun numéro de portable renseigné')).toBeInTheDocument();
        const mailLink = screen.getByRole('menuitem', { name: /Envoyer par e-mail/ });
        expect(mailLink.getAttribute('href')).toMatch(/^mailto:\?subject=/);
        expect(decodeURIComponent(mailLink.getAttribute('href'))).toContain('Livraison engrais Nord');
        expect(screen.getByText('Adresse à renseigner dans votre messagerie')).toBeInTheDocument();
    });

    it('disables SMS only when the driver has no mobile number — e-mail keeps the recipient prefilled', () => {
        const group = baseGroup({ mobile_phone: null, phone: '0102030405' });
        render(<DesktopTable groups={[{ ...group, tasks: [baseTask()] }]} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun numéro de portable renseigné')).toBeInTheDocument();
        const mailLink = screen.getByRole('menuitem', { name: /Envoyer par e-mail/ });
        expect(mailLink.getAttribute('href')).toContain(encodeURIComponent('jean.dupont@example.com'));
        expect(screen.queryByText('Adresse à renseigner dans votre messagerie')).not.toBeInTheDocument();
    });

    it('e-mail only (no mobile number): SMS unavailable, e-mail available with recipient prefilled', () => {
        const group = baseGroup({ mobile_phone: null, phone: null });
        render(<DesktopTable groups={[{ ...group, tasks: [baseTask()] }]} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun numéro de portable renseigné')).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: /Envoyer par e-mail/ }).getAttribute('href'))
            .toContain(encodeURIComponent('jean.dupont@example.com'));
    });

    it('keeps e-mail available with an empty recipient (subject/body still prefilled) when the driver has no e-mail — SMS unaffected', () => {
        const group = baseGroup({ email: null });
        render(<DesktopTable groups={[{ ...group, tasks: [baseTask()] }]} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Adresse à renseigner dans votre messagerie')).toBeInTheDocument();
        const mailLink = screen.getByRole('menuitem', { name: /Envoyer par e-mail/ });
        expect(mailLink.getAttribute('href')).toMatch(/^mailto:\?subject=/);
        expect(screen.getByRole('menuitem', { name: /Envoyer par SMS/ })).toBeInTheDocument();
    });

    it('no coordinates at all: SMS unavailable, e-mail still available without a recipient', () => {
        const group = baseGroup({ mobile_phone: null, phone: null, email: null });
        render(<DesktopTable groups={[{ ...group, tasks: [baseTask()] }]} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun numéro de portable renseigné')).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: /Envoyer par e-mail/ }).getAttribute('href')).toMatch(/^mailto:\?subject=/);
        expect(screen.queryByText('Aucun destinataire disponible')).not.toBeInTheDocument();
    });
});

describe('TaskRow (mobile) — bouton Envoyer', () => {
    it('renders the Envoyer button and builds links from the enclosing group', () => {
        const group = baseGroup();
        render(<TaskRow task={baseTask()} group={group} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByRole('menuitem', { name: /Envoyer par SMS/ })).toHaveAttribute(
            'href',
            expect.stringContaining('sms:0612345678?body='),
        );
    });

    it('still offers e-mail without a recipient when there is no group/assignee at all', () => {
        render(<TaskRow task={baseTask({ task: 'Livraison engrais Nord' })} group={null} moduleTitle="Engrais / Tourteaux" />);

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun numéro de portable renseigné')).toBeInTheDocument();
        const mailLink = screen.getByRole('menuitem', { name: /Envoyer par e-mail/ });
        expect(mailLink.getAttribute('href')).toMatch(/^mailto:\?subject=/);
        expect(decodeURIComponent(mailLink.getAttribute('href'))).toContain('Livraison engrais Nord');
        expect(screen.getByText('Adresse à renseigner dans votre messagerie')).toBeInTheDocument();
        expect(screen.queryByText('Aucun destinataire disponible')).not.toBeInTheDocument();
    });
});
