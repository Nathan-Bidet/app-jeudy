import { Bold, Italic, Strikethrough, Underline } from 'lucide-react';
import { useEffect, useRef } from 'react';

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
 * Éditeur de texte riche léger : gras, italique, souligné, barré, retours à
 * la ligne. Pas d'alignement, de couleur ni de taille de police (cf.
 * SimpleHtmlSanitizer côté serveur, qui n'autorise aucun style/attribut).
 */
export default function RichTextEditor({ value, onChange, placeholder = '', minHeightClassName = 'min-h-[140px]' }) {
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

    const runCommand = (command) => {
        restoreSelection();
        document.execCommand(command, false, null);
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
            </div>
            <div
                ref={editorRef}
                contentEditable
                suppressContentEditableWarning
                onInput={emitChange}
                onMouseUp={saveSelection}
                onKeyUp={saveSelection}
                data-placeholder={placeholder}
                className={`${minHeightClassName} w-full max-w-full min-w-0 p-3 text-sm leading-relaxed outline-none [overflow-wrap:anywhere] empty:before:text-[var(--app-muted)] empty:before:content-[attr(data-placeholder)]`}
            />
        </div>
    );
}
