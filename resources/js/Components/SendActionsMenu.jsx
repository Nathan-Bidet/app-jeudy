import { Mail, Send, Smartphone } from 'lucide-react';
import { computePopoverPosition } from '@/Support/popoverPosition';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const MENU_WIDTH = 240;

/**
 * Bouton "Envoyer" ouvrant un petit menu de choix SMS/e-mail, au format du
 * Livre du travail (liens sms:/mailto: pré-remplis, ouverts dans
 * l'application native de l'appareil — aucun envoi automatique). Modélisé
 * sur l'interaction de PhoneActionsLink.jsx (bouton + popup positionné,
 * fermeture au clic extérieur), étendu avec la sémantique ARIA d'un menu
 * (rôles menu/menuitem, fermeture et restitution du focus via Échap).
 *
 * smsHref à null désactive l'action SMS et affiche smsDisabledReason, sans
 * jamais ouvrir d'application. mailHref, lui, reste normalement toujours
 * fourni par l'appelant (même sans adresse connue, via un mailto: à
 * destinataire vide) : passer mailHasRecipient=false affiche alors une note
 * discrète sous l'action, qui reste cliquable — seul mailHref=null désactive
 * réellement l'e-mail (cas d'usage générique, non utilisé pour les tâches
 * Engrais/À prévoir où l'e-mail doit toujours rester proposable).
 */
export default function SendActionsMenu({
    label = 'Envoyer',
    smsHref = null,
    smsDisabledReason = 'Aucun numéro de portable renseigné',
    mailHref = null,
    mailHasRecipient = true,
    mailNoRecipientNote = 'Adresse à renseigner dans votre messagerie',
    mailDisabledReason = 'Aucune adresse e-mail renseignée',
    noRecipientMessage = 'Aucun destinataire disponible',
    className = '',
    buttonClassName = '',
}) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState(null);
    const containerRef = useRef(null);
    const buttonRef = useRef(null);
    const menuRef = useRef(null);

    // Le menu est rendu dans <body> (position: fixed) : il échappe ainsi aux
    // conteneurs `overflow` du tableau, et sa position est recalculée pour rester
    // dans la zone visible (ouverture, redimensionnement, défilement).
    useLayoutEffect(() => {
        if (!open) {
            setPosition(null);
            return undefined;
        }

        const update = () => {
            if (!buttonRef.current || !menuRef.current) return;
            const menu = menuRef.current;
            // scrollHeight = hauteur naturelle du contenu, indépendante de max-height.
            const naturalHeight = menu.scrollHeight + (menu.offsetHeight - menu.clientHeight);
            setPosition(
                computePopoverPosition(
                    buttonRef.current.getBoundingClientRect(),
                    { width: MENU_WIDTH, height: naturalHeight },
                    { width: window.innerWidth, height: window.innerHeight },
                ),
            );
        };

        update();
        window.addEventListener('resize', update);
        window.addEventListener('scroll', update, true);
        return () => {
            window.removeEventListener('resize', update);
            window.removeEventListener('scroll', update, true);
        };
    }, [open]);

    useEffect(() => {
        if (open && position) {
            menuRef.current?.querySelector('[role="menuitem"][href]')?.focus({ preventScroll: true });
        }
        // Focus uniquement à l'ouverture (première position calculée).
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, position === null]);

    const handleMenuKeyDown = (event) => {
        const items = [...(menuRef.current?.querySelectorAll('[role="menuitem"][href]') ?? [])];
        if (!items.length) return;
        const index = items.indexOf(document.activeElement);
        const move = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: items.length - 1 }[event.key];
        if (move === undefined) return;
        event.preventDefault();
        items[(move + items.length) % items.length].focus();
    };

    useEffect(() => {
        if (!open) return undefined;

        const handleOutside = (event) => {
            if (!containerRef.current?.contains(event.target) && !menuRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);
                buttonRef.current?.focus();
            }
        };
        document.addEventListener('mousedown', handleOutside);
        document.addEventListener('touchstart', handleOutside);
        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('mousedown', handleOutside);
            document.removeEventListener('touchstart', handleOutside);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [open]);

    const hasAnyRecipient = Boolean(smsHref || mailHref);
    const defaultButtonClassName =
        'inline-flex items-center gap-1 rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]';

    return (
        <div ref={containerRef} className={`relative inline-block ${className}`}>
            <button
                ref={buttonRef}
                type="button"
                onClick={(event) => {
                    event.stopPropagation();
                    setOpen((prev) => !prev);
                }}
                aria-haspopup="menu"
                aria-expanded={open}
                className={buttonClassName || defaultButtonClassName}
            >
                <Send className="h-3 w-3" strokeWidth={2.2} />
                <span>{label}</span>
            </button>

            {open ? createPortal(
                <div
                    ref={menuRef}
                    role="menu"
                    aria-label={label}
                    onClick={(event) => event.stopPropagation()}
                    onKeyDown={handleMenuKeyDown}
                    style={{
                        position: 'fixed',
                        left: position?.left ?? 0,
                        top: position?.top ?? 0,
                        width: position?.width ?? MENU_WIDTH,
                        maxHeight: position?.maxHeight,
                        visibility: position ? 'visible' : 'hidden',
                    }}
                    className="z-[120] overflow-y-auto overflow-x-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-left text-[var(--app-text)] shadow-xl shadow-black/15"
                >
                    {!hasAnyRecipient ? (
                        <p className="px-3.5 py-2.5 text-xs text-[var(--app-muted)]">{noRecipientMessage}</p>
                    ) : (
                        <>
                            {smsHref ? (
                                <a
                                    role="menuitem"
                                    href={smsHref}
                                    onClick={() => setOpen(false)}
                                    className="flex items-center gap-2.5 px-3.5 py-2.5 text-sm font-semibold hover:bg-[var(--app-surface-soft)] focus-visible:bg-[var(--app-surface-soft)] focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--brand-yellow-dark)]"
                                >
                                    <Smartphone className="h-4 w-4 shrink-0" strokeWidth={2.2} />
                                    Envoyer par SMS
                                </a>
                            ) : (
                                <p
                                    role="menuitem"
                                    aria-disabled="true"
                                    className="flex cursor-not-allowed items-center gap-2.5 px-3.5 py-2.5 text-sm text-[var(--app-muted)]"
                                >
                                    <Smartphone className="h-4 w-4 shrink-0" strokeWidth={2.2} />
                                    {smsDisabledReason}
                                </p>
                            )}
                            <div className="border-t border-[var(--app-border)]" />
                            {mailHref ? (
                                <a
                                    role="menuitem"
                                    href={mailHref}
                                    onClick={() => setOpen(false)}
                                    className="flex flex-col gap-0.5 px-3.5 py-2.5 text-sm font-semibold hover:bg-[var(--app-surface-soft)] focus-visible:bg-[var(--app-surface-soft)] focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--brand-yellow-dark)]"
                                >
                                    <span className="flex items-center gap-2.5">
                                        <Mail className="h-4 w-4 shrink-0" strokeWidth={2.2} />
                                        Envoyer par e-mail
                                    </span>
                                    {!mailHasRecipient ? (
                                        <span className="pl-[1.625rem] text-xs font-normal text-[var(--app-muted)]">
                                            {mailNoRecipientNote}
                                        </span>
                                    ) : null}
                                </a>
                            ) : (
                                <p
                                    role="menuitem"
                                    aria-disabled="true"
                                    className="flex cursor-not-allowed items-center gap-2.5 px-3.5 py-2.5 text-sm text-[var(--app-muted)]"
                                >
                                    <Mail className="h-4 w-4 shrink-0" strokeWidth={2.2} />
                                    {mailDisabledReason}
                                </p>
                            )}
                        </>
                    )}
                </div>,
                document.body,
            ) : null}
        </div>
    );
}
