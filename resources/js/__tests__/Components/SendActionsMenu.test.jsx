import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

import SendActionsMenu from '@/Components/SendActionsMenu';

afterEach(() => {
    cleanup();
});

describe('SendActionsMenu', () => {
    it('is closed by default and opens the SMS/e-mail choice on click', () => {
        render(<SendActionsMenu smsHref="sms:0612345678?body=x" mailHref="mailto:a@b.com?subject=x&body=y" />);

        expect(screen.queryByRole('menu')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByRole('menu')).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: /Envoyer par SMS/ })).toHaveAttribute('href', 'sms:0612345678?body=x');
        expect(screen.getByRole('menuitem', { name: /Envoyer par e-mail/ })).toHaveAttribute('href', 'mailto:a@b.com?subject=x&body=y');
    });

    it('disables only the SMS action and shows the reason when no mobile number is available', () => {
        render(<SendActionsMenu smsHref={null} mailHref="mailto:a@b.com" />);
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun numéro de portable renseigné')).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: /Envoyer par e-mail/ })).toBeInTheDocument();
    });

    it('disables only the e-mail action and shows the reason when no address is available', () => {
        render(<SendActionsMenu smsHref="sms:0612345678" mailHref={null} />);
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucune adresse e-mail renseignée')).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: /Envoyer par SMS/ })).toBeInTheDocument();
    });

    it('shows a single "no recipient" message and no external link when both are unavailable', () => {
        render(<SendActionsMenu smsHref={null} mailHref={null} />);
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun destinataire disponible')).toBeInTheDocument();
        expect(screen.queryByRole('menuitem')).not.toBeInTheDocument();
    });

    it('closes on outside click', () => {
        render(
            <div>
                <SendActionsMenu smsHref="sms:0612345678" mailHref="mailto:a@b.com" />
                <button type="button">Ailleurs</button>
            </div>,
        );
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));
        expect(screen.getByRole('menu')).toBeInTheDocument();

        fireEvent.mouseDown(screen.getByRole('button', { name: 'Ailleurs' }));

        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('Escape closes the menu and returns focus to the trigger button', () => {
        render(<SendActionsMenu smsHref="sms:0612345678" mailHref="mailto:a@b.com" />);
        const trigger = screen.getByRole('button', { name: /Envoyer/ });
        fireEvent.click(trigger);
        expect(screen.getByRole('menu')).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape', code: 'Escape' });

        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('exposes aria-haspopup/aria-expanded on the trigger button', () => {
        render(<SendActionsMenu smsHref="sms:0612345678" mailHref="mailto:a@b.com" />);
        const trigger = screen.getByRole('button', { name: /Envoyer/ });

        expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
    });

    it('clicking an available action closes the menu (native navigation, no auto-send)', () => {
        render(<SendActionsMenu smsHref="sms:0612345678?body=x" mailHref="mailto:a@b.com" />);
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        fireEvent.click(screen.getByRole('menuitem', { name: /Envoyer par SMS/ }));

        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });
});
