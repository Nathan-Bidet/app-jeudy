import { buildMailHref } from '@/Support/contactLinks';

const BLOCK_TAGS = new Set(['P', 'DIV']);

/**
 * Objet du message : « Cotation du JJ/MM/AAAA », date du jour dans le fuseau
 * configuré côté serveur (config app.timezone), format français.
 */
export function cotationMailSubject(timeZone = 'Europe/Paris', now = new Date()) {
    const date = new Intl.DateTimeFormat('fr-FR', {
        timeZone,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(now);

    return `Cotation du ${date}`;
}

/**
 * Convertit le HTML du bloc « Information » (déjà assaini côté serveur) en
 * lignes de texte brut : un bloc <p>/<div> = une ligne, <br> = retour à la
 * ligne, un bloc vide (<p><br></p>) = ligne vide. Les styles (couleurs,
 * italique…) ne peuvent pas être transmis par mailto: et sont abandonnés.
 *
 * @param {string} html
 * @returns {string[]}
 */
export function infoHtmlToLines(html) {
    const doc = new DOMParser().parseFromString(`<body>${html || ''}</body>`, 'text/html');
    let out = '';

    const walk = (node) => {
        node.childNodes.forEach((child) => {
            if (child.nodeType === Node.TEXT_NODE) {
                out += child.textContent.replace(/[\s ]+/g, ' ');
                return;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) return;

            if (child.tagName === 'BR') {
                out += '\n';
                return;
            }

            const isBlock = BLOCK_TAGS.has(child.tagName);
            if (isBlock && out !== '' && !out.endsWith('\n')) out += '\n';
            walk(child);
            if (isBlock && !out.endsWith('\n')) out += '\n';
        });
    };

    walk(doc.body);

    return out
        .split('\n')
        .map((line) => line.trim())
        .join('\n')
        .replace(/\n+$/, '')
        .split('\n');
}

/**
 * mailto: sans destinataire, objet daté et corps texte du message d'information.
 * Encodage délégué à buildMailHref (encodeURIComponent).
 */
export function buildCotationMailHref(infoHtml, timeZone, now = new Date()) {
    const hasText = infoHtmlToLines(infoHtml).some((line) => line !== '');
    const lines = hasText ? infoHtmlToLines(infoHtml) : [];

    return buildMailHref('', cotationMailSubject(timeZone, now), lines);
}
