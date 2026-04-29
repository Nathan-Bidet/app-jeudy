import { usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const TOAST_DURATION_MS = 5000;

const STATUS_MESSAGE_MAP = {
    'Sector created.': 'Secteur créé.',
    'Sector updated.': 'Secteur enregistré.',
    'Sector saved.': 'Secteur enregistré.',
    'Sector default permissions updated.': 'Permissions enregistrées.',
    'Sector deleted.': 'Secteur supprimé.',
    'Sector duplicated.': 'Secteur dupliqué.',
    'User created.': 'Utilisateur créé.',
    'User deleted.': 'Utilisateur supprimé.',
    'User account updated.': 'Compte enregistré.',
    'User access scope updated.': 'Rôle et secteur enregistrés.',
    'User exceptions updated.': 'Exceptions enregistrées.',
    'verification-link-sent': 'Lien envoyé.',
    'Two-factor authentication is now active.': '2FA activé.',
    'Scan the new QR code to complete your TOTP reset.': 'Nouveau QR code généré.',
    'Recovery codes were regenerated.': 'Codes de secours régénérés.',
    'Two-factor authentication was disabled. Re-enrollment is required.':
        '2FA désactivé.',
};

function normalizeToastMessage(type, message) {
    const raw = String(message ?? '').trim();

    if (!raw) {
        return '';
    }

    if (STATUS_MESSAGE_MAP[raw]) {
        return STATUS_MESSAGE_MAP[raw];
    }

    if (type === 'success') {
        if (/\b(created|updated|deleted|saved|regenerated)\b/i.test(raw)) {
            return 'Enregistré.';
        }
    }

    return raw;
}

function firstErrorMessage(errors) {
    if (!errors || typeof errors !== 'object') {
        return null;
    }

    const values = Object.values(errors);

    for (const value of values) {
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }

        if (Array.isArray(value)) {
            const firstString = value.find(
                (item) => typeof item === 'string' && item.trim() !== '',
            );

            if (firstString) {
                return firstString;
            }
        }
    }

    return null;
}

export default function ToastHost() {
    const page = usePage();
    const flash = page.props.flash ?? {};
    const errors = page.props.errors ?? {};

    const [toast, setToast] = useState(null);
    const timeoutRef = useRef(null);
    const lastPagePropsRef = useRef(null);

    const candidate = useMemo(() => {
        const flashError = typeof flash.error === 'string' ? flash.error.trim() : '';
        const flashSuccess = typeof flash.success === 'string' ? flash.success.trim() : '';
        const flashStatus = typeof flash.status === 'string' ? flash.status.trim() : '';
        const validationError = firstErrorMessage(errors);

        if (flashError) {
            return { type: 'error', message: normalizeToastMessage('error', flashError) };
        }

        if (flashSuccess) {
            return { type: 'success', message: normalizeToastMessage('success', flashSuccess) };
        }

        if (flashStatus) {
            return { type: 'success', message: normalizeToastMessage('success', flashStatus) };
        }

        if (validationError) {
            return {
                type: 'error',
                message: normalizeToastMessage('error', validationError),
            };
        }

        return null;
    }, [errors, flash.error, flash.success, flash.status]);

    useEffect(() => {
        if (!candidate) {
            return;
        }

        if (lastPagePropsRef.current === page.props) {
            return;
        }

        lastPagePropsRef.current = page.props;
        setToast({
            id: Date.now(),
            ...candidate,
        });
    }, [candidate, page.props]);

    useEffect(() => {
        if (!toast) {
            return;
        }

        if (timeoutRef.current) {
            clearTimeout(timeoutRef.current);
        }

        timeoutRef.current = setTimeout(() => {
            setToast(null);
            timeoutRef.current = null;
        }, TOAST_DURATION_MS);

        return () => {
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
                timeoutRef.current = null;
            }
        };
    }, [toast]);

    useEffect(() => {
        const handler = (event) => {
            const detail = event?.detail ?? {};
            const type = detail.type === 'error' ? 'error' : 'success';
            const message = normalizeToastMessage(type, detail.message);

            if (!message) {
                return;
            }

            setToast({
                id: Date.now(),
                type,
                message,
            });
        };

        window.addEventListener('app:toast', handler);
        return () => window.removeEventListener('app:toast', handler);
    }, []);

    if (!toast) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed bottom-3 right-3 z-[70] w-[min(92vw,340px)]">
            <div
                className={`app-toast pointer-events-auto ${
                    toast.type === 'error' ? 'app-toast--error' : 'app-toast--success'
                }`}
                role="status"
                aria-live="polite"
            >
                <div className="flex items-start gap-2.5 p-3">
                    <div className="app-toast__badge mt-0.5 text-[11px] font-black">
                        {toast.type === 'error' ? (
                            <AlertCircle className="h-3.5 w-3.5" strokeWidth={2.2} />
                        ) : (
                            <CheckCircle2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                        )}
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="text-[10px] font-black uppercase tracking-[0.12em] opacity-80">
                            {toast.type === 'error' ? 'Erreur' : 'Succès'}
                        </div>
                        <p className="mt-0.5 text-xs font-semibold leading-snug">
                            {toast.message}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setToast(null)}
                        className="rounded-md px-1.5 py-1 text-[10px] font-bold opacity-70 transition hover:opacity-100"
                        aria-label="Fermer"
                    >
                        Fermer
                    </button>
                </div>

                <div className="app-toast__progress-track">
                    <div
                        key={toast.id}
                        className="app-toast__progress-bar"
                        style={{ animationDuration: `${TOAST_DURATION_MS}ms` }}
                    />
                </div>
            </div>
        </div>
    );
}
