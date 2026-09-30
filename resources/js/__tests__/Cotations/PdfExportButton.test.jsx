import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

import PdfExportButton, { filenameFromDisposition } from '@/Components/Cotations/PdfExportButton';

const pdfResponse = ({ status = 200, type = 'application/pdf', disposition = 'attachment; filename="COTATIONS 30.09.2026.pdf"', body = '%PDF-1.4 contenu', text = '' } = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (name) => ({ 'Content-Type': type, 'Content-Disposition': disposition })[name] ?? null },
    blob: vi.fn(() => Promise.resolve(new Blob([body], { type }))),
    text: vi.fn(() => Promise.resolve(text)),
});

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });

    return { promise, resolve, reject };
};

let fetchMock;
let clicked;

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    URL.createObjectURL = vi.fn(() => 'blob:cotations');
    URL.revokeObjectURL = vi.fn();
    clicked = [];
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function click() {
        clicked.push({ href: this.href, download: this.download });
    });
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const button = (name = /Export PDF|Génération/) => screen.getByRole('button', { name });

describe('PdfExportButton', () => {
    it('affiche l\'animation, le libellé « Génération… », désactive le bouton et garde son état jusqu\'à la réception complète', async () => {
        const response = deferred();
        const blob = deferred();
        fetchMock.mockReturnValue(response.promise);
        render(<PdfExportButton url="/export" />);
        const before = button().getBoundingClientRect().width;

        fireEvent.click(button());

        expect(button()).toBeDisabled();
        expect(button()).toHaveAttribute('aria-busy', 'true');
        expect(button()).toHaveAttribute('data-state', 'busy');
        expect(screen.getByText('Génération…')).toBeVisible();
        expect(screen.getByRole('status')).toHaveTextContent('Génération du PDF en cours…');
        expect(button().querySelector('svg.animate-spin')).not.toBeNull();
        expect(button().querySelector('svg.motion-reduce\\:animate-none')).not.toBeNull();
        expect(button().getBoundingClientRect().width).toBe(before);

        // Réponse reçue mais corps pas encore lu : toujours en chargement.
        const ok = pdfResponse();
        ok.blob.mockReturnValue(blob.promise);
        await act(async () => { response.resolve(ok); });
        expect(button()).toBeDisabled();
        expect(URL.createObjectURL).not.toHaveBeenCalled();

        await act(async () => { blob.resolve(new Blob(['%PDF-1.4 x'], { type: 'application/pdf' })); });
        await waitFor(() => expect(button()).not.toBeDisabled());
    });

    it('empêche deux exports simultanés', async () => {
        const response = deferred();
        fetchMock.mockReturnValue(response.promise);
        render(<PdfExportButton url="/export" />);

        fireEvent.click(button());
        fireEvent.click(button());
        fireEvent.click(button());

        expect(fetchMock).toHaveBeenCalledTimes(1);
        await act(async () => { response.resolve(pdfResponse()); });
        await waitFor(() => expect(button()).not.toBeDisabled());
    });

    it('télécharge sous le nom du serveur, puis revient à l\'état normal et libère l\'URL temporaire', async () => {
        fetchMock.mockResolvedValue(pdfResponse());
        render(<PdfExportButton url="/export" />);

        fireEvent.click(button());

        await waitFor(() => expect(clicked).toHaveLength(1));
        expect(clicked[0].download).toBe('COTATIONS 30.09.2026.pdf');
        expect(clicked[0].href).toBe('blob:cotations');
        expect(fetchMock).toHaveBeenCalledWith('/export', expect.objectContaining({ credentials: 'same-origin' }));
        await waitFor(() => expect(button()).not.toBeDisabled());
        expect(button()).toHaveAttribute('aria-busy', 'false');
        expect(button()).toHaveAttribute('data-state', 'idle');
        expect(screen.getByRole('status')).toBeEmptyDOMElement();
        expect(document.querySelector('a[download]')).toBeNull();

        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        act(() => { vi.advanceTimersByTime(1000); });
        expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:cotations');
        expect(URL.createObjectURL).toHaveBeenCalledTimes(1);

        // On peut réessayer / réexporter.
        fetchMock.mockResolvedValue(pdfResponse());
        fireEvent.click(button());
        await waitFor(() => expect(clicked).toHaveLength(2));
    });

    it('utilise le nom encodé (filename*) et un nom de repli sans en-tête', async () => {
        fetchMock.mockResolvedValueOnce(pdfResponse({ disposition: "attachment; filename=cotations.pdf; filename*=utf-8''COTATIONS%2005.03.2026.pdf" }));
        render(<PdfExportButton url="/export" fallbackName="repli.pdf" />);
        fireEvent.click(button());
        await waitFor(() => expect(clicked[0]?.download).toBe('COTATIONS 05.03.2026.pdf'));
        await waitFor(() => expect(button()).not.toBeDisabled());

        fetchMock.mockResolvedValueOnce(pdfResponse({ disposition: null }));
        fireEvent.click(button());
        await waitFor(() => expect(clicked[1]?.download).toBe('repli.pdf'));
    });

    it.each([
        ['erreur serveur 500', () => pdfResponse({ status: 500, type: 'text/plain', text: 'Erreur export PDF : dompdf' }), 'Erreur export PDF : dompdf'],
        ['redirection vers une page HTML (accès refusé)', () => pdfResponse({ type: 'text/html; charset=UTF-8' }), 'Le PDF n\'a pas pu être généré. Réessayez dans un instant.'],
        ['PDF vide', () => pdfResponse({ body: '' }), 'Le PDF reçu est vide. Réessayez dans un instant.'],
    ])('notifie l\'échec et ne télécharge rien : %s', async (_label, build, message) => {
        fetchMock.mockResolvedValue(build());
        const onError = vi.fn();
        render(<PdfExportButton url="/export" onError={onError} />);

        fireEvent.click(button());

        await waitFor(() => expect(onError).toHaveBeenCalledWith(message));
        expect(clicked).toHaveLength(0);
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        await waitFor(() => expect(button()).not.toBeDisabled());
        expect(button()).toHaveAttribute('aria-busy', 'false');
        expect(button()).toHaveTextContent('Export PDF');
    });

    it('notifie une erreur réseau, se débloque et permet de réessayer', async () => {
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        const onError = vi.fn();
        render(<PdfExportButton url="/export" onError={onError} />);

        fireEvent.click(button());
        await waitFor(() => expect(onError).toHaveBeenCalledWith('Failed to fetch'));
        await waitFor(() => expect(button()).not.toBeDisabled());

        fetchMock.mockResolvedValueOnce(pdfResponse());
        fireEvent.click(button());
        await waitFor(() => expect(clicked).toHaveLength(1));
    });

    it('ne reste pas bloqué si la lecture du fichier échoue (téléchargement incomplet)', async () => {
        const broken = pdfResponse();
        broken.blob.mockRejectedValue(new TypeError('network error'));
        fetchMock.mockResolvedValue(broken);
        const onError = vi.fn();
        render(<PdfExportButton url="/export" onError={onError} />);

        fireEvent.click(button());

        await waitFor(() => expect(onError).toHaveBeenCalledWith('network error'));
        expect(clicked).toHaveLength(0);
        await waitFor(() => expect(button()).not.toBeDisabled());
    });

    it('a des états de chargement indépendants pour plusieurs boutons', async () => {
        const first = deferred();
        fetchMock.mockImplementation((url) => (url === '/a' ? first.promise : Promise.resolve(pdfResponse())));
        render(
            <>
                <PdfExportButton url="/a" label="Export A" />
                <PdfExportButton url="/b" label="Export B" />
            </>,
        );
        const [a, b] = screen.getAllByRole('button');

        fireEvent.click(a);
        expect(a).toBeDisabled();
        expect(b).not.toBeDisabled();

        fireEvent.click(b);
        await waitFor(() => expect(clicked).toHaveLength(1));
        await waitFor(() => expect(b).not.toBeDisabled());
        expect(a).toBeDisabled();

        await act(async () => { first.resolve(pdfResponse()); });
        await waitFor(() => expect(a).not.toBeDisabled());
    });

    it('interrompt la requête et ne met pas à jour l\'état après démontage', async () => {
        let signal;
        fetchMock.mockImplementation((_url, options) => {
            signal = options.signal;

            return new Promise(() => {});
        });
        const { unmount } = render(<PdfExportButton url="/export" />);

        fireEvent.click(button());
        unmount();

        expect(signal.aborted).toBe(true);
    });

    it('un bouton désactivé n\'est pas activable au clavier', async () => {
        fetchMock.mockReturnValue(new Promise(() => {}));
        render(<PdfExportButton url="/export" />);
        fireEvent.click(button());

        fireEvent.keyDown(button(), { key: 'Enter' });
        fireEvent.click(button());

        expect(button()).toBeDisabled();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('filenameFromDisposition', () => {
    it.each([
        ['attachment; filename="COTATIONS 30.09.2026.pdf"', 'COTATIONS 30.09.2026.pdf'],
        ["attachment; filename=x.pdf; filename*=UTF-8''COTATIONS%2030.09.2026.pdf", 'COTATIONS 30.09.2026.pdf'],
        ['attachment; filename=simple.pdf', 'simple.pdf'],
        [null, null],
        ['inline', null],
    ])('%s', (header, expected) => {
        expect(filenameFromDisposition(header)).toBe(expected);
    });
});
