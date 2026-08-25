import { describe, expect, it } from 'vitest';
import { buildMailHref, buildSmsHref, isFrenchMobileNumber, singleLineText, toMailHref, toSmsHref, toTelHref } from '@/Support/contactLinks';

/**
 * Primitives partagées avec le Livre du travail (EntryCard.jsx) : mêmes
 * formats d'URL (sms:...?body=, mailto:...?subject=&body=), mêmes règles de
 * nettoyage du numéro et d'encodage.
 */

describe('toTelHref / toSmsHref', () => {
    it('strips everything but digits and a leading + from the number', () => {
        expect(toTelHref('06 12 34 56 78')).toBe('tel:0612345678');
        expect(toSmsHref('+33 6 12 34 56 78')).toBe('sms:+33612345678');
    });

    it('returns null for an empty/missing number', () => {
        expect(toTelHref('')).toBeNull();
        expect(toTelHref(null)).toBeNull();
        expect(toSmsHref(undefined)).toBeNull();
    });
});

describe('toMailHref', () => {
    it('encodes the address and returns null when empty', () => {
        expect(toMailHref('jean.dupont@example.com')).toBe('mailto:jean.dupont%40example.com');
        expect(toMailHref('')).toBeNull();
        expect(toMailHref(null)).toBeNull();
    });
});

describe('buildSmsHref', () => {
    it('uses ?body= (not &body=) after the number', () => {
        const href = buildSmsHref('0612345678', ['Bonjour']);
        expect(href).toBe('sms:0612345678?body=Bonjour');
    });

    it('encodes accents and newlines in the body (encodeURIComponent leaves apostrophes literal, same as EntryCard.jsx)', () => {
        const href = buildSmsHref('0612345678', ["Livraison à l'entrepôt", 'Ligne 2']);
        expect(href).toBe(`sms:0612345678?body=${encodeURIComponent("Livraison à l'entrepôt\nLigne 2")}`);
        expect(href).toContain('%C3%A0'); // à
        expect(href).toContain("l'entrep%C3%B4t"); // apostrophe preserved as-is
        expect(href).toContain('%0A'); // \n
    });

    it('returns null when the number is missing', () => {
        expect(buildSmsHref('', ['Bonjour'])).toBeNull();
        expect(buildSmsHref(null, ['Bonjour'])).toBeNull();
    });
});

describe('buildMailHref', () => {
    it('encodes address, subject and body separately', () => {
        const href = buildMailHref('a@b.com', 'Objet clair', ['Ligne 1', 'Ligne 2']);
        expect(href).toBe(
            `mailto:${encodeURIComponent('a@b.com')}?subject=${encodeURIComponent('Objet clair')}&body=${encodeURIComponent('Ligne 1\nLigne 2')}`,
        );
    });

    it('never returns null: builds an empty-recipient mailto: when the address is missing (subject/body still prefilled)', () => {
        const href = buildMailHref('', 'Objet', ['x']);
        expect(href).toBe(`mailto:?subject=${encodeURIComponent('Objet')}&body=${encodeURIComponent('x')}`);
        expect(href).toBe(buildMailHref(null, 'Objet', ['x']));
        expect(href).toBe(buildMailHref(undefined, 'Objet', ['x']));
    });
});

describe('isFrenchMobileNumber', () => {
    it('recognizes national 06/07 numbers regardless of spaces/dots/dashes', () => {
        expect(isFrenchMobileNumber('06 77 27 03 03')).toBe(true);
        expect(isFrenchMobileNumber('07 12 34 56 78')).toBe(true);
        expect(isFrenchMobileNumber('06.77.27.03.03')).toBe(true);
        expect(isFrenchMobileNumber('06-77-27-03-03')).toBe(true);
    });

    it('recognizes +33/0033 international equivalents, including "(0)" liaison', () => {
        expect(isFrenchMobileNumber('+33 6 77 27 03 03')).toBe(true);
        expect(isFrenchMobileNumber('+33 7 12 34 56 78')).toBe(true);
        expect(isFrenchMobileNumber('0033 7 12 34 56 78')).toBe(true);
        expect(isFrenchMobileNumber('+33 (0)6 77 27 03 03')).toBe(true);
        expect(isFrenchMobileNumber('+336772703 03')).toBe(true);
    });

    it('rejects French landline prefixes (01-05, 09)', () => {
        for (const prefix of ['01', '02', '03', '04', '05', '09']) {
            expect(isFrenchMobileNumber(`${prefix} 23 45 67 89`)).toBe(false);
        }
    });

    it('rejects incomplete, too-long, or non-numeric (extension) values instead of matching loosely on "06"/"07"', () => {
        expect(isFrenchMobileNumber('06 77 27 03')).toBe(false); // incomplete (8 digits)
        expect(isFrenchMobileNumber('06 77 27 03 03 12')).toBe(false); // too long
        expect(isFrenchMobileNumber('appelez le 06 77 27 03 03')).toBe(false); // not purely a number
        expect(isFrenchMobileNumber('06 77 27 03 03 poste 12')).toBe(false); // extension
        expect(isFrenchMobileNumber('')).toBe(false);
        expect(isFrenchMobileNumber(null)).toBe(false);
    });
});

describe('singleLineText', () => {
    it('collapses newlines into " | " and strips ** / ~~ markers', () => {
        expect(singleLineText('**Zone A**\nQuai 3')).toBe('Zone A | Quai 3');
    });

    it('returns an empty string for empty input', () => {
        expect(singleLineText('')).toBe('');
        expect(singleLineText(null)).toBe('');
    });
});
