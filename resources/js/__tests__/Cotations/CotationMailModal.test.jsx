import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

import CotationMailModal from '@/Components/Cotations/CotationMailModal';

const DRAFT = {
    subject: 'Cotation du 30/09/2026',
    body_html: '<p><span style="color: #b91c1c">Marché haussier</span></p><p><br></p><p><em>Lundi</em></p>',
    is_empty: false,
    draft_id: 'draft-1',
    default_recipient: 'cotation@jeudy-sa.fr',
    limits: { max_files: 5, max_file_bytes: 5 * 1024 * 1024, max_total_bytes: 15 * 1024 * 1024, extensions: ['pdf', 'png', 'txt'] },
};

const PDF = { id: 1, kind: 'pdf', name: 'COTATIONS 30.09.2026.pdf', type: 'application/pdf', size: 20480 };

// Routeur de fetch simulé ; `overrides` remplace la réponse d'une route (clé : suffixe d'URL).
const route = (overrides = {}) => (url, options = {}) => {
    const key = Object.keys(overrides).find((suffix) => url.endsWith(suffix));
    if (key) return overrides[key](url, options);
    if (url === '/draft') return json(DRAFT);
    if (url.endsWith('/pdf')) return json({ attachment: PDF });
    if (url === '/send') return json({ ok: true, sent: 2 });

    return json({ ok: true });
};

const json = (data, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });

function mount(props = {}) {
    const handlers = { onClose: vi.fn(), onSent: vi.fn(), onCancel: vi.fn() };
    render(<CotationMailModal show draftUrl="/draft" sendUrl="/send" filesBaseUrl="/mail" {...handlers} {...props} />);

    return handlers;
}

// Clic « pointeur » (souris/tactile : detail >= 1), par opposition à l'activation clavier (detail 0).
const pointerClick = (element) => fireEvent.click(element, { detail: 1 });
const sendButton = () => screen.getByRole('button', { name: /Envoyer le message/ });

const sendRequest = (fetchMock) => fetchMock.mock.calls.find(([url]) => url === '/send');

describe('CotationMailModal', () => {
    let fetchMock;
    let hrefSetter;

    beforeEach(() => {
        fetchMock = vi.fn(route());
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
        expect(screen.getByLabelText('Destinataires')).toHaveValue('cotation@jeudy-sa.fr');
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
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: '' } });

        pointerClick(sendButton());

        expect(await screen.findByRole('alert')).toHaveTextContent('Renseignez au moins un destinataire.');
        expect(sendRequest(fetchMock)).toBeUndefined();
    });

    it('envoie plusieurs destinataires séparés par virgule, point-virgule ou espace, puis ferme', async () => {
        const { onClose, onSent } = mount();
        await screen.findByText('Marché haussier');

        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr, b@ex.fr;c@ex.fr d@ex.fr' } });
        pointerClick(sendButton());

        await waitFor(() => expect(onSent).toHaveBeenCalledWith(2));
        expect(onClose).toHaveBeenCalled();
        const [, options] = sendRequest(fetchMock);
        expect(options.method).toBe('POST');
        expect(JSON.parse(options.body)).toEqual({
            to: ['a@ex.fr', 'b@ex.fr', 'c@ex.fr', 'd@ex.fr'],
            subject: 'Cotation du 30/09/2026',
            body_html: DRAFT.body_html,
            draft_id: 'draft-1',
            attachment_ids: [1],
        });
        expect(hrefSetter).not.toHaveBeenCalled();
    });

    it('empêche le double envoi pendant que la requête est en cours', async () => {
        let resolveSend;
        fetchMock.mockImplementation(route({ '/send': () => new Promise((resolve) => { resolveSend = resolve; }) }));
        const handlers = mount();
        await screen.findByText('Marché haussier');
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

        const button = screen.getByRole('button', { name: /Envoyer le message/ });
        pointerClick(button);
        pointerClick(button);
        fireEvent.submit(button.closest('form'));

        await waitFor(() => expect(button).toBeDisabled());
        expect(fetchMock.mock.calls.filter(([url]) => url === '/send')).toHaveLength(1);

        resolveSend({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, sent: 1 }) });
        await waitFor(() => expect(handlers.onSent).toHaveBeenCalledWith(1));
    });

    it('reste ouverte avec les destinataires saisis quand l\'envoi échoue', async () => {
        fetchMock.mockImplementation(route({ '/send': () => json({ message: "L'e-mail n'a pas pu être envoyé. Réessayez dans un instant." }, 502) }));
        const { onClose, onSent } = mount();
        await screen.findByText('Marché haussier');
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

        pointerClick(sendButton());

        expect(await screen.findByRole('alert')).toHaveTextContent("L'e-mail n'a pas pu être envoyé");
        expect(screen.getByLabelText('Destinataires')).toHaveValue('a@ex.fr');
        expect(onClose).not.toHaveBeenCalled();
        expect(onSent).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: /Envoyer le message/ })).not.toBeDisabled();
    });

    it('affiche l\'erreur de validation d\'une adresse invalide renvoyée par le serveur', async () => {
        fetchMock.mockImplementation(route({ '/send': () => json({ message: 'x', errors: { 'to.0': ['Adresse e-mail invalide : nope.'] } }, 422) }));
        mount();
        await screen.findByText('Marché haussier');
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'nope' } });

        pointerClick(sendButton());

        expect(await screen.findByRole('alert')).toHaveTextContent('Adresse e-mail invalide : nope.');
    });

    it('reprend le message d\'information avec sa mise en forme dans un éditeur modifiable', async () => {
        mount();
        const colored = await screen.findByText('Marché haussier');

        expect(colored).toHaveStyle({ color: '#b91c1c' });
        expect(colored.closest('[contenteditable="true"]')).not.toBeNull();
        expect(screen.getByLabelText('Gras')).toBeInTheDocument();
    });

    it('envoie le texte modifié dans l\'éditeur, sans toucher au brouillon d\'origine', async () => {
        mount();
        const colored = await screen.findByText('Marché haussier');
        const editor = colored.closest('[contenteditable="true"]');

        editor.innerHTML = '<p style="color: #123456">Texte réécrit</p>';
        fireEvent.input(editor);
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });
        pointerClick(sendButton());

        await waitFor(() => expect(sendRequest(fetchMock)).toBeDefined());
        expect(JSON.parse(sendRequest(fetchMock)[1].body).body_html).toBe('<p style="color: #123456">Texte réécrit</p>');
        // La seule requête de lecture ne modifie rien : aucune écriture vers une route de réglages.
        expect(fetchMock.mock.calls.map(([url]) => url).sort()).toEqual(['/draft', '/mail/draft-1/pdf', '/send']);
    });

    it('refuse d\'envoyer un message vidé par l\'utilisateur', async () => {
        mount();
        const editor = (await screen.findByText('Marché haussier')).closest('[contenteditable="true"]');

        editor.innerHTML = '<p><br></p>';
        fireEvent.input(editor);
        fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });
        pointerClick(sendButton());

        expect(await screen.findByRole('alert')).toHaveTextContent('Le message est vide');
        expect(sendRequest(fetchMock)).toBeUndefined();
    });

    describe('aucun envoi au clavier', () => {
        const keys = [
            ['Entrée', { key: 'Enter' }],
            ['Ctrl + Entrée', { key: 'Enter', ctrlKey: true }],
            ['Cmd + Entrée', { key: 'Enter', metaKey: true }],
        ];

        it.each(keys)('%s dans le champ destinataires', async (_label, init) => {
            const { onClose } = mount();
            await screen.findByText('Marché haussier');
            const input = screen.getByLabelText('Destinataires');
            fireEvent.change(input, { target: { value: 'a@ex.fr' } });

            const notPrevented = fireEvent.keyDown(input, init);

            expect(notPrevented).toBe(false);
            expect(sendRequest(fetchMock)).toBeUndefined();
            expect(onClose).not.toHaveBeenCalled();
        });

        it.each(keys)('%s dans le champ objet', async (_label, init) => {
            const { onClose } = mount();
            await screen.findByText('Marché haussier');
            fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

            const notPrevented = fireEvent.keyDown(screen.getByLabelText('Objet du message'), init);

            expect(notPrevented).toBe(false);
            expect(sendRequest(fetchMock)).toBeUndefined();
            expect(onClose).not.toHaveBeenCalled();
        });

        it('Entrée dans le message n\'est pas interceptée (nouveau paragraphe) et n\'envoie rien', async () => {
            mount();
            const editor = (await screen.findByText('Marché haussier')).closest('[contenteditable="true"]');
            fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

            expect(fireEvent.keyDown(editor, { key: 'Enter' })).toBe(true);
            fireEvent.keyDown(editor, { key: 'Enter', ctrlKey: true });
            fireEvent.keyDown(editor, { key: 'Enter', metaKey: true });

            expect(sendRequest(fetchMock)).toBeUndefined();
        });

        it('ne déclenche aucune soumission implicite du formulaire', async () => {
            mount();
            await screen.findByText('Marché haussier');
            const form = screen.getByLabelText('Destinataires').closest('form');
            fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

            expect(form.querySelector('[type="submit"]')).toBeNull();
            expect(sendButton()).toHaveAttribute('type', 'button');
            expect(fireEvent.submit(form)).toBe(false);
            expect(sendRequest(fetchMock)).toBeUndefined();
        });

        it('n\'envoie pas à l\'activation clavier du bouton, seulement au clic pointeur', async () => {
            const { onSent } = mount();
            await screen.findByText('Marché haussier');
            fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

            fireEvent.click(sendButton(), { detail: 0 });
            expect(sendRequest(fetchMock)).toBeUndefined();

            pointerClick(sendButton());
            await waitFor(() => expect(onSent).toHaveBeenCalled());
            expect(fetchMock.mock.calls.filter(([url]) => url === '/send')).toHaveLength(1);
        });
    });

    describe('destinataire par défaut', () => {
        const sentBody = () => JSON.parse(sendRequest(fetchMock)[1].body);

        it('préremplit « À » avec l\'adresse configurée, comme un destinataire ordinaire', async () => {
            mount();
            await screen.findByText('Marché haussier');

            expect(screen.getByLabelText('Destinataires')).toHaveValue('cotation@jeudy-sa.fr');

            pointerClick(sendButton());
            await waitFor(() => expect(sendRequest(fetchMock)).toBeDefined());
            expect(sentBody().to).toEqual(['cotation@jeudy-sa.fr']);
        });

        it('permet de le remplacer ou de le supprimer', async () => {
            mount();
            await screen.findByText('Marché haussier');
            const input = screen.getByLabelText('Destinataires');

            fireEvent.change(input, { target: { value: 'autre@ex.fr' } });
            expect(input).toHaveValue('autre@ex.fr');

            fireEvent.change(input, { target: { value: '' } });
            pointerClick(sendButton());
            expect(await screen.findByRole('alert')).toHaveTextContent('Renseignez au moins un destinataire.');
            expect(sendRequest(fetchMock)).toBeUndefined();
        });

        it('permet d\'ajouter d\'autres destinataires, envoyés avec l\'adresse affichée', async () => {
            mount();
            await screen.findByText('Marché haussier');
            const input = screen.getByLabelText('Destinataires');

            fireEvent.change(input, { target: { value: `${input.value}, a@ex.fr; b@ex.fr` } });
            pointerClick(sendButton());

            await waitFor(() => expect(sendRequest(fetchMock)).toBeDefined());
            expect(sentBody().to).toEqual(['cotation@jeudy-sa.fr', 'a@ex.fr', 'b@ex.fr']);
        });

        it('ne s\'ajoute pas en double à la réouverture et repart d\'un brouillon vierge', async () => {
            const handlers = { onClose: vi.fn(), onSent: vi.fn(), onCancel: vi.fn() };
            const modal = (show) => <CotationMailModal show={show} draftUrl="/draft" sendUrl="/send" filesBaseUrl="/mail" {...handlers} />;
            const { rerender } = render(modal(true));
            await screen.findByText('Marché haussier');
            fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'cotation@jeudy-sa.fr, a@ex.fr' } });

            rerender(modal(false));
            rerender(modal(true));

            await waitFor(() => expect(screen.getByLabelText('Destinataires')).toHaveValue('cotation@jeudy-sa.fr'));
        });

        it('n\'écrase pas la saisie de l\'utilisateur ni lors d\'un nouveau rendu', async () => {
            let release;
            fetchMock.mockImplementation((url) => (url === '/draft'
                ? new Promise((resolve) => { release = () => resolve({ ok: true, status: 200, json: () => Promise.resolve(DRAFT) }); })
                : route()(url)));
            const { rerender } = render(<CotationMailModal show draftUrl="/draft" sendUrl="/send" filesBaseUrl="/mail" onClose={() => {}} onSent={() => {}} onCancel={() => {}} />);

            // Saisie pendant le chargement du brouillon : conservée.
            fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'saisi@ex.fr' } });
            await act(async () => { release(); });
            await screen.findByText('Marché haussier');
            expect(screen.getByLabelText('Destinataires')).toHaveValue('saisi@ex.fr');

            // Nouveau rendu (mêmes props) : pas de réinitialisation.
            rerender(<CotationMailModal show draftUrl="/draft" sendUrl="/send" filesBaseUrl="/mail" onClose={() => {}} onSent={() => {}} onCancel={() => {}} />);
            fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'saisi@ex.fr, autre@ex.fr' } });
            rerender(<CotationMailModal show draftUrl="/draft" sendUrl="/send" filesBaseUrl="/mail" onClose={() => {}} onSent={() => {}} onCancel={() => {}} />);
            expect(screen.getByLabelText('Destinataires')).toHaveValue('saisi@ex.fr, autre@ex.fr');
        });

        it('ne préremplit rien quand aucune adresse n\'est configurée', async () => {
            fetchMock.mockImplementation(route({ '/draft': () => json({ ...DRAFT, default_recipient: '' }) }));
            mount();
            await screen.findByText('Marché haussier');

            expect(screen.getByLabelText('Destinataires')).toHaveValue('');
        });
    });
});
