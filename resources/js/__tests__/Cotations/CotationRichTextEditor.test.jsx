import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

import RichTextEditor from '@/Components/Cotations/CotationRichTextEditor';

/**
 * Éditeur partagé entre la modification des cotations et la modale d'envoi :
 * chaque commande de la barre d'outils doit produire la bonne commande de
 * mise en forme et remonter le HTML obtenu.
 */
describe('CotationRichTextEditor', () => {
    let execCommand;

    beforeEach(() => {
        execCommand = vi.fn(() => true);
        document.execCommand = execCommand;
    });

    afterEach(() => {
        cleanup();
        delete document.execCommand;
    });

    it('affiche le contenu initial avec sa mise en forme', () => {
        const { container } = render(
            <RichTextEditor value={'<p style="text-align: center; font-size: 24px"><em>Titre</em></p>'} onChange={() => {}} />,
        );

        const editor = container.querySelector('[contenteditable="true"]');
        expect(editor.querySelector('em')).toHaveTextContent('Titre');
        expect(editor.querySelector('p')).toHaveStyle({ textAlign: 'center', fontSize: '24px' });
    });

    it.each([
        ['Gras', 'bold'],
        ['Italique', 'italic'],
        ['Souligné', 'underline'],
        ['Barré', 'strikeThrough'],
        ['Aligner à gauche', 'justifyLeft'],
        ['Centrer', 'justifyCenter'],
        ['Aligner à droite', 'justifyRight'],
        ['Justifier', 'justifyFull'],
    ])('le bouton « %s » applique %s', (label, command) => {
        const onChange = vi.fn();
        render(<RichTextEditor value="<p>x</p>" onChange={onChange} />);

        fireEvent.click(screen.getByLabelText(label));

        expect(execCommand).toHaveBeenCalledWith(command, false, null);
        expect(onChange).toHaveBeenCalled();
    });

    it('applique la couleur du texte et le surlignage', () => {
        render(<RichTextEditor value="<p>x</p>" onChange={() => {}} />);

        fireEvent.change(screen.getByLabelText('Couleur du texte'), { target: { value: '#ff0000' } });
        fireEvent.change(screen.getByLabelText('Couleur de surlignage'), { target: { value: '#ffff00' } });

        expect(execCommand).toHaveBeenCalledWith('foreColor', false, '#ff0000');
        expect(execCommand).toHaveBeenCalledWith('hiliteColor', false, '#ffff00');
    });

    it('applique la taille du texte choisie en pixels', () => {
        const onChange = vi.fn();
        const { container } = render(<RichTextEditor value="<p>x</p>" onChange={onChange} />);
        execCommand.mockImplementation((command) => {
            if (command === 'fontSize') {
                container.querySelector('[contenteditable="true"]').innerHTML = '<font size="7">gros</font>';
            }
            return true;
        });

        fireEvent.change(screen.getByLabelText('Taille du texte'), { target: { value: '24' } });

        expect(container.querySelector('[contenteditable="true"] span')).toHaveStyle({ fontSize: '24px' });
        expect(onChange).toHaveBeenLastCalledWith('<span style="font-size: 24px;">gros</span>');
    });
});
