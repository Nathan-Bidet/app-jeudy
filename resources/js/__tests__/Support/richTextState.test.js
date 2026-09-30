import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { computeFormatState, normalizeColor } from '@/Support/richTextState';

let root;

const mount = (html) => {
    root = document.createElement('div');
    root.setAttribute('contenteditable', 'true');
    root.innerHTML = html;
    document.body.appendChild(root);

    return root;
};

const selectAll = () => {
    const range = document.createRange();
    range.selectNodeContents(root);

    return range;
};

const between = (fromNode, fromOffset, toNode, toOffset) => {
    const range = document.createRange();
    range.setStart(fromNode, fromOffset);
    range.setEnd(toNode, toOffset);

    return range;
};

const caretIn = (selector, offset = 1) => {
    const text = root.querySelector(selector).firstChild;
    const range = document.createRange();
    range.setStart(text, offset);
    range.collapse(true);

    return range;
};

const stateOf = (html, range) => {
    mount(html);

    return computeFormatState(root, range ? range() : selectAll());
};

beforeEach(() => { root = null; });
afterEach(() => { root?.remove(); });

describe('styles activables (gras, italique, souligné, barré)', () => {
    const cases = [
        ['bold', '<b>', '</b>', 'font-weight: bold'],
        ['italic', '<i>', '</i>', 'font-style: italic'],
        ['underline', '<u>', '</u>', 'text-decoration: underline'],
        ['strike', '<s>', '</s>', 'text-decoration-line: line-through'],
    ];

    it.each(cases)('%s : toute la sélection', (key, open, close, css) => {
        expect(stateOf(`<p>${open}Un${close} ${open}deux${close}</p>`)[key]).not.toBe('off');
        expect(stateOf(`<p><span style="${css}">Un deux</span></p>`)[key]).toBe('on');
        expect(stateOf(`<p>${open}Un deux${close}</p>`)[key]).toBe('on');
    });

    it.each(cases)('%s : aucune partie', (key) => {
        expect(stateOf('<p>Un deux</p>')[key]).toBe('off');
    });

    it.each(cases)('%s : partie seulement => mixte', (key, open, close) => {
        expect(stateOf(`<p>${open}Un${close} deux</p>`)[key]).toBe('mixed');
        expect(stateOf(`<p>Un ${open}deux${close}</p><p>trois</p>`)[key]).toBe('mixed');
    });

    it('gras : <strong>, poids numérique et neutralisation par un style enfant', () => {
        expect(stateOf('<p><strong>x</strong></p>').bold).toBe('on');
        expect(stateOf('<p><span style="font-weight: 700">x</span></p>').bold).toBe('on');
        expect(stateOf('<p><span style="font-weight: 400">x</span></p>').bold).toBe('off');
        expect(stateOf('<p><b><span style="font-weight: normal">x</span></b></p>').bold).toBe('off');
    });

    it('un style hérité d\'un parent compte pour le texte enfant', () => {
        expect(stateOf('<div style="font-style: italic"><p><span>x</span></p></div>').italic).toBe('on');
        expect(stateOf('<p><u><span style="color: red">x</span></u></p>').underline).toBe('on');
    });

    it('sélection du texte mis en forme vers du texte non mis en forme => mixte', () => {
        const state = stateOf('<p><b>Gras</b> normal</p>', () => between(root.querySelector('b').firstChild, 2, root.querySelector('p').lastChild, 4));

        expect(state.bold).toBe('mixed');
    });

    it('une sélection qui ne fait que toucher le nœud voisin ne le compte pas', () => {
        const state = stateOf('<p><b>Gras</b> normal</p>', () => between(root.querySelector('b').firstChild, 0, root.querySelector('b').firstChild, 4));

        expect(state.bold).toBe('on');
    });
});

describe('alignement', () => {
    it('même alignement sur plusieurs paragraphes', () => {
        const state = stateOf('<p style="text-align: center">A</p><p style="text-align: center">B</p>');

        expect(state.align).toEqual({ left: 'off', center: 'on', right: 'off', justify: 'off' });
    });

    it('alignements différents => mixte, jamais deux « actifs »', () => {
        const state = stateOf('<p style="text-align: center">A</p><p style="text-align: right">B</p><p>C</p>');

        expect(state.align).toEqual({ left: 'mixed', center: 'mixed', right: 'mixed', justify: 'off' });
        expect(Object.values(state.align).filter((v) => v === 'on')).toHaveLength(0);
    });

    it('sans style explicite : à gauche', () => {
        expect(stateOf('<p>A</p>').align.left).toBe('on');
    });

    it('justifié et alignement hérité du bloc parent', () => {
        expect(stateOf('<p style="text-align: justify"><b>A</b></p>').align.justify).toBe('on');
        expect(stateOf('<div style="text-align: right"><p>A</p></div>').align.right).toBe('on');
    });
});

describe('taille', () => {
    it('taille uniforme', () => {
        expect(stateOf('<p><span style="font-size: 24px">A</span> <span style="font-size: 24px">B</span></p>').size)
            .toEqual({ kind: 'value', value: 24 });
    });

    it('tailles mélangées', () => {
        expect(stateOf('<p><span style="font-size: 12px">A</span><span style="font-size: 24px">B</span></p>').size)
            .toEqual({ kind: 'mixed' });
    });

    it('taille par défaut + taille personnalisée => mixte', () => {
        expect(stateOf('<p>A<span style="font-size: 24px">B</span></p>').size).toEqual({ kind: 'mixed' });
    });

    it('aucune taille explicite => défaut ; pt converti en px', () => {
        expect(stateOf('<p>A</p>').size).toEqual({ kind: 'default' });
        expect(stateOf('<p><span style="font-size: 18pt">A</span></p>').size).toEqual({ kind: 'value', value: 24 });
    });
});

describe.each([
    ['color', 'color', 'color'],
    ['highlight', 'background-color', 'background-color'],
])('%s', (key, property) => {
    it('couleur uniforme (rgb, hex 3/6 chiffres normalisés)', () => {
        const html = `<p><span style="${property}: rgb(185, 28, 28)">A</span><span style="${property}: #b91c1c">B</span></p>`;

        expect(stateOf(html)[key]).toEqual({ kind: 'value', value: '#b91c1c' });
        expect(stateOf(`<p><span style="${property}: #f00">A</span></p>`)[key]).toEqual({ kind: 'value', value: '#ff0000' });
    });

    it('plusieurs couleurs => mixte', () => {
        expect(stateOf(`<p><span style="${property}: #ff0000">A</span><span style="${property}: #00ff00">B</span></p>`)[key])
            .toEqual({ kind: 'mixed' });
    });

    it('partiellement sans couleur explicite => mixte (pas la couleur du premier caractère)', () => {
        expect(stateOf(`<p><span style="${property}: #ff0000">A</span>B</p>`)[key]).toEqual({ kind: 'mixed' });
        expect(stateOf(`<p>A<span style="${property}: #ff0000">B</span></p>`)[key]).toEqual({ kind: 'mixed' });
    });

    it('aucune couleur explicite => valeur distincte « default »', () => {
        expect(stateOf('<p>A</p>')[key]).toEqual({ kind: 'default' });
        expect(stateOf(`<p><span style="${property}: transparent">A</span></p>`)[key]).toEqual({ kind: 'default' });
    });
});

describe('curseur sans sélection', () => {
    const html = '<p><b>gras</b></p><p style="text-align: center"><i><span style="color: #ff0000; font-size: 24px; background-color: #ffff00">mix</span></i></p><p><u>sou</u></p>';

    it('reflète les styles au point d\'insertion', () => {
        mount(html);

        const bold = computeFormatState(root, caretIn('b'));
        expect(bold.bold).toBe('on');
        expect(bold.italic).toBe('off');
        expect(bold.align.left).toBe('on');

        const rich = computeFormatState(root, caretIn('span'));
        expect(rich.italic).toBe('on');
        expect(rich.bold).toBe('off');
        expect(rich.align.center).toBe('on');
        expect(rich.size).toEqual({ kind: 'value', value: 24 });
        expect(rich.color).toEqual({ kind: 'value', value: '#ff0000' });
        expect(rich.highlight).toEqual({ kind: 'value', value: '#ffff00' });

        expect(computeFormatState(root, caretIn('u')).underline).toBe('on');
    });

    it('suit le déplacement du curseur (clavier ou souris)', () => {
        mount(html);
        const text = root.querySelector('b').firstChild;
        const results = [1, 2, 3, 4].map((offset) => {
            const range = document.createRange();
            range.setStart(text, offset);
            range.collapse(true);

            return computeFormatState(root, range).bold;
        });

        expect(results).toEqual(['on', 'on', 'on', 'on']);
        expect(computeFormatState(root, caretIn('u')).bold).toBe('off');
    });

    it('caret dans un paragraphe vide', () => {
        mount('<p style="text-align: right"><br></p>');
        const range = document.createRange();
        range.setStart(root.querySelector('p'), 0);
        range.collapse(true);

        const state = computeFormatState(root, range);
        expect(state.align.right).toBe('on');
        expect(state.bold).toBe('off');
    });
});

describe('effets de bord', () => {
    it('ne modifie ni le contenu ni la sélection', () => {
        mount('<p><b>Gras</b> et <i>italique</i></p>');
        const before = root.innerHTML;
        const range = selectAll();
        const snapshot = [range.startContainer, range.startOffset, range.endContainer, range.endOffset];

        computeFormatState(root, range);

        expect(root.innerHTML).toBe(before);
        expect([range.startContainer, range.startOffset, range.endContainer, range.endOffset]).toEqual(snapshot);
    });
});

describe('normalizeColor', () => {
    it.each([
        ['rgb(255, 0, 0)', '#ff0000'],
        ['rgba(0, 0, 255, 1)', '#0000ff'],
        ['rgba(0, 0, 255, 0)', null],
        ['#ABC', '#aabbcc'],
        ['#B91C1C', '#b91c1c'],
        ['transparent', null],
        ['', null],
    ])('%s -> %s', (input, expected) => {
        expect(normalizeColor(input)).toBe(expected);
    });
});
