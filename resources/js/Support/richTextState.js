/**
 * Calcul de l'état de mise en forme d'une sélection dans l'éditeur riche des
 * cotations (contenteditable). Fonctions pures fondées sur le DOM : chaque
 * portion de texte de la sélection est examinée avec les styles de ses
 * ancêtres (balises b/i/u/s et styles en ligne, seule représentation produite
 * par l'éditeur et conservée par CerealInfoSanitizer), jamais le seul premier
 * nœud. Aucune modification du DOM ni de la sélection.
 *
 * États : 'on' (toute la sélection), 'off' (aucune partie), 'mixed' (une partie
 * seulement). Valeurs (taille, couleurs) : { kind: 'value' | 'mixed' | 'default' }.
 */

export const FONT_TAG_SIZES_PX = [10, 13, 16, 18, 24, 32, 48];

const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

function inlineStyle(element, property) {
    const raw = element.getAttribute?.('style');
    if (!raw) return null;

    let found = null;
    raw.split(';').forEach((declaration) => {
        const index = declaration.indexOf(':');
        if (index === -1) return;
        if (declaration.slice(0, index).trim().toLowerCase() === property) {
            found = declaration.slice(index + 1).trim().toLowerCase();
        }
    });

    return found;
}

function chainFrom(node, root) {
    let element = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement;
    const chain = [];

    while (element && root.contains(element)) {
        chain.push(element);
        if (element === root) break;
        element = element.parentElement;
    }

    return chain;
}

function firstExplicit(chain, read) {
    for (const element of chain) {
        const value = read(element);
        if (value !== undefined && value !== null) return value;
    }

    return null;
}

export function normalizeColor(value) {
    const source = String(value || '').trim().toLowerCase();
    if (!source || source === 'transparent' || source === 'inherit' || source === 'initial') return null;

    const hex = source.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/);
    if (hex) {
        const digits = hex[1].length === 3 ? hex[1].split('').map((c) => c + c).join('') : hex[1];
        return `#${digits}`;
    }

    const rgb = source.match(/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,/]+([\d.]+%?))?\s*\)$/);
    if (rgb) {
        if (rgb[4] !== undefined && parseFloat(rgb[4]) === 0) return null;
        return `#${[rgb[1], rgb[2], rgb[3]].map((n) => Number(n).toString(16).padStart(2, '0')).join('')}`;
    }

    return source;
}

function fontSizePx(value) {
    const match = String(value).match(/^([\d.]+)(px|pt)$/);
    if (!match) return String(value);

    const px = match[2] === 'pt' ? (parseFloat(match[1]) * 4) / 3 : parseFloat(match[1]);

    return Math.round(px * 100) / 100;
}

const readers = {
    bold: (chain) => firstExplicit(chain, (el) => {
        const weight = inlineStyle(el, 'font-weight');
        if (weight) {
            return weight === 'bold' || weight === 'bolder' || (/^\d+$/.test(weight) && Number(weight) >= 600);
        }
        return ['B', 'STRONG'].includes(el.tagName) ? true : null;
    }) ?? false,

    italic: (chain) => firstExplicit(chain, (el) => {
        const style = inlineStyle(el, 'font-style');
        if (style) return style === 'italic' || style === 'oblique';
        return ['I', 'EM'].includes(el.tagName) ? true : null;
    }) ?? false,

    underline: (chain) => chain.some((el) => ['U', 'INS'].includes(el.tagName)
        || /underline/.test(`${inlineStyle(el, 'text-decoration') ?? ''} ${inlineStyle(el, 'text-decoration-line') ?? ''}`)),

    strike: (chain) => chain.some((el) => ['S', 'STRIKE', 'DEL'].includes(el.tagName)
        || /line-through/.test(`${inlineStyle(el, 'text-decoration') ?? ''} ${inlineStyle(el, 'text-decoration-line') ?? ''}`)),

    align: (chain) => {
        const value = firstExplicit(chain, (el) => inlineStyle(el, 'text-align') || el.getAttribute?.('align')?.toLowerCase() || null);
        if (value === 'center' || value === 'right' || value === 'justify') return value;
        if (value === 'end') return 'right';

        return 'left';
    },

    size: (chain) => firstExplicit(chain, (el) => {
        const size = inlineStyle(el, 'font-size');
        if (size) return fontSizePx(size);
        if (el.tagName === 'FONT' && /^[1-7]$/.test(el.getAttribute('size') || '')) {
            return FONT_TAG_SIZES_PX[Number(el.getAttribute('size')) - 1];
        }
        return null;
    }) ?? 'default',

    color: (chain) => firstExplicit(chain, (el) => {
        const value = inlineStyle(el, 'color') || (el.tagName === 'FONT' ? el.getAttribute('color') : null);
        return value ? (normalizeColor(value) ?? 'default') : null;
    }) ?? 'default',

    highlight: (chain) => firstExplicit(chain, (el) => {
        const value = inlineStyle(el, 'background-color') || inlineStyle(el, 'background');
        return value ? (normalizeColor(value) ?? 'default') : null;
    }) ?? 'default',
};

/**
 * Nœuds de texte réellement couverts par la portion sélectionnée.
 */
function selectedTextNodes(root, range) {
    const nodes = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (!range.intersectsNode(node)) continue;
        if (node === range.startContainer && node === range.endContainer) {
            if (range.startOffset === range.endOffset) continue;
        } else if (node === range.startContainer && range.startOffset >= node.length) {
            continue;
        } else if (node === range.endContainer && range.endOffset === 0) {
            continue;
        }
        nodes.push(node);
    }

    const meaningful = nodes.filter((node) => node.textContent.trim() !== '');

    return meaningful.length > 0 ? meaningful : nodes;
}

function combineFlag(values) {
    if (values.every(Boolean)) return 'on';
    if (values.some(Boolean)) return 'mixed';

    return 'off';
}

function combineValue(values) {
    const distinct = [...new Set(values)];
    if (distinct.length > 1) return { kind: 'mixed' };
    if (distinct[0] === 'default') return { kind: 'default' };

    return { kind: 'value', value: distinct[0] };
}

export const EMPTY_FORMAT_STATE = {
    bold: 'off',
    italic: 'off',
    underline: 'off',
    strike: 'off',
    align: { left: 'on', center: 'off', right: 'off', justify: 'off' },
    size: { kind: 'default' },
    color: { kind: 'default' },
    highlight: { kind: 'default' },
};

/**
 * @param {HTMLElement} root  Élément contenteditable de l'éditeur.
 * @param {Range} range       Sélection courante (dans root).
 */
export function computeFormatState(root, range) {
    const anchors = range.collapsed ? [range.startContainer] : selectedTextNodes(root, range);
    const chains = (anchors.length > 0 ? anchors : [range.startContainer]).map((node) => chainFrom(node, root));

    const flag = (key) => combineFlag(chains.map((chain) => readers[key](chain)));
    const value = (key) => combineValue(chains.map((chain) => readers[key](chain)));

    const alignments = chains.map((chain) => readers.align(chain));
    const distinctAlignments = new Set(alignments);
    const align = Object.fromEntries(ALIGNMENTS.map((name) => [
        name,
        !distinctAlignments.has(name) ? 'off' : (distinctAlignments.size === 1 ? 'on' : 'mixed'),
    ]));

    return {
        bold: flag('bold'),
        italic: flag('italic'),
        underline: flag('underline'),
        strike: flag('strike'),
        align,
        size: value('size'),
        color: value('color'),
        highlight: value('highlight'),
    };
}
