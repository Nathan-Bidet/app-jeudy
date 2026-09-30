import { describe, expect, it } from 'vitest';
import { buildCotationMailHref, cotationMailSubject, infoHtmlToLines } from '@/Support/cotationMail';

const decode = (href) => {
    const query = href.slice(href.indexOf('?') + 1);
    const params = new URLSearchParams(query);

    return { subject: params.get('subject'), body: params.get('body') };
};

describe('cotationMailSubject', () => {
    it('formate la date du jour au format français', () => {
        expect(cotationMailSubject('Europe/Paris', new Date('2026-09-30T10:00:00Z'))).toBe('Cotation du 30/09/2026');
    });

    it('utilise le fuseau de l\'application (minuit passé à Paris)', () => {
        const now = new Date('2026-09-29T23:30:00Z');

        expect(cotationMailSubject('Europe/Paris', now)).toBe('Cotation du 30/09/2026');
        expect(cotationMailSubject('UTC', now)).toBe('Cotation du 29/09/2026');
    });
});

describe('infoHtmlToLines', () => {
    it('conserve paragraphes, lignes vides et retours à la ligne', () => {
        const html = '<p><b>Marché</b> haussier</p><p><br></p><div>Ligne 1<br>Ligne 2</div><p>Fin</p>';

        expect(infoHtmlToLines(html)).toEqual(['Marché haussier', '', 'Ligne 1', 'Ligne 2', 'Fin']);
    });

    it('ignore les styles et garde le texte, sans corrompre les accents', () => {
        const html = '<p><span style="color:#b91c1c">Prix à l\'été : 250 € &amp; plus</span></p>';

        expect(infoHtmlToLines(html)).toEqual(["Prix à l'été : 250 € & plus"]);
    });

    it('gère un message vide', () => {
        expect(infoHtmlToLines('')).toEqual(['']);
        expect(infoHtmlToLines(null)).toEqual(['']);
    });
});

describe('buildCotationMailHref', () => {
    const now = new Date('2026-09-30T10:00:00Z');

    it('construit un mailto: sans destinataire, encodé', () => {
        const href = buildCotationMailHref('<p>Bonjour à tous</p><p><br></p><p>100 € & 50 %</p>', 'Europe/Paris', now);

        expect(href.startsWith('mailto:?subject=Cotation%20du%2030%2F09%2F2026&body=')).toBe(true);
        expect(href).not.toMatch(/[ \n]/);
        expect(decode(href)).toEqual({
            subject: 'Cotation du 30/09/2026',
            body: 'Bonjour à tous\n\n100 € & 50 %',
        });
    });

    it('envoie un corps vide pour un message vide', () => {
        expect(decode(buildCotationMailHref('<p><br></p>', 'Europe/Paris', now)).body).toBe('');
    });
});
