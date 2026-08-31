import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * Liste déroulante filtrable, avec option de saisie libre.
 * Extrait de Components/Aprevoir/TaskModal pour être partagé entre les modules
 * de tâches (À Prévoir, Engrais, Maintenance). Comportement inchangé.
 */
export default function SearchableSelect({
    options,
    value,
    onChange,
    placeholder = 'Sélectionner',
    emptyLabel = 'Aucun',
    disabled = false,
    allowFree = false,
    freeLabel = '',
    onFreeSelect,
}) {
    const rootRef = useRef(null);
    const inputRef = useRef(null);
    const selectingRef = useRef(false);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(-1);

    const selectedOption = useMemo(
        () => options.find((item) => String(item.value) === String(value || '')) || null,
        [options, value],
    );

    useEffect(() => {
        if (!open) {
            setQuery(selectedOption?.label || freeLabel || '');
            setActiveIndex(-1);
        }
    }, [open, selectedOption, freeLabel]);

    useEffect(() => {
        const onPointerDown = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                if (allowFree) {
                    const trimmed = query.trim();
                    const hasSelection = Boolean(value);
                    const selectedLabel = selectedOption?.label || '';
                    if (trimmed !== '' && (!hasSelection || trimmed !== selectedLabel)) {
                        onFreeSelect?.(trimmed);
                    } else if (trimmed === '') {
                        onChange('');
                    }
                }
                setOpen(false);
                setActiveIndex(-1);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [allowFree, onChange, onFreeSelect, query, selectedOption, value]);

    const filteredOptions = useMemo(() => {
        const needle = (query || '').trim().toLowerCase();
        if (!needle) return options;
        return options.filter((item) => item.label.toLowerCase().includes(needle));
    }, [options, query]);

    const handleSelect = (nextValue) => {
        selectingRef.current = true;
        onChange(nextValue);
        if (!nextValue) {
            setQuery('');
        }
        setOpen(false);
        setActiveIndex(-1);
        // Allow blur to happen without triggering free-text overwrite.
        setTimeout(() => {
            selectingRef.current = false;
        }, 0);
    };

    return (
        <div ref={rootRef} className="relative">
            <input
                ref={inputRef}
                type="text"
                value={open ? query : (selectedOption?.label || freeLabel || '')}
                onChange={(e) => {
                    const nextQuery = e.target.value;
                    setQuery(nextQuery);
                    if (allowFree && nextQuery.trim() === '') {
                        onChange('');
                    }
                    if (!open) setOpen(true);
                    setActiveIndex(-1);
                }}
                onFocus={() => {
                    if (!disabled) {
                        setOpen(true);
                        setQuery(selectedOption?.label || freeLabel || '');
                        setActiveIndex(-1);
                    }
                }}
                onBlur={() => {
                    requestAnimationFrame(() => {
                        if (selectingRef.current) {
                            setOpen(false);
                            setActiveIndex(-1);
                            return;
                        }
                        if (rootRef.current?.contains(document.activeElement)) {
                            return;
                        }
                        if (allowFree) {
                            const trimmed = query.trim();
                            const hasSelection = Boolean(value);
                            const selectedLabel = selectedOption?.label || '';
                            if (trimmed !== '' && (!hasSelection || trimmed !== selectedLabel)) {
                                onFreeSelect?.(trimmed);
                            } else if (trimmed === '') {
                                onChange('');
                            }
                        }
                        setOpen(false);
                        setActiveIndex(-1);
                    });
                }}
                onClick={() => !disabled && setOpen(true)}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        setOpen(false);
                        setActiveIndex(-1);
                        inputRef.current?.blur();
                        return;
                    }
                    if (!open) {
                        return;
                    }
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setActiveIndex((prev) => {
                            const next = prev + 1;
                            return next >= filteredOptions.length ? 0 : next;
                        });
                        return;
                    }
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setActiveIndex((prev) => {
                            if (filteredOptions.length === 0) return -1;
                            const next = prev - 1;
                            return next < 0 ? filteredOptions.length - 1 : next;
                        });
                        return;
                    }
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (activeIndex >= 0 && filteredOptions[activeIndex]) {
                            handleSelect(String(filteredOptions[activeIndex].value));
                            return;
                        }
                        if (allowFree && query.trim()) {
                            onFreeSelect?.(query.trim());
                            setOpen(false);
                            setActiveIndex(-1);
                            requestAnimationFrame(() => inputRef.current?.blur());
                        }
                    }
                }}
                disabled={disabled}
                placeholder={placeholder}
                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60"
            />

            {open && !disabled ? (
                <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-lg">
                    <button
                        type="button"
                        onPointerDown={(event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            selectingRef.current = true;
                            handleSelect('');
                        }}
                        className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-[var(--app-surface-soft)]"
                    >
                        <span>{emptyLabel}</span>
                        {!value ? <span className="text-xs text-[var(--app-muted)]">✓</span> : null}
                    </button>

                    <div className="max-h-56 overflow-y-auto border-t border-[var(--app-border)]">
                        {filteredOptions.length ? (
                            filteredOptions.map((item) => (
                                <button
                                    key={item.value}
                                    type="button"
                                    onPointerDown={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        selectingRef.current = true;
                                        handleSelect(String(item.value));
                                    }}
                                    className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-[var(--app-surface-soft)] ${
                                        activeIndex >= 0 && filteredOptions[activeIndex]?.value === item.value
                                            ? 'bg-[var(--app-surface-soft)]'
                                            : ''
                                    }`}
                                >
                                    <span>{item.label}</span>
                                    {String(value || '') === String(item.value) ? (
                                        <span className="text-xs text-[var(--app-muted)]">✓</span>
                                    ) : null}
                                </button>
                            ))
                        ) : allowFree && query.trim() ? (
                            <button
                                type="button"
                                onClick={() => {
                                    onFreeSelect?.(query.trim());
                                    setOpen(false);
                                    requestAnimationFrame(() => inputRef.current?.blur());
                                }}
                                className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-[var(--app-surface-soft)]"
                            >
                                <span>Utiliser “{query.trim()}”</span>
                                <span className="text-xs text-[var(--app-muted)]">Libre</span>
                            </button>
                        ) : (
                            <div className="px-3 py-2 text-sm text-[var(--app-muted)]">Aucun résultat</div>
                        )}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
