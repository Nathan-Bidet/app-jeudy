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

    it('keeps the e-mail action clickable (empty-recipient mailto:) with a discreet note when mailHasRecipient is false', () => {
        render(<SendActionsMenu smsHref={null} mailHref="mailto:?subject=x&body=y" mailHasRecipient={false} />);
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        const mailLink = screen.getByRole('menuitem', { name: /Envoyer par e-mail/ });
        expect(mailLink).toHaveAttribute('href', 'mailto:?subject=x&body=y');
        expect(screen.getByText('Adresse à renseigner dans votre messagerie')).toBeInTheDocument();
    });

    it('does not show the discreet note when mailHasRecipient is true', () => {
        render(<SendActionsMenu smsHref={null} mailHref="mailto:a@b.com?subject=x&body=y" mailHasRecipient />);
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.queryByText('Adresse à renseigner dans votre messagerie')).not.toBeInTheDocument();
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

describe('SendActionsMenu — lisibilité dans une ligne colorée', () => {
    // Régression : dans une ligne dont la règle impose un texte blanc (fond vert),
    // le menu (fond clair) héritait de cette couleur et devenait illisible.
    const renderInColoredRow = (props) =>
        render(
            <div style={{ color: '#ffffff', backgroundColor: '#166534' }}>
                <SendActionsMenu {...props} />
            </div>,
        );

    it('fixe sa propre couleur de texte (thème) au lieu d’hériter de la ligne', () => {
        renderInColoredRow({ smsHref: 'sms:0612345678', mailHref: 'mailto:a@b.com' });
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByRole('menu').className).toContain('text-[var(--app-text)]');
        expect(screen.getByRole('menu').className).toContain('bg-[var(--app-surface)]');
    });

    it('garde une couleur explicite pour les options désactivées et les notes', () => {
        renderInColoredRow({ smsHref: null, mailHref: 'mailto:a@b.com', mailHasRecipient: false });
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));

        expect(screen.getByText('Aucun numéro de portable renseigné').className).toContain('text-[var(--app-muted)]');
        expect(screen.getByText('Adresse à renseigner dans votre messagerie').className).toContain('text-[var(--app-muted)]');
    });

    it('rend focus et survol visibles sur les options, et se ferme à Échap en rendant le focus', () => {
        renderInColoredRow({ smsHref: 'sms:0612345678', mailHref: 'mailto:a@b.com' });
        const trigger = screen.getByRole('button', { name: /Envoyer/ });
        fireEvent.click(trigger);

        screen.getAllByRole('menuitem').forEach((item) => {
            expect(item.className).toContain('hover:bg-[var(--app-surface-soft)]');
            expect(item.className).toContain('focus-visible:bg-[var(--app-surface-soft)]');
        });

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('ferme le menu après sélection d’une option', () => {
        renderInColoredRow({ smsHref: 'sms:0612345678', mailHref: 'mailto:a@b.com' });
        fireEvent.click(screen.getByRole('button', { name: /Envoyer/ }));
        fireEvent.click(screen.getByRole('menuitem', { name: /Envoyer par SMS/ }));

        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });
});
