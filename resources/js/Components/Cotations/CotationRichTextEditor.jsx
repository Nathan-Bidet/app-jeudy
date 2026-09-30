import { EMPTY_FORMAT_STATE, computeFormatState } from '@/Support/richTextState';
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
import { useEffect, useRef, useState } from 'react';

const RICH_TEXT_FONT_SIZES = [
    { label: 'Petit', px: 12 },
    { label: 'Normal', px: 14 },
    { label: 'Moyen', px: 18 },
    { label: 'Grand', px: 24 },
    { label: 'Très grand', px: 32 },
];

const STATE_LABELS = { on: 'actif', off: 'inactif', mixed: 'partiel' };

/**
 * Bouton de barre d'outils à trois états : 'on' (inversé), 'off', 'mixed'
 * (bordure en pointillés + trait sous l'icône). L'état n'est jamais porté par
 * la couleur seule, et les dimensions du bouton ne changent pas.
 */
function ToolbarButton({ icon: Icon, label, onClick, state = 'off' }) {
    const stateClass = state === 'on'
        ? 'border-[var(--app-text)] bg-[var(--app-text)] text-[var(--app-surface)]'
        : state === 'mixed'
            ? 'border-dashed border-[var(--app-text)] bg-[var(--app-surface-soft)] text-[var(--app-text)]'
            : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:bg-[var(--app-surface-soft)]';

    return (
        <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            aria-label={label}
            aria-pressed={state === 'mixed' ? 'mixed' : state === 'on'}
            data-state={state}
            title={`${label} (${STATE_LABELS[state]})`}
            className={`relative inline-flex h-9 w-9 items-center justify-center rounded-lg border ${stateClass}`}
        >
            <Icon className="h-4 w-4" strokeWidth={2.3} />
            {state === 'mixed' ? (
                <span aria-hidden="true" className="pointer-events-none absolute bottom-1 left-1/2 h-0.5 w-3 -translate-x-1/2 rounded bg-current" />
            ) : null}
        </button>
    );
}

/**
 * Sélecteur de couleur avec l'état de la sélection : couleur réelle si
 * uniforme, « ? » si plusieurs couleurs, « ∅ » si aucune couleur explicite.
 */
function ColorControl({ label, title, formatState, fallback, onSelect, saveSelection }) {
    const { kind, value } = formatState;
    const description = kind === 'mixed' ? 'plusieurs couleurs' : kind === 'default' ? 'aucune couleur explicite' : value;

    return (
        <label className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-xs font-semibold" title={`${title} : ${description}`}>
            {label}
            <span className="relative inline-flex h-6 w-6" data-color-state={kind}>
                <input
                    type="color"
                    value={kind === 'value' && /^#[0-9a-f]{6}$/.test(value) ? value : fallback}
                    onMouseDown={saveSelection}
                    onChange={(event) => onSelect(event.target.value)}
                    className="h-6 w-6 cursor-pointer border-0 bg-transparent p-0"
                    aria-label={`${title} : ${description}`}
                />
                {kind !== 'value' ? (
                    <span aria-hidden="true" className="pointer-events-none absolute inset-0 flex items-center justify-center rounded border border-dashed border-[var(--app-text)] bg-[var(--app-surface)]/70 text-xs font-black">
                        {kind === 'mixed' ? '?' : '∅'}
                    </span>
                ) : null}
            </span>
        </label>
    );
}

/**
 * Éditeur du message d'information des cotations (gras, italique, souligné,
 * barré, alignements, taille, couleur du texte et surlignage). Partagé entre
 * la modification des cotations et la modale d'envoi par e-mail : même barre
 * d'outils, même sortie HTML, assainie côté serveur par CerealInfoSanitizer.
 * Le contenu n'est lu qu'au montage : monter le composant avec `key` pour le
 * réinitialiser.
 *
 * La barre d'outils reflète en temps réel la mise en forme de la sélection
 * (voir Support/richTextState.js) : actif / inactif / partiel pour chaque
 * commande, sans jamais modifier le contenu pour le calculer.
 */
export default function RichTextEditor({ value, onChange }) {
    const editorRef = useRef(null);
    const selectionRangeRef = useRef(null);
    const initializedRef = useRef(false);
    const pendingRef = useRef(null);
    const pendingSizeRef = useRef(null);
    const [format, setFormat] = useState(EMPTY_FORMAT_STATE);

    useEffect(() => {
        if (!initializedRef.current && editorRef.current) {
            editorRef.current.innerHTML = value || '';
            initializedRef.current = true;
        }
    }, [value]);

    // Recalcule l'état depuis la sélection courante, lue au moment de l'appel
    // (jamais d'état périmé). Ignore les sélections hors de l'éditeur.
    const refreshFormat = () => {
        const editor = editorRef.current;
        const selection = window.getSelection?.();
        if (!editor || !selection || selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        if (!editor.contains(range.commonAncestorContainer)) return;

        const next = computeFormatState(editor, range);

        if (range.collapsed) {
            // Styles « en attente » au point d'insertion (avant la prochaine frappe).
            const pending = pendingRef.current;
            if (pending && pending.container === range.startContainer && pending.offset === range.startOffset) {
                Object.entries(pending.values).forEach(([key, val]) => { next[key] = val; });
            } else {
                pendingRef.current = null;
            }
            if (typeof document.queryCommandState === 'function') {
                try {
                    next.bold = document.queryCommandState('bold') ? 'on' : 'off';
                    next.italic = document.queryCommandState('italic') ? 'on' : 'off';
                    next.underline = document.queryCommandState('underline') ? 'on' : 'off';
                    next.strike = document.queryCommandState('strikeThrough') ? 'on' : 'off';
                } catch {
                    // queryCommandState indisponible : on garde l'état issu du DOM.
                }
            }
        } else {
            pendingRef.current = null;
        }

        setFormat((previous) => (JSON.stringify(previous) === JSON.stringify(next) ? previous : next));
    };

    useEffect(() => {
        document.addEventListener('selectionchange', refreshFormat);

        return () => document.removeEventListener('selectionchange', refreshFormat);
    }, []);

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
        normalizeFontTags();
        onChange(editorRef.current?.innerHTML || '');
        refreshFormat();
    };

    // execCommand('fontSize') sans sélection ne produit qu'une balise <font>
    // dès la frappe suivante : on la convertit en style de taille en pixels.
    const normalizeFontTags = () => {
        const size = pendingSizeRef.current;
        editorRef.current?.querySelectorAll('font[size="7"]').forEach((node) => {
            const span = document.createElement('span');
            if (size) span.style.fontSize = `${size}px`;
            span.innerHTML = node.innerHTML;
            node.replaceWith(span);
        });
    };
    const setPending = (values) => {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !selection.getRangeAt(0).collapsed) return;
        const range = selection.getRangeAt(0);
        pendingRef.current = {
            container: range.startContainer,
            offset: range.startOffset,
            values: { ...(pendingRef.current?.values || {}), ...values },
        };
    };

    const currentSelectionState = () => {
        const selection = window.getSelection();
        if (!editorRef.current || !selection || selection.rangeCount === 0) return null;

        return computeFormatState(editorRef.current, selection.getRangeAt(0));
    };

    // Bascule d'un style. Sur une sélection partielle (« mixed »), le premier
    // clic applique le style à toute la sélection, le suivant le retire :
    // si le navigateur a retiré le style au premier passage, on relance.
    const runToggle = (command, key) => {
        restoreSelection();
        const before = currentSelectionState()?.[key];
        document.execCommand(command, false, null);
        if (before === 'mixed' && currentSelectionState()?.[key] === 'off') {
            document.execCommand(command, false, null);
        }
        saveSelection();
        emitChange();
    };

    const runCommand = (command) => {
        restoreSelection();
        document.execCommand(command, false, null);
        saveSelection();
        emitChange();
    };

    const applyFontSize = (px) => {
        restoreSelection();
        pendingSizeRef.current = px;
        document.execCommand('fontSize', false, '7');
        editorRef.current?.querySelectorAll('font[size="7"]').forEach((node) => {
            const span = document.createElement('span');
            span.style.fontSize = `${px}px`;
            span.innerHTML = node.innerHTML;
            // Une taille explicite imbriquée ne doit pas survivre à la nouvelle valeur.
            span.querySelectorAll('[style*="font-size"]').forEach((inner) => {
                inner.style.removeProperty('font-size');
                if (!inner.getAttribute('style')) inner.removeAttribute('style');
            });
            node.replaceWith(span);
        });
        setPending({ size: { kind: 'value', value: px } });
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
        setPending({ [command === 'foreColor' ? 'color' : 'highlight']: { kind: 'value', value: color.toLowerCase() } });
        saveSelection();
        emitChange();
    };

    const sizeSelectValue = format.size.kind === 'mixed'
        ? 'mixed'
        : format.size.kind === 'default'
            ? 'default'
            : String(format.size.value);
    const customSize = format.size.kind === 'value' && !RICH_TEXT_FONT_SIZES.some((size) => size.px === format.size.value);

    return (
        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)]">
            <div role="toolbar" aria-label="Mise en forme du message" className="flex flex-wrap items-center gap-1.5 border-b border-[var(--app-border)] p-2">
                <ToolbarButton icon={Bold} label="Gras" state={format.bold} onClick={() => runToggle('bold', 'bold')} />
                <ToolbarButton icon={Italic} label="Italique" state={format.italic} onClick={() => runToggle('italic', 'italic')} />
                <ToolbarButton icon={Underline} label="Souligné" state={format.underline} onClick={() => runToggle('underline', 'underline')} />
                <ToolbarButton icon={Strikethrough} label="Barré" state={format.strike} onClick={() => runToggle('strikeThrough', 'strike')} />
                <span className="mx-1 h-6 w-px bg-[var(--app-border)]" />
                <ToolbarButton icon={AlignLeft} label="Aligner à gauche" state={format.align.left} onClick={() => runCommand('justifyLeft')} />
                <ToolbarButton icon={AlignCenter} label="Centrer" state={format.align.center} onClick={() => runCommand('justifyCenter')} />
                <ToolbarButton icon={AlignRight} label="Aligner à droite" state={format.align.right} onClick={() => runCommand('justifyRight')} />
                <ToolbarButton icon={AlignJustify} label="Justifier" state={format.align.justify} onClick={() => runCommand('justifyFull')} />
                <span className="mx-1 h-6 w-px bg-[var(--app-border)]" />
                <select
                    onMouseDown={saveSelection}
                    onChange={(event) => applyFontSize(Number(event.target.value))}
                    value={sizeSelectValue}
                    aria-label="Taille du texte"
                    data-size-state={format.size.kind}
                    className="h-9 w-32 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-sm font-semibold"
                >
                    <option value="default" disabled>Taille (défaut)</option>
                    <option value="mixed" disabled>Mixte</option>
                    {customSize ? <option value={String(format.size.value)} disabled>{format.size.value} px</option> : null}
                    {RICH_TEXT_FONT_SIZES.map((size) => (
                        <option key={size.px} value={size.px}>{size.label}</option>
                    ))}
                </select>
                <ColorControl
                    label="Texte"
                    title="Couleur du texte"
                    formatState={format.color}
                    fallback="#000000"
                    saveSelection={saveSelection}
                    onSelect={(color) => applyColor('foreColor', color)}
                />
                <ColorControl
                    label="Surlignage"
                    title="Couleur de surlignage"
                    formatState={format.highlight}
                    fallback="#ffff00"
                    saveSelection={saveSelection}
                    onSelect={(color) => applyColor('hiliteColor', color)}
                />
            </div>
            <div
                ref={editorRef}
                contentEditable
                suppressContentEditableWarning
                onInput={emitChange}
                onMouseUp={() => { saveSelection(); refreshFormat(); }}
                onKeyUp={() => { saveSelection(); refreshFormat(); }}
                onFocus={refreshFormat}
                onBlur={refreshFormat}
                className="min-h-[120px] w-full max-w-full min-w-0 p-3 text-sm leading-relaxed outline-none [overflow-wrap:anywhere]"
            />
        </div>
    );
}
