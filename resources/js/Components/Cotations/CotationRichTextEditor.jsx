import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Bold,
    Italic,
    Strikethrough,
    Underline,
} from 'lucide-react';
import { useEffect, useRef } from 'react';

const RICH_TEXT_FONT_SIZES = [
    { label: 'Petit', px: 12 },
    { label: 'Normal', px: 14 },
    { label: 'Moyen', px: 18 },
    { label: 'Grand', px: 24 },
    { label: 'Très grand', px: 32 },
];

function ToolbarButton({ icon: Icon, label, onClick }) {
    return (
        <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            aria-label={label}
            title={label}
            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:bg-[var(--app-surface-soft)]"
        >
            <Icon className="h-4 w-4" strokeWidth={2.3} />
        </button>
    );
}

/**
 * Éditeur du message d'information des cotations (gras, italique, souligné,
 * barré, alignements, taille, couleur du texte et surlignage). Partagé entre
 * la modification des cotations et la modale d'envoi par e-mail : même barre
 * d'outils, même sortie HTML, assainie côté serveur par CerealInfoSanitizer.
 * Le contenu n'est lu qu'au montage : monter le composant avec `key` pour le
 * réinitialiser.
 */
export default function RichTextEditor({ value, onChange }) {
    const editorRef = useRef(null);
    const selectionRangeRef = useRef(null);
    const initializedRef = useRef(false);

    useEffect(() => {
        if (!initializedRef.current && editorRef.current) {
            editorRef.current.innerHTML = value || '';
            initializedRef.current = true;
        }
    }, [value]);

    const saveSelection = () => {
        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0 && editorRef.current?.contains(selection.anchorNode)) {
            selectionRangeRef.current = selection.getRangeAt(0).cloneRange();
        }
    };

    const restoreSelection = () => {
        editorRef.current?.focus();
        const selection = window.getSelection();
        if (!selection || !selectionRangeRef.current) return;
        selection.removeAllRanges();
        selection.addRange(selectionRangeRef.current);
    };

    const emitChange = () => {
        onChange(editorRef.current?.innerHTML || '');
    };

    const runCommand = (command, arg = null) => {
        restoreSelection();
        document.execCommand(command, false, arg);
        saveSelection();
        emitChange();
    };

    const applyFontSize = (px) => {
        restoreSelection();
        document.execCommand('fontSize', false, '7');
        editorRef.current?.querySelectorAll('font[size="7"]').forEach((node) => {
            const span = document.createElement('span');
            span.style.fontSize = `${px}px`;
            span.innerHTML = node.innerHTML;
            node.replaceWith(span);
        });
        saveSelection();
        emitChange();
    };

    const applyColor = (command, color) => {
        restoreSelection();
        document.execCommand('styleWithCSS', false, true);
        const applied = document.execCommand(command, false, color);
        if (!applied && command === 'hiliteColor') {
            document.execCommand('backColor', false, color);
        }
        saveSelection();
        emitChange();
    };

    return (
        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)]">
            <div className="flex flex-wrap items-center gap-1.5 border-b border-[var(--app-border)] p-2">
                <ToolbarButton icon={Bold} label="Gras" onClick={() => runCommand('bold')} />
                <ToolbarButton icon={Italic} label="Italique" onClick={() => runCommand('italic')} />
                <ToolbarButton icon={Underline} label="Souligné" onClick={() => runCommand('underline')} />
                <ToolbarButton icon={Strikethrough} label="Barré" onClick={() => runCommand('strikeThrough')} />
                <span className="mx-1 h-6 w-px bg-[var(--app-border)]" />
                <ToolbarButton icon={AlignLeft} label="Aligner à gauche" onClick={() => runCommand('justifyLeft')} />
                <ToolbarButton icon={AlignCenter} label="Centrer" onClick={() => runCommand('justifyCenter')} />
                <ToolbarButton icon={AlignRight} label="Aligner à droite" onClick={() => runCommand('justifyRight')} />
                <ToolbarButton icon={AlignJustify} label="Justifier" onClick={() => runCommand('justifyFull')} />
                <span className="mx-1 h-6 w-px bg-[var(--app-border)]" />
                <select
                    onMouseDown={saveSelection}
                    onChange={(event) => applyFontSize(Number(event.target.value))}
                    defaultValue=""
                    aria-label="Taille du texte"
                    className="h-9 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-sm font-semibold"
                >
                    <option value="" disabled>Taille</option>
                    {RICH_TEXT_FONT_SIZES.map((size) => (
                        <option key={size.px} value={size.px}>{size.label}</option>
                    ))}
                </select>
                <label className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-xs font-semibold" title="Couleur du texte">
                    Texte
                    <input
                        type="color"
                        onMouseDown={saveSelection}
                        onChange={(event) => applyColor('foreColor', event.target.value)}
                        className="h-6 w-6 cursor-pointer border-0 bg-transparent p-0"
                        aria-label="Couleur du texte"
                    />
                </label>
                <label className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-xs font-semibold" title="Couleur de surlignage">
                    Surlignage
                    <input
                        type="color"
                        onMouseDown={saveSelection}
                        onChange={(event) => applyColor('hiliteColor', event.target.value)}
                        className="h-6 w-6 cursor-pointer border-0 bg-transparent p-0"
                        aria-label="Couleur de surlignage"
                    />
                </label>
            </div>
            <div
                ref={editorRef}
                contentEditable
                suppressContentEditableWarning
                onInput={emitChange}
                onMouseUp={saveSelection}
                onKeyUp={saveSelection}
                className="min-h-[120px] w-full max-w-full min-w-0 p-3 text-sm leading-relaxed outline-none [overflow-wrap:anywhere]"
            />
        </div>
    );
}
