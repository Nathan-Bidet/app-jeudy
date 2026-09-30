import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

import RichTextEditor from '@/Components/Cotations/CotationRichTextEditor';

/**
 * Synchronisation de la barre d'outils avec la sélection (états actif /
 * inactif / partiel), sur le composant partagé par la modale d'envoi et la
 * modification des cotations.
 */
let editor;

const mount = (html, onChange = () => {}) => {
    const utils = render(<RichTextEditor value={html} onChange={onChange} />);
    editor = utils.container.querySelector('[contenteditable="true"]');

    return utils;
};

const select = (range) => {
    // Comme dans un navigateur, l'éditeur a le focus quand l'utilisateur y sélectionne
    // du texte (focus() le rejouerait sinon et déplacerait la sélection sous jsdom).
    if (document.activeElement !== editor) editor.focus();
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    act(() => { document.dispatchEvent(new Event('selectionchange')); });
};

const selectAll = () => {
    const range = document.createRange();
    range.selectNodeContents(editor);
    select(range);
};

const caret = (node, offset) => {
    const range = document.createRange();
    range.setStart(node, offset);
    range.collapse(true);
    select(range);
};

const button = (label) => screen.getByLabelText(label);
const pressed = (label) => button(label).getAttribute('aria-pressed');

beforeEach(() => {
    document.execCommand = vi.fn(() => true);
    document.queryCommandState = undefined;
});

afterEach(() => {
    cleanup();
    delete document.execCommand;
    window.getSelection().removeAllRanges();
});

describe('états de la barre d\'outils', () => {
    it.each([
        ['Gras', '<b>', '</b>'],
        ['Italique', '<i>', '</i>'],
        ['Souligné', '<u>', '</u>'],
        ['Barré', '<s>', '</s>'],
    ])('« %s » : actif, inactif puis partiel (aria-pressed true / false / mixed)', (label, open, close) => {
        mount(`<p>${open}tout${close}</p><p>rien</p><p>${open}une${close} partie</p>`);

        const [p1, p2, p3] = editor.querySelectorAll('p');
        const range = (from, to) => {
            const r = document.createRange();
            r.selectNodeContents(from);
            if (to) r.setEnd(to, to.childNodes.length);
            return r;
        };

        select(range(p1));
        expect(pressed(label)).toBe('true');
        expect(button(label)).toHaveAttribute('data-state', 'on');

        select(range(p2));
        expect(pressed(label)).toBe('false');
        expect(button(label)).toHaveAttribute('data-state', 'off');

        select(range(p3));
        expect(pressed(label)).toBe('mixed');
        expect(button(label)).toHaveAttribute('data-state', 'mixed');
        // Distinct sans la couleur : indicateur visuel dédié + libellé d'infobulle.
        expect(button(label).querySelector('span')).not.toBeNull();
        expect(button(label).title).toContain('partiel');
    });

    it('alignement : un seul actif, ou partiels sur plusieurs paragraphes', () => {
        mount('<p style="text-align: center">A</p><p style="text-align: center">B</p><p style="text-align: right">C</p>');
        const [a, b, c] = editor.querySelectorAll('p');

        const range = document.createRange();
        range.setStart(a.firstChild, 0);
        range.setEnd(b.firstChild, 1);
        select(range);
        expect(pressed('Centrer')).toBe('true');
        expect(pressed('Aligner à gauche')).toBe('false');
        expect(pressed('Aligner à droite')).toBe('false');

        range.setEnd(c.firstChild, 1);
        select(range);
        expect(pressed('Centrer')).toBe('mixed');
        expect(pressed('Aligner à droite')).toBe('mixed');
        expect(pressed('Aligner à gauche')).toBe('false');
        ['Aligner à gauche', 'Centrer', 'Aligner à droite', 'Justifier'].forEach((label) => {
            expect(pressed(label)).not.toBe('true');
        });
    });

    it('taille : valeur uniforme, « Mixte », défaut et taille hors liste', () => {
        mount('<p><span style="font-size: 24px">A</span></p><p><span style="font-size: 12px">B</span></p><p>C</p><p><span style="font-size: 20px">D</span></p>');
        const [a, b, c, d] = editor.querySelectorAll('p');
        const size = () => screen.getByLabelText('Taille du texte');
        const over = (...paragraphs) => {
            const r = document.createRange();
            r.setStartBefore(paragraphs[0]);
            r.setEndAfter(paragraphs[paragraphs.length - 1]);
            select(r);
        };

        over(a);
        expect(size()).toHaveValue('24');
        over(a, b);
        expect(size()).toHaveValue('mixed');
        expect(size().selectedOptions[0]).toHaveTextContent('Mixte');
        over(c);
        expect(size()).toHaveValue('default');
        over(b, c);
        expect(size()).toHaveValue('mixed');
        over(d);
        expect(size()).toHaveValue('20');
        expect(size().selectedOptions[0]).toHaveTextContent('20 px');
    });

    it('couleurs : valeur réelle, « mixte » et « aucune »', () => {
        mount('<p><span style="color: #ff0000; background-color: #ffff00">A</span></p><p><span style="color: #00ff00">B</span></p><p>C</p>');
        const [a, b, c] = editor.querySelectorAll('p');
        const textInput = () => screen.getByLabelText(/^Couleur du texte/);
        const stateOf = (input) => input.parentElement.getAttribute('data-color-state');
        const over = (from, to) => {
            const r = document.createRange();
            r.setStartBefore(from);
            r.setEndAfter(to);
            select(r);
        };

        over(a, a);
        expect(textInput()).toHaveValue('#ff0000');
        expect(stateOf(textInput())).toBe('value');
        expect(screen.getByLabelText(/^Couleur de surlignage/)).toHaveValue('#ffff00');

        over(a, b);
        expect(stateOf(textInput())).toBe('mixed');
        expect(textInput().getAttribute('aria-label')).toContain('plusieurs couleurs');
        expect(textInput().parentElement).toHaveTextContent('?');

        over(c, c);
        expect(stateOf(textInput())).toBe('default');
        expect(textInput().parentElement).toHaveTextContent('∅');

        over(a, c);
        expect(stateOf(screen.getByLabelText(/^Couleur de surlignage/))).toBe('mixed');
    });

    it('curseur : styles au point d\'insertion, au clavier comme à la souris', () => {
        mount('<p><b>gras</b> <i style="color: #123456">ital</i></p>');
        const bold = editor.querySelector('b').firstChild;
        const italic = editor.querySelector('i').firstChild;

        caret(bold, 2);
        expect(pressed('Gras')).toBe('true');
        expect(pressed('Italique')).toBe('false');

        // Déplacement au clavier : flèche puis keyup.
        caret(italic, 1);
        fireEvent.keyUp(editor, { key: 'ArrowRight' });
        expect(pressed('Gras')).toBe('false');
        expect(pressed('Italique')).toBe('true');
        expect(screen.getByLabelText(/^Couleur du texte/)).toHaveValue('#123456');

        // Clic souris dans une autre zone.
        caret(bold, 0);
        fireEvent.mouseUp(editor);
        expect(pressed('Gras')).toBe('true');
    });

    it('étend et réduit une sélection (Majuscule + flèches)', () => {
        mount('<p><b>gras</b> normal</p>');
        const bold = editor.querySelector('b').firstChild;
        const tail = editor.querySelector('p').lastChild;

        const range = document.createRange();
        range.setStart(bold, 0);
        range.setEnd(bold, 4);
        select(range);
        expect(pressed('Gras')).toBe('true');

        range.setEnd(tail, 3);
        select(range);
        fireEvent.keyUp(editor, { key: 'ArrowRight', shiftKey: true });
        expect(pressed('Gras')).toBe('mixed');

        range.setEnd(bold, 2);
        select(range);
        expect(pressed('Gras')).toBe('true');
    });

    it('ignore les sélections situées hors de l\'éditeur', () => {
        const { container } = mount('<p><b>gras</b></p>');
        const outside = document.createElement('p');
        outside.textContent = 'dehors';
        container.appendChild(outside);

        select((() => { const r = document.createRange(); r.selectNodeContents(editor); return r; })());
        expect(pressed('Gras')).toBe('true');

        const r = document.createRange();
        r.selectNodeContents(outside);
        select(r);
        expect(pressed('Gras')).toBe('true');
    });

    it('se met à jour après saisie, suppression, collage, annulation et rétablissement', () => {
        mount('<p><b>gras</b></p>');
        const p = editor.querySelector('p');
        const stateAfter = (mutate) => {
            mutate();
            const r = document.createRange();
            r.selectNodeContents(editor);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(r);
            fireEvent.input(editor);
        };

        selectAll();
        expect(pressed('Gras')).toBe('true');

        // Saisie d'un texte non gras.
        stateAfter(() => p.appendChild(document.createTextNode(' plus')));
        expect(pressed('Gras')).toBe('mixed');

        // Suppression de la partie grasse.
        stateAfter(() => editor.querySelector('b').remove());
        expect(pressed('Gras')).toBe('false');

        // Collage d'un contenu en italique.
        stateAfter(() => p.insertAdjacentHTML('beforeend', '<i>collé</i>'));
        expect(pressed('Italique')).toBe('mixed');

        // Annulation puis rétablissement.
        stateAfter(() => editor.querySelector('i').remove());
        expect(pressed('Italique')).toBe('false');
        stateAfter(() => p.insertAdjacentHTML('beforeend', '<i>collé</i>'));
        expect(pressed('Italique')).toBe('mixed');
    });

    it('prend en compte le focus et la perte de focus sans erreur', () => {
        mount('<p><b>gras</b></p>');
        selectAll();

        fireEvent.blur(editor);
        expect(pressed('Gras')).toBe('true');
        fireEvent.focus(editor);
        expect(pressed('Gras')).toBe('true');
    });

    it('après activation d\'un style sans sélection, indique le style des prochains caractères', () => {
        mount('<p>texte</p>');
        caret(editor.querySelector('p').firstChild, 5);

        // Couleur : style « en attente » tant que le curseur ne bouge pas.
        fireEvent.change(screen.getByLabelText(/^Couleur du texte/), { target: { value: '#00ff00' } });
        expect(screen.getByLabelText(/^Couleur du texte/)).toHaveValue('#00ff00');
        expect(screen.getByLabelText(/^Couleur du texte/).parentElement).toHaveAttribute('data-color-state', 'value');

        // Gras : l'état vient de queryCommandState (style en attente géré par le navigateur).
        document.queryCommandState = vi.fn((command) => command === 'bold');
        fireEvent.keyUp(editor);
        expect(pressed('Gras')).toBe('true');
        document.queryCommandState = vi.fn(() => false);
        fireEvent.keyUp(editor);
        expect(pressed('Gras')).toBe('false');

        // Le curseur bouge : le style en attente est abandonné.
        caret(editor.querySelector('p').firstChild, 1);
        expect(screen.getByLabelText(/^Couleur du texte/).parentElement).toHaveAttribute('data-color-state', 'default');
    });
});

describe('application d\'une commande sur une sélection mixte', () => {
    const html = '<p><span id="a" style="font-weight: bold">Un</span><span id="b"> deux</span></p>';

    const setBold = (value) => {
        editor.querySelector('#a').style.fontWeight = value ? 'bold' : 'normal';
        editor.querySelector('#b').style.fontWeight = value ? 'bold' : 'normal';
    };

    it('un clic sur Gras applique le style à toute la sélection, un second le retire', () => {
        mount(html);
        selectAll();
        expect(pressed('Gras')).toBe('mixed');

        // Navigateur qui applique à tout au premier passage (Chrome).
        document.execCommand = vi.fn((command) => {
            if (command === 'bold') setBold(true);
            return true;
        });
        fireEvent.click(button('Gras'));
        expect(document.execCommand).toHaveBeenCalledTimes(1);
        expect(pressed('Gras')).toBe('true');

        document.execCommand = vi.fn((command) => { if (command === 'bold') setBold(false); return true; });
        fireEvent.click(button('Gras'));
        expect(pressed('Gras')).toBe('false');
    });

    it('si le navigateur retire le style au premier passage, la commande est relancée', () => {
        mount(html);
        selectAll();

        const calls = [];
        document.execCommand = vi.fn((command) => {
            if (command !== 'bold') return true;
            calls.push(command);
            setBold(calls.length % 2 === 0);
            return true;
        });

        fireEvent.click(button('Gras'));

        expect(calls).toHaveLength(2);
        expect(pressed('Gras')).toBe('true');
    });

    it('un style non mixte se bascule en un seul appel', () => {
        mount('<p><b>Un</b></p>');
        selectAll();

        document.execCommand = vi.fn(() => true);
        fireEvent.click(button('Gras'));

        expect(document.execCommand).toHaveBeenCalledTimes(1);
    });

    it('alignement, taille et couleur s\'appliquent uniformément et l\'état mixte disparaît', () => {
        mount('<p style="text-align: center">A</p><p style="text-align: right">B</p>');
        selectAll();
        expect(pressed('Centrer')).toBe('mixed');

        document.execCommand = vi.fn((command) => {
            if (command === 'justifyCenter') {
                editor.querySelectorAll('p').forEach((p) => { p.style.textAlign = 'center'; });
            }
            return true;
        });
        fireEvent.click(button('Centrer'));

        expect(pressed('Centrer')).toBe('true');
        expect(pressed('Aligner à droite')).toBe('false');
    });
});

describe('sélection et contenu', () => {
    it('le clic dans la barre d\'outils ne fait pas perdre la sélection (mousedown neutralisé)', () => {
        mount('<p><b>gras</b> normal</p>');
        selectAll();
        const before = window.getSelection().getRangeAt(0).cloneRange();

        const notPrevented = fireEvent.mouseDown(button('Italique'));
        fireEvent.click(button('Italique'));

        expect(notPrevented).toBe(false);
        const after = window.getSelection().getRangeAt(0);
        expect(after.startContainer).toBe(before.startContainer);
        expect(after.endContainer).toBe(before.endContainer);
    });

    it('calculer l\'état ne modifie ni le HTML ni ne déclenche onChange', () => {
        const onChange = vi.fn();
        mount('<p><b>gras</b> et <i style="color: #ff0000">italique</i></p>', onChange);
        const html = editor.innerHTML;

        selectAll();
        caret(editor.querySelector('b').firstChild, 1);
        fireEvent.focus(editor);
        fireEvent.blur(editor);

        expect(editor.innerHTML).toBe(html);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('n\'intercepte pas Entrée dans l\'éditeur', () => {
        mount('<p>texte</p>');

        expect(fireEvent.keyDown(editor, { key: 'Enter' })).toBe(true);
    });
});
