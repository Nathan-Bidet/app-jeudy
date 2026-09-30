import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';

import CotationMailModal from '@/Components/Cotations/CotationMailModal';

const LIMITS = { max_files: 2, max_file_bytes: 1024 * 1024, max_total_bytes: 1.5 * 1024 * 1024, extensions: ['pdf', 'png', 'txt'] };
const DRAFT = { subject: 'Cotation du 30/09/2026', body_html: '<p>Bonjour</p>', is_empty: false, draft_id: 'draft-1', limits: LIMITS };
const PDF = { id: 1, kind: 'pdf', name: 'COTATIONS 30.09.2026.pdf', type: 'application/pdf', size: 20480 };
const PDF_2 = { ...PDF, id: 2, size: 30720 };

const json = (data, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });
const pointerClick = (element) => fireEvent.click(element, { detail: 1 });
const sendButton = () => screen.getByRole('button', { name: /Envoyer le message/ });
const calls = (fetchMock, suffix, method) => fetchMock.mock.calls.filter(([url, options]) => url.endsWith(suffix) && (!method || (options?.method || 'GET') === method));

class FakeXHR {
    static instances = [];

    constructor() {
        this.upload = {};
        this.headers = {};
        FakeXHR.instances.push(this);
    }

    open(method, url) { this.method = method; this.url = url; }

    setRequestHeader(name, value) { this.headers[name] = value; }

    send(body) { this.body = body; }

    abort() { this.aborted = true; this.onabort?.(); }

    progress(loaded, total) { this.upload.onprogress?.({ lengthComputable: true, loaded, total }); }

    finish(status, data) {
        this.status = status;
        this.responseText = JSON.stringify(data);
        this.onload?.();
    }
}

let fetchMock;
let pdfResponses;

const route = (overrides = {}) => (url, options = {}) => {
    const key = Object.keys(overrides).find((suffix) => url.endsWith(suffix) && (!overrides[suffix].method || overrides[suffix].method === (options.method || 'GET')));
    if (key) return overrides[key].handler(url, options);
    if (url === '/draft') return json(DRAFT);
    if (url.endsWith('/pdf')) return json({ attachment: pdfResponses.shift() || PDF });
    if (url === '/send') return json({ ok: true, sent: 1 });

    return json({ ok: true });
};

async function mount(overrides = {}) {
    fetchMock.mockImplementation(route(overrides));
    const handlers = { onClose: vi.fn(), onSent: vi.fn(), onCancel: vi.fn() };
    render(<CotationMailModal show draftUrl="/draft" sendUrl="/send" filesBaseUrl="/mail" {...handlers} />);
    await screen.findByText('Bonjour');

    return handlers;
}

const pickFiles = (...files) => {
    const input = screen.getByLabelText('Sélectionner des pièces jointes');
    fireEvent.change(input, { target: { files } });
};

const makeFile = (name, size = 1024, type = 'application/pdf') => {
    const file = new File(['x'], name, { type });
    Object.defineProperty(file, 'size', { value: size });

    return file;
};

const fillRecipient = () => fireEvent.change(screen.getByLabelText('Destinataires'), { target: { value: 'a@ex.fr' } });

beforeEach(() => {
    FakeXHR.instances = [];
    pdfResponses = [];
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('XMLHttpRequest', FakeXHR);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('PDF joint automatiquement', () => {
    it('génère le PDF à l\'ouverture et l\'affiche (nom, type, taille) sans le télécharger', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        await mount();

        expect(await screen.findByText('COTATIONS 30.09.2026.pdf')).toBeInTheDocument();
        expect(screen.getByText(/PDF · 20 Ko/)).toBeInTheDocument();
        expect(calls(fetchMock, '/mail/draft-1/pdf', 'POST')).toHaveLength(1);
        expect(screen.getByRole('link', { name: 'Télécharger le PDF' })).toHaveAttribute('href', '/mail/draft-1/attachments/1');
        expect(open).not.toHaveBeenCalled();
    });

    it('affiche « Génération du PDF… » et bloque l\'envoi pendant la génération', async () => {
        let finish;
        await mount({ '/pdf': { handler: () => new Promise((resolve) => { finish = resolve; }) } });
        fillRecipient();

        expect((await screen.findAllByText('Génération du PDF…')).length).toBeGreaterThan(0);
        expect(sendButton()).toBeDisabled();

        await act(async () => { finish({ ok: true, status: 200, json: () => Promise.resolve({ attachment: PDF }) }); });

        expect(await screen.findByText('COTATIONS 30.09.2026.pdf')).toBeInTheDocument();
        expect(sendButton()).not.toBeDisabled();
    });

    it('supprime le PDF, permet d\'envoyer sans lui, puis de le recréer', async () => {
        await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');

        pointerClick(screen.getByRole('button', { name: 'Supprimer le PDF' }));

        expect(await screen.findByText('Aucun PDF joint')).toBeInTheDocument();
        expect(calls(fetchMock, '/mail/draft-1/attachments/1', 'DELETE')).toHaveLength(1);

        fillRecipient();
        pointerClick(sendButton());
        await waitFor(() => expect(calls(fetchMock, '/send', 'POST')).toHaveLength(1));
        expect(JSON.parse(calls(fetchMock, '/send', 'POST')[0][1].body).attachment_ids).toEqual([]);
    });

    it('recrée le PDF avec « Générer le PDF »', async () => {
        pdfResponses = [PDF, PDF_2];
        await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');
        pointerClick(screen.getByRole('button', { name: 'Supprimer le PDF' }));
        await screen.findByText('Aucun PDF joint');

        pointerClick(screen.getByRole('button', { name: 'Générer le PDF' }));

        expect(await screen.findByText(/PDF généré · PDF · 30 Ko/)).toBeInTheDocument();
        expect(calls(fetchMock, '/pdf', 'POST')).toHaveLength(2);
    });

    it('régénère : envoie l\'ancien identifiant et remplace le PDF après succès', async () => {
        pdfResponses = [PDF, PDF_2];
        await mount();
        await screen.findByText(/PDF généré · PDF · 20 Ko/);

        pointerClick(screen.getByRole('button', { name: 'Régénérer' }));

        expect(await screen.findByText(/PDF généré · PDF · 30 Ko/)).toBeInTheDocument();
        expect(JSON.parse(calls(fetchMock, '/pdf', 'POST')[1][1].body)).toEqual({ replaces: 1 });
    });

    it('empêche les clics multiples pendant une régénération', async () => {
        let finish;
        await mount();
        await screen.findByText(/PDF généré/);
        fetchMock.mockImplementation(route({ '/pdf': { handler: () => new Promise((resolve) => { finish = resolve; }) } }));

        const regenerate = screen.getByRole('button', { name: 'Régénérer' });
        pointerClick(regenerate);
        pointerClick(regenerate);
        pointerClick(regenerate);

        await waitFor(() => expect(screen.getByRole('button', { name: 'Régénérer' })).toBeDisabled());
        expect(calls(fetchMock, '/pdf', 'POST')).toHaveLength(2); // génération initiale + une seule régénération
        expect(sendButton()).toBeDisabled();

        await act(async () => { finish({ ok: true, status: 200, json: () => Promise.resolve({ attachment: PDF_2 }) }); });
    });

    it('conserve l\'ancien PDF et affiche une erreur quand la régénération échoue', async () => {
        await mount();
        await screen.findByText(/PDF généré · PDF · 20 Ko/);
        fetchMock.mockImplementation(route({ '/pdf': { handler: () => json({ message: 'Le PDF n\'a pas pu être généré. Réessayez dans un instant.' }, 500) } }));

        pointerClick(screen.getByRole('button', { name: 'Régénérer' }));

        expect(await screen.findByText('Le PDF n\'a pas pu être généré. Réessayez dans un instant.')).toBeInTheDocument();
        expect(screen.getByText('COTATIONS 30.09.2026.pdf')).toBeInTheDocument();
        expect(sendButton()).not.toBeDisabled();
    });

    it('envoie avec l\'identifiant du PDF et celui du brouillon', async () => {
        await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');
        fillRecipient();

        pointerClick(sendButton());

        await waitFor(() => expect(calls(fetchMock, '/send', 'POST')).toHaveLength(1));
        const body = JSON.parse(calls(fetchMock, '/send', 'POST')[0][1].body);
        expect(body.draft_id).toBe('draft-1');
        expect(body.attachment_ids).toEqual([1]);
    });
});

describe('autres pièces jointes', () => {
    it('affiche les limites applicables', async () => {
        await mount();

        expect(screen.getByText(/2 fichiers maximum · 1 Mo par fichier · 1,5 Mo au total \(PDF inclus\) · types : pdf, png, txt/)).toBeInTheDocument();
    });

    it('téléverse plusieurs fichiers avec progression, puis les supprime individuellement', async () => {
        await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');

        pickFiles(makeFile('devis.pdf', 2048), makeFile('photo.png', 4096, 'image/png'));
        expect(FakeXHR.instances).toHaveLength(2);
        expect(FakeXHR.instances[0].url).toBe('/mail/draft-1/files');
        expect(FakeXHR.instances[0].body.get('file').name).toBe('devis.pdf');

        act(() => FakeXHR.instances[0].progress(50, 100));
        expect(screen.getByLabelText('Téléversement de devis.pdf')).toHaveValue(50);
        expect(screen.getByText('50 %')).toBeInTheDocument();
        expect(sendButton()).toBeDisabled();

        act(() => FakeXHR.instances[0].finish(200, { attachment: { id: 11, kind: 'file', name: 'devis.pdf', type: 'application/pdf', size: 2048 } }));
        act(() => FakeXHR.instances[1].finish(200, { attachment: { id: 12, kind: 'file', name: 'photo.png', type: 'image/png', size: 4096 } }));

        await waitFor(() => expect(sendButton()).not.toBeDisabled());
        expect(screen.getByText('devis.pdf')).toBeInTheDocument();
        expect(screen.getByText(/PNG · 4 Ko/)).toBeInTheDocument();

        pointerClick(screen.getByRole('button', { name: 'Supprimer devis.pdf' }));
        await waitFor(() => expect(screen.queryByText('devis.pdf')).not.toBeInTheDocument());
        expect(calls(fetchMock, '/mail/draft-1/attachments/11', 'DELETE')).toHaveLength(1);

        fillRecipient();
        pointerClick(sendButton());
        await waitFor(() => expect(calls(fetchMock, '/send', 'POST')).toHaveLength(1));
        expect(JSON.parse(calls(fetchMock, '/send', 'POST')[0][1].body).attachment_ids).toEqual([1, 12]);
    });

    it('bloque l\'envoi tant qu\'un téléversement échoue, jusqu\'au retrait du fichier en erreur', async () => {
        await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');
        fillRecipient();

        pickFiles(makeFile('devis.pdf'));
        act(() => FakeXHR.instances[0].finish(422, { errors: { file: ['Le contenu du fichier ne correspond pas à son extension.'] } }));

        expect(await screen.findByText('Le contenu du fichier ne correspond pas à son extension.')).toBeInTheDocument();
        expect(sendButton()).toBeDisabled();

        pointerClick(screen.getByRole('button', { name: 'Supprimer devis.pdf' }));
        await waitFor(() => expect(sendButton()).not.toBeDisabled());
        expect(calls(fetchMock, '/attachments/', 'DELETE')).toHaveLength(0); // jamais enregistré côté serveur
    });

    it('annule un téléversement en cours', async () => {
        await mount();
        pickFiles(makeFile('devis.pdf'));

        pointerClick(screen.getByRole('button', { name: 'Annuler devis.pdf' }));

        expect(FakeXHR.instances[0].aborted).toBe(true);
        expect(screen.queryByText('devis.pdf')).not.toBeInTheDocument();
    });

    it.each([
        ['type non autorisé', () => makeFile('virus.exe'), 'type non autorisé (.exe)'],
        ['fichier vide', () => makeFile('vide.pdf', 0), 'le fichier est vide'],
        ['trop volumineux', () => makeFile('gros.pdf', 2 * 1024 * 1024), 'dépasse la taille maximale de 1 Mo par fichier'],
    ])('refuse côté interface : %s', async (_label, build, message) => {
        await mount();

        pickFiles(build());

        expect(await screen.findByRole('alert')).toHaveTextContent(message);
        expect(FakeXHR.instances).toHaveLength(0);
    });

    it('refuse au-delà du nombre maximal et de la taille totale', async () => {
        await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');

        // Total : PDF 20 Ko + 0,9 Mo + 0,9 Mo > 1,5 Mo.
        pickFiles(makeFile('a.pdf', 900 * 1024), makeFile('b.pdf', 900 * 1024));
        expect(FakeXHR.instances).toHaveLength(1);
        expect(screen.getByRole('alert')).toHaveTextContent('la taille totale dépasserait 1,5 Mo');

        // Nombre : 2 fichiers autorisés.
        pickFiles(makeFile('c.txt', 100), makeFile('d.txt', 100));
        expect(FakeXHR.instances).toHaveLength(2);
        expect(screen.getByRole('alert')).toHaveTextContent('nombre maximal de 2 fichiers atteint');
    });

    it('supprime les fichiers temporaires à l\'annulation du brouillon', async () => {
        const { onCancel } = await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');
        pickFiles(makeFile('devis.pdf'));

        fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));

        expect(calls(fetchMock, '/mail/draft-1', 'DELETE')).toHaveLength(1);
        expect(FakeXHR.instances[0].aborted).toBe(true);
        expect(onCancel).toHaveBeenCalled();
    });

    it('ne supprime pas le brouillon après un envoi réussi (le serveur nettoie)', async () => {
        const { onSent } = await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');
        fillRecipient();

        pointerClick(sendButton());

        await waitFor(() => expect(onSent).toHaveBeenCalled());
        expect(calls(fetchMock, '/mail/draft-1', 'DELETE')).toHaveLength(0);
    });

    it('conserve les pièces jointes et la saisie après un échec d\'envoi', async () => {
        await mount({ '/send': { handler: () => json({ message: 'L\'e-mail n\'a pas pu être envoyé.' }, 502) } });
        await screen.findByText('COTATIONS 30.09.2026.pdf');
        fillRecipient();

        pointerClick(sendButton());

        expect(await screen.findByText('L\'e-mail n\'a pas pu être envoyé.')).toBeInTheDocument();
        expect(screen.getByText('COTATIONS 30.09.2026.pdf')).toBeInTheDocument();
        expect(screen.getByLabelText('Destinataires')).toHaveValue('a@ex.fr');
        expect(sendButton()).not.toBeDisabled();
    });

    it('n\'envoie jamais avec Entrée, même avec des pièces jointes', async () => {
        await mount();
        await screen.findByText('COTATIONS 30.09.2026.pdf');
        fillRecipient();

        [{ key: 'Enter' }, { key: 'Enter', ctrlKey: true }, { key: 'Enter', metaKey: true }].forEach((init) => {
            fireEvent.keyDown(screen.getByLabelText('Destinataires'), init);
            fireEvent.keyDown(screen.getByLabelText('Objet du message'), init);
        });
        fireEvent.submit(screen.getByLabelText('Destinataires').closest('form'));

        expect(calls(fetchMock, '/send', 'POST')).toHaveLength(0);
        // La liste des pièces jointes reste opérable sans envoi (boutons de type button).
        within(screen.getByRole('region', { name: 'Pièces jointes' })).getAllByRole('button').forEach((button) => {
            expect(button).toHaveAttribute('type', 'button');
        });
    });
});
