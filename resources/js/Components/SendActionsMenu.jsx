import { Mail, Send, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * Bouton "Envoyer" ouvrant un petit menu de choix SMS/e-mail, au format du
 * Livre du travail (liens sms:/mailto: pré-remplis, ouverts dans
 * l'application native de l'appareil — aucun envoi automatique). Modélisé
 * sur l'interaction de PhoneActionsLink.jsx (bouton + popup positionné,
 * fermeture au clic extérieur), étendu avec la sémantique ARIA d'un menu
 * (rôles menu/menuitem, fermeture et restitution du focus via Échap).
 *
 * smsHref/mailHref à null désactivent l'action correspondante et affichent
 * le message associé (coordonnée absente) sans jamais ouvrir d'application.
 */
export default function SendActionsMenu({
    label = 'Envoyer',
    smsHref = null,
    smsDisabledReason = 'Aucun numéro de portable renseigné',
    mailHref = null,
    mailDisabledReason = 'Aucune adresse e-mail renseignée',
    noRecipientMessage = 'Aucun destinataire disponible',
    className = '',
    buttonClassName = '',
}) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const buttonRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        const handleOutside = (event) => {
            if (!containerRef.current?.contains(event.target)) {
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

            {open ? (
                <div
                    role="menu"
                    aria-label={label}
                    onClick={(event) => event.stopPropagation()}
                    className="absolute left-1/2 top-full z-20 mt-1 w-60 max-w-[85vw] -translate-x-1/2 overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] text-left shadow-xl shadow-black/15"
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
                                    className="flex items-center gap-2.5 px-3.5 py-2.5 text-sm font-semibold hover:bg-[var(--app-surface-soft)]"
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
                                    className="flex items-center gap-2.5 px-3.5 py-2.5 text-sm font-semibold hover:bg-[var(--app-surface-soft)]"
                                >
                                    <Mail className="h-4 w-4 shrink-0" strokeWidth={2.2} />
                                    Envoyer par e-mail
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
                </div>
            ) : null}
        </div>
    );
}
