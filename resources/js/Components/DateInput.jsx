import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const ISO_DATE_REGEX = /^\d{4}-\d{2}-\d{2}$/;
const CALENDAR_HEIGHT_ESTIMATE = 320;
const CALENDAR_WIDTH = 288;
const CALENDAR_MAX_WIDTH = 360;
const CALENDAR_GAP = 6;
const CALENDAR_PAD = 8;
const MOBILE_EDITING_CLASS = 'app-mobile-date-editing';

const DAY_LABELS = ['Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa', 'Di'];
const MONTH_LABEL = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' });

function toDigits(value) {
    return String(value || '')
        .replace(/\D/g, '')
        .slice(0, 8);
}

function digitsToDisplay(value) {
    const digits = toDigits(value);

    if (digits.length <= 2) {
        return digits;
    }

    if (digits.length <= 4) {
        return `${digits.slice(0, 2)}/${digits.slice(2)}`;
    }

    return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
}

function isoToDisplay(iso) {
    const safe = String(iso || '').trim();

    if (!safe) {
        return '';
    }

    if (ISO_DATE_REGEX.test(safe)) {
        const [year, month, day] = safe.split('-');
        return `${day}/${month}/${year}`;
    }

    return digitsToDisplay(safe);
}

function digitsToIso(value) {
    const digits = toDigits(value);

    if (digits.length !== 8) {
        return '';
    }

    const day = Number(digits.slice(0, 2));
    const month = Number(digits.slice(2, 4));
    const year = Number(digits.slice(4, 8));

    if (day < 1 || month < 1 || month > 12 || year < 1000) {
        return '';
    }

    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
        return '';
    }

    return `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`;
}

function isoToParts(iso) {
    if (!ISO_DATE_REGEX.test(String(iso || ''))) {
        const now = new Date();
        return { year: now.getFullYear(), month: now.getMonth() };
    }

    const [year, month] = String(iso).split('-').map(Number);
    return { year, month: month - 1 };
}

function atNoon(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 12, 0, 0, 0);
}

function toIso(date) {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function monthDaysGrid(year, month) {
    const start = new Date(year, month, 1);
    const startIndex = (start.getDay() + 6) % 7;
    const firstCell = new Date(year, month, 1 - startIndex);
    const days = [];

    for (let i = 0; i < 42; i += 1) {
        const d = new Date(firstCell.getFullYear(), firstCell.getMonth(), firstCell.getDate() + i);
        days.push({
            date: d,
            iso: toIso(d),
            label: d.getDate(),
            inMonth: d.getMonth() === month,
        });
    }

    return days;
}

function caretIndexFromDigits(display, digitsCount) {
    if (digitsCount <= 0) {
        return 0;
    }

    let seen = 0;
    for (let i = 0; i < display.length; i += 1) {
        if (/\d/.test(display[i])) {
            seen += 1;
            if (seen === digitsCount) {
                return i + 1;
            }
        }
    }

    return display.length;
}

function isMobileDateContext() {
    if (typeof window === 'undefined') {
        return false;
    }

    const coarsePointer = typeof window.matchMedia === 'function'
        ? window.matchMedia('(pointer: coarse)').matches
        : false;

    return coarsePointer || window.innerWidth <= 900;
}

function getViewportMetrics() {
    if (typeof window === 'undefined') {
        return { top: 0, left: 0, width: 0, height: 0 };
    }

    const vv = window.visualViewport;
    return {
        top: vv?.offsetTop ?? 0,
        left: vv?.offsetLeft ?? 0,
        width: vv?.width ?? window.innerWidth,
        height: vv?.height ?? window.innerHeight,
    };
}

function lockMobileBottomNav() {
    if (typeof document === 'undefined') {
        return;
    }

    const body = document.body;
    const current = Number(body.dataset.mobileDateEditingCount || 0);
    const next = current + 1;
    body.dataset.mobileDateEditingCount = String(next);
    body.classList.add(MOBILE_EDITING_CLASS);
}

function unlockMobileBottomNav() {
    if (typeof document === 'undefined') {
        return;
    }

    const body = document.body;
    const current = Number(body.dataset.mobileDateEditingCount || 0);
    const next = Math.max(0, current - 1);
    body.dataset.mobileDateEditingCount = String(next);
    if (next === 0) {
        body.classList.remove(MOBILE_EDITING_CLASS);
    }
}

export default function DateInput({
    value,
    onChange,
    className = '',
    disabled = false,
    name,
    id,
    placeholder = 'JJ/MM/AAAA',
    label = 'Date',
}) {
    const inputRef = useRef(null);
    const popoverRef = useRef(null);
    const mobileDraftInputRef = useRef(null);
    const isFocusedRef = useRef(false);
    const desktopCaretRef = useRef(null);
    const mobileCaretRef = useRef(null);
    const instanceIdRef = useRef(`date-input-${Math.random().toString(36).slice(2)}`);
    const openRequestRef = useRef(0);

    const [isMobile, setIsMobile] = useState(() => isMobileDateContext());
    const [display, setDisplay] = useState(isoToDisplay(value));
    const [desktopOpen, setDesktopOpen] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [mobileDraftDisplay, setMobileDraftDisplay] = useState('');
    const [position, setPosition] = useState({
        top: 0,
        left: 0,
        width: CALENDAR_WIDTH,
        maxHeight: CALENDAR_HEIGHT_ESTIMATE,
    });
    const [view, setView] = useState(() => isoToParts(value));

    const selectedIso = useMemo(() => {
        if (ISO_DATE_REGEX.test(String(value || ''))) {
            return String(value);
        }

        return digitsToIso(display) || '';
    }, [display, value]);

    const activeIso = mobileOpen ? (digitsToIso(mobileDraftDisplay) || '') : selectedIso;

    useEffect(() => {
        const evaluateMobile = () => {
            setIsMobile(isMobileDateContext());
        };

        evaluateMobile();
        window.addEventListener('resize', evaluateMobile);
        window.visualViewport?.addEventListener('resize', evaluateMobile);

        return () => {
            window.removeEventListener('resize', evaluateMobile);
            window.visualViewport?.removeEventListener('resize', evaluateMobile);
        };
    }, []);

    useEffect(() => {
        if (isFocusedRef.current || mobileOpen) {
            return;
        }

        setDisplay(isoToDisplay(value));
    }, [mobileOpen, value]);

    useEffect(() => {
        if (desktopCaretRef.current === null || !inputRef.current) {
            return;
        }

        const next = desktopCaretRef.current;
        desktopCaretRef.current = null;

        requestAnimationFrame(() => {
            inputRef.current?.setSelectionRange(next, next);
        });
    }, [display]);

    useEffect(() => {
        if (mobileCaretRef.current === null || !mobileDraftInputRef.current || !mobileOpen) {
            return;
        }

        const next = mobileCaretRef.current;
        mobileCaretRef.current = null;

        requestAnimationFrame(() => {
            mobileDraftInputRef.current?.setSelectionRange(next, next);
        });
    }, [mobileDraftDisplay, mobileOpen]);

    const syncDesktopPosition = () => {
        const input = inputRef.current;
        if (!input) {
            return;
        }

        const rect = input.getBoundingClientRect();
        const metrics = getViewportMetrics();
        const popover = popoverRef.current;
        const estimatedHeight = popover?.offsetHeight || CALENDAR_HEIGHT_ESTIMATE;
        const width = Math.max(Math.min(rect.width, CALENDAR_MAX_WIDTH), CALENDAR_WIDTH);

        const spaceAbove = rect.top - metrics.top - CALENDAR_GAP - CALENDAR_PAD;
        const spaceBelow = metrics.top + metrics.height - rect.bottom - CALENDAR_GAP - CALENDAR_PAD;

        let placement = 'bottom';
        if (spaceBelow < estimatedHeight && spaceAbove > spaceBelow) {
            placement = 'top';
        }

        let maxHeight = Math.max(140, Math.min(
            estimatedHeight,
            placement === 'bottom' ? spaceBelow : spaceAbove,
        ));

        if (placement === 'bottom' && maxHeight < 140 && spaceAbove > spaceBelow) {
            placement = 'top';
            maxHeight = Math.max(140, Math.min(estimatedHeight, spaceAbove));
        }

        if (placement === 'top' && maxHeight < 140 && spaceBelow >= spaceAbove) {
            placement = 'bottom';
            maxHeight = Math.max(140, Math.min(estimatedHeight, spaceBelow));
        }

        const minTop = metrics.top + CALENDAR_PAD;
        const maxTop = metrics.top + metrics.height - maxHeight - CALENDAR_PAD;

        let top = placement === 'bottom'
            ? rect.bottom + CALENDAR_GAP
            : rect.top - maxHeight - CALENDAR_GAP;
        top = Math.max(minTop, Math.min(top, Math.max(minTop, maxTop)));

        const minLeft = metrics.left + CALENDAR_PAD;
        const maxLeft = metrics.left + metrics.width - width - CALENDAR_PAD;
        const left = Math.max(minLeft, Math.min(rect.left, Math.max(minLeft, maxLeft)));

        setPosition({ top, left, width, maxHeight });
    };

    const closeDesktopCalendar = () => {
        setDesktopOpen(false);
    };

    const closeMobileEditor = () => {
        setMobileOpen(false);
    };

    const openMobileEditor = () => {
        if (disabled || mobileOpen) {
            return;
        }

        window.dispatchEvent(
            new CustomEvent('app:date-input-open', { detail: { id: instanceIdRef.current } }),
        );

        const iso = selectedIso || digitsToIso(display);
        setView(isoToParts(iso));
        setMobileDraftDisplay(isoToDisplay(iso));
        setMobileOpen(true);
        inputRef.current?.blur();
    };

    useEffect(() => {
        if (!isMobile && mobileOpen) {
            setMobileOpen(false);
        }

        if (isMobile && desktopOpen) {
            setDesktopOpen(false);
        }
    }, [desktopOpen, isMobile, mobileOpen]);

    const openDesktopCalendar = async () => {
        if (disabled) {
            return;
        }

        window.dispatchEvent(
            new CustomEvent('app:date-input-open', { detail: { id: instanceIdRef.current } }),
        );

        const openRequestId = openRequestRef.current + 1;
        openRequestRef.current = openRequestId;

        const iso = selectedIso || digitsToIso(display);
        setView(isoToParts(iso));
        setDesktopOpen(true);

        if (openRequestRef.current !== openRequestId) {
            return;
        }
    };

    const openDateEditor = () => {
        if (disabled) {
            return;
        }

        if (isMobile) {
            openMobileEditor();
            return;
        }

        openDesktopCalendar();
    };

    useLayoutEffect(() => {
        if (!desktopOpen || isMobile) {
            return;
        }

        syncDesktopPosition();
    }, [desktopOpen, isMobile, view.month, view.year]);

    useEffect(() => {
        const onAnotherOpen = (event) => {
            if (event.detail?.id !== instanceIdRef.current) {
                openRequestRef.current = 0;
                setDesktopOpen(false);
                setMobileOpen(false);
            }
        };

        window.addEventListener('app:date-input-open', onAnotherOpen);
        return () => window.removeEventListener('app:date-input-open', onAnotherOpen);
    }, []);

    useEffect(() => {
        if (!desktopOpen || isMobile) {
            return undefined;
        }

        const onPointerDownOutside = (event) => {
            const input = inputRef.current;
            const popover = popoverRef.current;
            const target = event.target;

            if (input?.contains(target) || popover?.contains(target)) {
                return;
            }

            closeDesktopCalendar();
        };

        const onViewportChange = () => syncDesktopPosition();
        const onEscape = (event) => {
            if (event.key === 'Escape') {
                closeDesktopCalendar();
            }
        };
        const vv = window.visualViewport;

        document.addEventListener('pointerdown', onPointerDownOutside, true);
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('scroll', onViewportChange, true);
        window.addEventListener('keydown', onEscape);
        vv?.addEventListener('resize', onViewportChange);
        vv?.addEventListener('scroll', onViewportChange);

        return () => {
            document.removeEventListener('pointerdown', onPointerDownOutside, true);
            window.removeEventListener('resize', onViewportChange);
            window.removeEventListener('scroll', onViewportChange, true);
            window.removeEventListener('keydown', onEscape);
            vv?.removeEventListener('resize', onViewportChange);
            vv?.removeEventListener('scroll', onViewportChange);
        };
    }, [desktopOpen, isMobile]);

    useEffect(() => {
        if (!mobileOpen || !isMobile) {
            return undefined;
        }

        lockMobileBottomNav();

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const onEscape = (event) => {
            if (event.key === 'Escape') {
                closeMobileEditor();
            }
        };

        window.addEventListener('keydown', onEscape);

        return () => {
            window.removeEventListener('keydown', onEscape);
            document.body.style.overflow = previousOverflow;
            unlockMobileBottomNav();
        };
    }, [isMobile, mobileOpen]);

    useEffect(() => {
        if (!mobileOpen || !isMobile) {
            return;
        }

        const timer = window.setTimeout(() => {
            mobileDraftInputRef.current?.focus({ preventScroll: true });
        }, 40);

        return () => window.clearTimeout(timer);
    }, [isMobile, mobileOpen]);

    const updateDesktopDisplay = (rawDigits, caretDigits = null) => {
        const next = digitsToDisplay(rawDigits);
        setDisplay(next);
        onChange?.(digitsToIso(rawDigits));

        if (caretDigits !== null) {
            desktopCaretRef.current = caretIndexFromDigits(next, caretDigits);
        }
    };

    const updateMobileDraft = (rawDigits, caretDigits = null) => {
        const next = digitsToDisplay(rawDigits);
        setMobileDraftDisplay(next);

        if (caretDigits !== null) {
            mobileCaretRef.current = caretIndexFromDigits(next, caretDigits);
        }
    };

    const handleMaskedChange = (event, updater) => {
        const raw = event.target.value;
        const cursor = event.target.selectionStart ?? raw.length;
        const digits = toDigits(raw);
        const digitsBeforeCursor = toDigits(raw.slice(0, cursor)).length;
        updater(digits, digitsBeforeCursor);
    };

    const handleMaskedKeyDown = (event, currentDisplay, updater, onArrowDown = null) => {
        const start = event.currentTarget.selectionStart ?? 0;
        const end = event.currentTarget.selectionEnd ?? 0;

        if (event.key.length === 1 && !/\d/.test(event.key) && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            return;
        }

        if (event.key === 'Backspace' && start === end && start > 0 && currentDisplay[start - 1] === '/') {
            event.preventDefault();
            const digits = toDigits(currentDisplay);
            const digitsBeforeSlash = toDigits(currentDisplay.slice(0, start - 1)).length;
            const removeAt = Math.max(0, digitsBeforeSlash - 1);
            const nextDigits = `${digits.slice(0, removeAt)}${digits.slice(removeAt + 1)}`;
            updater(nextDigits, digitsBeforeSlash - 1);
            return;
        }

        if (event.key === 'Delete' && start === end && currentDisplay[start] === '/') {
            event.preventDefault();
            const digits = toDigits(currentDisplay);
            const digitsBeforeSlash = toDigits(currentDisplay.slice(0, start)).length;
            const nextDigits = `${digits.slice(0, digitsBeforeSlash)}${digits.slice(digitsBeforeSlash + 1)}`;
            updater(nextDigits, digitsBeforeSlash);
            return;
        }

        if (event.key === 'ArrowDown' && onArrowDown) {
            event.preventDefault();
            onArrowDown();
        }
    };

    const goMonth = (step) => {
        const nextMonth = new Date(view.year, view.month + step, 1);
        setView({ year: nextMonth.getFullYear(), month: nextMonth.getMonth() });
    };

    const pickDateDesktop = (date) => {
        const normalized = atNoon(date);
        const iso = toIso(normalized);
        setDisplay(isoToDisplay(iso));
        onChange?.(iso);
        closeDesktopCalendar();
        inputRef.current?.focus();
    };

    const pickDateMobile = (date) => {
        const normalized = atNoon(date);
        const iso = toIso(normalized);
        setMobileDraftDisplay(isoToDisplay(iso));
    };

    const validateMobile = () => {
        const iso = digitsToIso(mobileDraftDisplay);

        if (iso) {
            setDisplay(isoToDisplay(iso));
            onChange?.(iso);
            setView(isoToParts(iso));
        } else {
            const masked = digitsToDisplay(mobileDraftDisplay);
            setDisplay(masked);
            onChange?.('');
        }

        closeMobileEditor();
    };

    const cancelMobile = () => {
        closeMobileEditor();
    };

    const days = useMemo(() => monthDaysGrid(view.year, view.month), [view.year, view.month]);
    const todayIso = toIso(atNoon(new Date()));

    return (
        <>
            <input
                ref={inputRef}
                type="text"
                inputMode={isMobile ? 'none' : 'numeric'}
                autoComplete="off"
                spellCheck={false}
                name={name}
                id={id}
                value={display}
                onChange={(event) => handleMaskedChange(event, updateDesktopDisplay)}
                onKeyDown={(event) => handleMaskedKeyDown(event, display, updateDesktopDisplay, openDesktopCalendar)}
                onFocus={() => {
                    isFocusedRef.current = true;
                    if (isMobile) {
                        openMobileEditor();
                    }
                }}
                onBlur={() => {
                    isFocusedRef.current = false;
                }}
                onClick={openDateEditor}
                onTouchStart={() => {
                    if (isMobile) {
                        openMobileEditor();
                    }
                }}
                readOnly={isMobile}
                placeholder={placeholder}
                maxLength={10}
                disabled={disabled}
                className={className}
            />

            {desktopOpen && !isMobile && typeof document !== 'undefined'
                ? createPortal(
                      <div
                          ref={popoverRef}
                          className="z-[1000] rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-xl"
                          style={{
                              position: 'fixed',
                              top: `${position.top}px`,
                              left: `${position.left}px`,
                              width: `${position.width}px`,
                              maxHeight: `${position.maxHeight}px`,
                              overflowY: 'auto',
                          }}
                      >
                          <div className="mb-2 flex items-center justify-between">
                              <button
                                  type="button"
                                  onClick={() => goMonth(-1)}
                                  className="rounded-lg border border-[var(--app-border)] px-2 py-1 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                              >
                                  {'<'}
                              </button>

                              <div className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                                  {MONTH_LABEL.format(new Date(view.year, view.month, 1))}
                              </div>

                              <button
                                  type="button"
                                  onClick={() => goMonth(1)}
                                  className="rounded-lg border border-[var(--app-border)] px-2 py-1 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                              >
                                  {'>'}
                              </button>
                          </div>

                          <div className="grid grid-cols-7 gap-1">
                              {DAY_LABELS.map((dayLabel) => (
                                  <div
                                      key={dayLabel}
                                      className="py-1 text-center text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]"
                                  >
                                      {dayLabel}
                                  </div>
                              ))}

                              {days.map((day) => {
                                  const isSelected = activeIso === day.iso;
                                  const isToday = todayIso === day.iso;

                                  return (
                                      <button
                                          key={day.iso}
                                          type="button"
                                          onClick={() => pickDateDesktop(day.date)}
                                          className={`h-8 rounded-lg text-xs font-bold transition ${
                                              isSelected
                                                  ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                  : day.inMonth
                                                    ? 'text-[var(--app-text)] hover:bg-[var(--app-surface-soft)]'
                                                    : 'text-[var(--app-muted)] opacity-60 hover:bg-[var(--app-surface-soft)]'
                                          } ${isToday && !isSelected ? 'ring-1 ring-[var(--app-border)]' : ''}`}
                                      >
                                          {day.label}
                                      </button>
                                  );
                              })}
                          </div>
                      </div>,
                      document.body,
                  )
                : null}

            {mobileOpen && isMobile && typeof document !== 'undefined'
                ? createPortal(
                      <div className="fixed inset-0 z-[1200] bg-black/45">
                          <div
                              className="absolute inset-0"
                              onClick={cancelMobile}
                              aria-hidden="true"
                          />

                          <div className="absolute inset-x-0 bottom-0 max-h-[88vh] overflow-hidden rounded-t-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-2xl">
                              <div className="flex items-center justify-between border-b border-[var(--app-border)] px-4 py-3">
                                  <div>
                                      <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Champ date</p>
                                      <h3 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">{label}</h3>
                                  </div>

                                  <button
                                      type="button"
                                      onClick={cancelMobile}
                                      className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-xs font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                                  >
                                      Fermer
                                  </button>
                              </div>

                              <div className="max-h-[calc(88vh-132px)] overflow-y-auto px-4 py-3">
                                  <div className="mb-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                                      <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">Valeur actuelle</p>
                                      <p className="mt-1 text-sm font-bold text-[var(--app-text)]">{display || 'Non renseignée'}</p>
                                  </div>

                                  <label className="block text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                      Saisie manuelle (JJ/MM/AAAA)
                                  </label>
                                  <input
                                      ref={mobileDraftInputRef}
                                      type="text"
                                      inputMode="numeric"
                                      autoComplete="off"
                                      spellCheck={false}
                                      value={mobileDraftDisplay}
                                      onChange={(event) => handleMaskedChange(event, updateMobileDraft)}
                                      onKeyDown={(event) => handleMaskedKeyDown(event, mobileDraftDisplay, updateMobileDraft)}
                                      maxLength={10}
                                      className="mt-1 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)]"
                                      placeholder={placeholder}
                                  />

                                  <div className="mt-4 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-2.5">
                                      <div className="mb-2 flex items-center justify-between">
                                          <button
                                              type="button"
                                              onClick={() => goMonth(-1)}
                                              className="rounded-lg border border-[var(--app-border)] px-2 py-1 text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                                          >
                                              {'<'}
                                          </button>

                                          <div className="text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                                              {MONTH_LABEL.format(new Date(view.year, view.month, 1))}
                                          </div>

                                          <button
                                              type="button"
                                              onClick={() => goMonth(1)}
                                              className="rounded-lg border border-[var(--app-border)] px-2 py-1 text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                                          >
                                              {'>'}
                                          </button>
                                      </div>

                                      <div className="grid grid-cols-7 gap-1">
                                          {DAY_LABELS.map((dayLabel) => (
                                              <div
                                                  key={dayLabel}
                                                  className="py-0.5 text-center text-[9px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]"
                                              >
                                                  {dayLabel}
                                              </div>
                                          ))}

                                          {days.map((day) => {
                                              const isSelected = activeIso === day.iso;
                                              const isToday = todayIso === day.iso;

                                              return (
                                                  <button
                                                      key={day.iso}
                                                      type="button"
                                                      onClick={() => pickDateMobile(day.date)}
                                                      className={`h-8 rounded-lg text-[11px] font-bold transition ${
                                                          isSelected
                                                              ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                              : day.inMonth
                                                                ? 'text-[var(--app-text)] hover:bg-[var(--app-surface)]'
                                                                : 'text-[var(--app-muted)] opacity-60 hover:bg-[var(--app-surface)]'
                                                      } ${isToday && !isSelected ? 'ring-1 ring-[var(--app-border)]' : ''}`}
                                                  >
                                                      {day.label}
                                                  </button>
                                              );
                                          })}
                                      </div>
                                  </div>
                              </div>

                              <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
                                  <button
                                      type="button"
                                      onClick={cancelMobile}
                                      className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--app-text)]"
                                  >
                                      Annuler
                                  </button>
                                  <button
                                      type="button"
                                      onClick={validateMobile}
                                      className="rounded-lg border border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--color-black)]"
                                  >
                                      Valider
                                  </button>
                              </div>
                          </div>
                      </div>,
                      document.body,
                  )
                : null}
        </>
    );
}
