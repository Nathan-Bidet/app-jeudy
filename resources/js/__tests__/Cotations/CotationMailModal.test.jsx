import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

import CotationMailModal from '@/Components/Cotations/CotationMailModal';

const DRAFT = {
    subject: 'Cotation du 30/09/2026',
    body_html: '<p><span style="color: #b91c1c">Marché haussier</span></p><p><br></p><p><em>Lundi</em></p>',
    is_empty: false,
};

const json = (data, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });

function mount(props = {}) {
    const handlers = { onClose: vi.fn(), onSent: vi.fn(), onCancel: vi.fn() };
    render(<CotationMailModal show draftUrl="/draft" sendUrl="/send" {...handlers} {...props} />);

    return handlers;
}

const sendRequest = (fetchMock) => fetchMock.mock.calls.find(([url]) => url === '/send');

describe('CotationMailModal', () => {
    let fetchMock;
    let hrefSetter;

    beforeEach(() => {
        fetchMock = vi.fn((url) => (url === '/draft' ? json(DRAFT) : json({ ok: true, sent: 2 })));
        vi.stubGlobal('fetch', fetchMock);
        // Aucun mailto: ne doit jamais être déclenché.
        hrefSetter = vi.fn();
        vi.spyOn(window, 'open').mockImplementation(hrefSetter);
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('affiche l\'objet daté et le message avec sa mise en forme', async () => {
        mount();

        expect(await screen.findByDisplayValue('Cotation du 30/09/2026')).toBeInTheDocument();
        const colored = await screen.findByText('Marché haussier');
        expect(colored).toHaveStyle({ color: '#b91c1c' });
        expect(screen.getByText('Lundi').tagName).toBe('EM');
        expect(screen.getByLabelText('Destinataires')).toHaveValue('');
    });

    it('ferme sans envoyer avec Annuler et avec la croix', async () => {
        const { onClose, onCancel } = mount();
        await screen.findByText('Marché haussier');

        fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));
        fireEvent.click(screen.getByRole('button', { name: 'Fermer sans envoyer' }));

        expect(onClose).toHaveBeenCalledTimes(2);
        expect(onCancel).toHaveBeenCalledTimes(2);
        expect(sendRequest(fetchMock)).toBeUndefined();
    });

    it('exige au moins un destinataire avant d\'appeler le serveur', async () => {
        mount();
        await screen.findByText('Marché haussier');

        fireEvent.click(screen.getByRole('button', { name: /Envoyer le message/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Renseignez au moins un destinataire.');
        expect(sendRequest(fetchMock)).toBeUndefined();
    });

    it('envoie plusieurs destinataires séparés par virgule, point-virgule ou espace, puis ferme', async () => {
        const { onClose, onSent } = mount();
        await screen.findByText('Marché haussier');

        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr, b@ex.fr;c@ex.fr d@ex.fr' } });
        fireEvent.click(screen.getByRole('button', { name: /Envoyer le message/ }));

        await waitFor(() => expect(onSent).toHaveBeenCalledWith(2));
        expect(onClose).toHaveBeenCalled();
        const [, options] = sendRequest(fetchMock);
        expect(options.method).toBe('POST');
        expect(JSON.parse(options.body)).toEqual({
            to: ['a@ex.fr', 'b@ex.fr', 'c@ex.fr', 'd@ex.fr'],
            subject: 'Cotation du 30/09/2026',
        });
        expect(hrefSetter).not.toHaveBeenCalled();
    });

    it('empêche le double envoi pendant que la requête est en cours', async () => {
        let resolveSend;
        fetchMock.mockImplementation((url) => (url === '/draft'
            ? json(DRAFT)
            : new Promise((resolve) => { resolveSend = resolve; })));
        const handlers = mount();
        await screen.findByText('Marché haussier');
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

        const button = screen.getByRole('button', { name: /Envoyer le message/ });
        fireEvent.click(button);
        fireEvent.click(button);
        fireEvent.submit(button.closest('form'));

        await waitFor(() => expect(button).toBeDisabled());
        expect(fetchMock.mock.calls.filter(([url]) => url === '/send')).toHaveLength(1);

        resolveSend({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, sent: 1 }) });
        await waitFor(() => expect(handlers.onSent).toHaveBeenCalledWith(1));
    });

    it('reste ouverte avec les destinataires saisis quand l\'envoi échoue', async () => {
        fetchMock.mockImplementation((url) => (url === '/draft'
            ? json(DRAFT)
            : json({ message: "L'e-mail n'a pas pu être envoyé. Réessayez dans un instant." }, 502)));
        const { onClose, onSent } = mount();
        await screen.findByText('Marché haussier');
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

        fireEvent.click(screen.getByRole('button', { name: /Envoyer le message/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent("L'e-mail n'a pas pu être envoyé");
        expect(screen.getByLabelText('Destinataires')).toHaveValue('a@ex.fr');
        expect(onClose).not.toHaveBeenCalled();
        expect(onSent).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: /Envoyer le message/ })).not.toBeDisabled();
    });

    it('affiche l\'erreur de validation d\'une adresse invalide renvoyée par le serveur', async () => {
        fetchMock.mockImplementation((url) => (url === '/draft'
            ? json(DRAFT)
            : json({ message: 'x', errors: { 'to.0': ['Adresse e-mail invalide : nope.'] } }, 422)));
        mount();
        await screen.findByText('Marché haussier');
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'nope' } });

        fireEvent.click(screen.getByRole('button', { name: /Envoyer le message/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Adresse e-mail invalide : nope.');
    });

    it('désactive l\'envoi quand le message d\'information est vide', async () => {
        fetchMock.mockImplementation(() => json({ ...DRAFT, body_html: '', is_empty: true }));
        mount();

        expect(await screen.findByText(/message d'information est vide/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Envoyer le message/ })).toBeDisabled();
    });
});
