import { usePushNotifications } from '@/hooks/usePushNotifications';
import { Bell, BellOff, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const STORAGE_KEY = 'jeudy_push_dismissed_until';
const LATER_DAYS = 30;

function isStandaloneMode() {
    if (typeof window === 'undefined') return false;
    const mediaStandalone = window.matchMedia?.('(display-mode: standalone)')?.matches;
    const iosStandalone = window.navigator.standalone === true;
    return Boolean(mediaStandalone || iosStandalone);
}

function isDismissed() {
    try {
        const until = localStorage.getItem(STORAGE_KEY);
        if (!until) return false;
        return new Date(until) > new Date();
    } catch {
        return false;
    }
}

function dismissForLater() {
    try {
        const until = new Date();
        until.setDate(until.getDate() + LATER_DAYS);
        localStorage.setItem(STORAGE_KEY, until.toISOString());
    } catch {}
}

function dismissForever() {
    try {
        const forever = new Date('2099-01-01');
        localStorage.setItem(STORAGE_KEY, forever.toISOString());
    } catch {}
}

export default function PushNotificationPrompt() {
    const { isSupported, permission, isSubscribed, isLoading, subscribe } = usePushNotifications();
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!isSupported) return;
        if (!isStandaloneMode()) return;
        if (permission !== 'default') return;
        if (isSubscribed) return;
        if (isDismissed()) return;

        setVisible(true);
    }, [isSupported, permission, isSubscribed]);

    if (!visible) return null;

    const handleActivate = async () => {
        const success = await subscribe();
        if (success || permission !== 'default') {
            dismissForever();
            setVisible(false);
        }
    };

    const handleLater = () => {
        dismissForLater();
        setVisible(false);
    };

    const handleDismiss = () => {
        dismissForever();
        setVisible(false);
    };

    return (
        <div
            role="dialog"
            aria-live="polite"
            className="fixed bottom-20 left-3 right-3 z-[60] md:bottom-6 md:left-auto md:right-6 md:w-80"
        >
            <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-xl shadow-black/20">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[var(--app-surface-soft)]">
                            <Bell className="h-4.5 w-4.5" strokeWidth={2} />
                        </div>
                        <div>
                            <p className="text-sm font-semibold leading-snug">Notifications Push</p>
                            <p className="mt-0.5 text-xs text-[var(--app-muted)]">
                                Recevez une notification lorsqu'une demande de congé vous est soumise.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={handleDismiss}
                        className="shrink-0 rounded-lg p-1 text-[var(--app-muted)] hover:bg-[var(--app-surface-soft)]"
                        aria-label="Fermer"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="mt-3 flex gap-2">
                    <button
                        type="button"
                        onClick={handleLater}
                        disabled={isLoading}
                        className="flex-1 rounded-xl border border-[var(--app-border)] px-3 py-2 text-xs font-medium hover:bg-[var(--app-surface-soft)] disabled:opacity-50"
                    >
                        Plus tard
                    </button>
                    <button
                        type="button"
                        onClick={handleActivate}
                        disabled={isLoading}
                        className="flex-1 rounded-xl bg-[#F1BF0C] px-3 py-2 text-xs font-semibold text-black hover:brightness-95 disabled:opacity-50"
                    >
                        {isLoading ? 'Activation…' : 'Activer'}
                    </button>
                </div>
            </div>
        </div>
    );
}
