import InputError from '@/Components/InputError';
import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * Zone de texte multi-lignes avec suggestions de lieux (une par ligne).
 * Extrait de Components/Aprevoir/TaskModal pour être partagé entre les modules
 * de tâches (À Prévoir, Engrais, Maintenance). Comportement inchangé.
 */
export default function PlaceAutocompleteTextarea({
    label,
    value,
    onChange,
    error,
    placeholder,
    suggestions = [],
    defaultSuggestions = [],
}) {
    const rootRef = useRef(null);
    const textareaRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [preferDefaultSuggestions, setPreferDefaultSuggestions] = useState(true);

    const normalizedSuggestions = useMemo(
        () =>
            Array.from(
                new Set(
                    (suggestions || [])
                        .map((item) => String(item || '').trim())
                        .filter(Boolean),
                ),
            ),
        [suggestions],
    );

    const normalizedDefaultSuggestions = useMemo(
        () =>
            Array.from(
                new Set(
                    (defaultSuggestions || [])
                        .map((item) => String(item || '').trim())
                        .filter(Boolean),
                ),
            ),
        [defaultSuggestions],
    );

    const activeLineQuery = useMemo(() => {
        const lines = String(value || '').split(/\r\n|\r|\n/);
        return String(lines[lines.length - 1] || '').trim();
    }, [value]);

    const matchingSuggestions = useMemo(() => {
        if (preferDefaultSuggestions) {
            return normalizedDefaultSuggestions.length ? normalizedDefaultSuggestions : normalizedSuggestions;
        }

        const query = activeLineQuery.toLocaleLowerCase('fr');
        if (!query) {
            return normalizedDefaultSuggestions.length ? normalizedDefaultSuggestions : normalizedSuggestions;
        }

        if (query.length < 1) return [];

        return Array.from(new Set([...normalizedDefaultSuggestions, ...normalizedSuggestions]))
            .filter((item) => String(item).toLocaleLowerCase('fr').startsWith(query))
            .slice(0, 100);
    }, [
        normalizedSuggestions,
        normalizedDefaultSuggestions,
        activeLineQuery,
        preferDefaultSuggestions,
    ]);

    useEffect(() => {
        const onPointerDown = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, []);

    useEffect(() => {
        const node = textareaRef.current;
        if (!node) return;

        node.style.height = 'auto';
        node.style.height = `${node.scrollHeight}px`;
    }, [value]);

    const applySuggestion = (selected) => {
        const rows = String(value || '').split(/\r\n|\r|\n/);
        rows[rows.length - 1] = selected;
        onChange(rows.join('\n'));
        setPreferDefaultSuggestions(true);
        setOpen(false);
    };

    return (
        <div ref={rootRef}>
            <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                {label}
            </label>
            <div className="relative mt-1">
                <textarea
                    ref={textareaRef}
                    value={value || ''}
                    onChange={(e) => {
                        const nextValue = e.target.value;
                        onChange(nextValue);
                        setPreferDefaultSuggestions(false);
                        setOpen(true);
                    }}
                    onFocus={() => {
                        setPreferDefaultSuggestions(true);
                        setOpen(true);
                    }}
                    onClick={() => {
                        setPreferDefaultSuggestions(true);
                        setOpen(true);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setPreferDefaultSuggestions(true);
                            setOpen(false);
                        }
                    }}
                    rows={1}
                    className="w-full resize-none overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                    placeholder={placeholder}
                />

                {open && matchingSuggestions.length > 0 ? (
                    <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-lg">
                        <div className="max-h-52 overflow-y-auto py-1">
                            {matchingSuggestions.map((item) => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => applySuggestion(item)}
                                    className="block w-full px-3 py-2 text-left text-sm hover:bg-[var(--app-surface-soft)]"
                                >
                                    {item}
                                </button>
                            ))}
                        </div>
                    </div>
                ) : null}
            </div>
            <InputError className="mt-1" message={error} />
        </div>
    );
}
