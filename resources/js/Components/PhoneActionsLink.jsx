import { Phone, PhoneCall, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

function toTelHref(number) {
    const raw = (number || '').toString().trim();
    if (!raw) return null;
    return `tel:${raw.replace(/[^\d+]/g, '')}`;
}

function toSmsHref(number) {
    const raw = (number || '').toString().trim();
    if (!raw) return null;
    return `sms:${raw.replace(/[^\d+]/g, '')}`;
}

export default function PhoneActionsLink({ number, className = '', buttonClassName = '' }) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        const handleOutside = (event) => {
            if (!containerRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handleOutside);
        document.addEventListener('touchstart', handleOutside);
        return () => {
            document.removeEventListener('mousedown', handleOutside);
            document.removeEventListener('touchstart', handleOutside);
        };
    }, [open]);

    const callHref = toTelHref(number);
    const smsHref = toSmsHref(number);

    if (!callHref && !smsHref) return null;

    return (
        <div ref={containerRef} className={`relative ${className}`}>
            <button
                type="button"
                onClick={(event) => {
                    event.stopPropagation();
                    setOpen((prev) => !prev);
                }}
                className={`inline-flex items-center gap-1.5 text-left transition-opacity hover:opacity-70 active:opacity-70 ${buttonClassName}`}
            >
                <Phone className="h-3.5 w-3.5 shrink-0" strokeWidth={2} />
                <span>{number}</span>
            </button>

            {open ? (
                <div className="absolute bottom-full left-0 z-50 mb-1.5 min-w-[10rem] overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl shadow-black/15">
                    {callHref ? (
                        <a
                            href={callHref}
                            className="flex items-center gap-2.5 px-3.5 py-2.5 text-sm font-semibold hover:bg-[var(--app-surface-soft)]"
                            onClick={() => setOpen(false)}
                        >
                            <PhoneCall className="h-4 w-4 shrink-0" strokeWidth={2.2} />
                            Appeler
                        </a>
                    ) : null}
                    {smsHref ? (
                        <a
                            href={smsHref}
                            className={`flex items-center gap-2.5 px-3.5 py-2.5 text-sm font-semibold hover:bg-[var(--app-surface-soft)] ${callHref ? 'border-t border-[var(--app-border)]' : ''}`}
                            onClick={() => setOpen(false)}
                        >
                            <Smartphone className="h-4 w-4 shrink-0" strokeWidth={2.2} />
                            Envoyer un SMS
                        </a>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
