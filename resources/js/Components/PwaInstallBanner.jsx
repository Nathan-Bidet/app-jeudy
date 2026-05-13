import { useEffect, useMemo, useState } from 'react';

const STORAGE_KEY = 'jeudy_pwa_install_banner_dismissed';

function isStandaloneMode() {
    if (typeof window === 'undefined') {
        return false;
    }

    const mediaStandalone = window.matchMedia?.('(display-mode: standalone)')?.matches;
    const iosStandalone = window.navigator.standalone === true;

    return Boolean(mediaStandalone || iosStandalone);
}

function detectPlatform() {
    if (typeof window === 'undefined') {
        return { isMobile: false, isIOS: false, isAndroid: false };
    }

    const ua = window.navigator.userAgent || '';
    const maxTouchPoints = window.navigator.maxTouchPoints || 0;
    const isIOS = /iphone|ipad|ipod/i.test(ua) || (window.navigator.platform === 'MacIntel' && maxTouchPoints > 1);
    const isAndroid = /android/i.test(ua);
    const isMobileViewport = window.matchMedia?.('(max-width: 767px)')?.matches ?? false;
    const isCoarse = window.matchMedia?.('(pointer: coarse)')?.matches ?? false;
    const isMobile = isMobileViewport || isCoarse || isIOS || isAndroid;

    return { isMobile, isIOS, isAndroid };
}

export default function PwaInstallBanner() {
    const [ready, setReady] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const [deferredPrompt, setDeferredPrompt] = useState(null);

    const platform = useMemo(() => detectPlatform(), []);
    const canRenderBase = platform.isMobile && !isStandaloneMode() && !dismissed;

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const wasDismissed = window.localStorage.getItem(STORAGE_KEY) === 'true';
        setDismissed(wasDismissed);
        setReady(true);
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const onBeforeInstallPrompt = (event) => {
            event.preventDefault();
            setDeferredPrompt(event);
        };

        window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);

        return () => {
            window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
        };
    }, []);

    const dismissBanner = () => {
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(STORAGE_KEY, 'true');
        }
        setDismissed(true);
    };

    const handleInstall = async () => {
        if (!deferredPrompt) {
            dismissBanner();
            return;
        }

        await deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        setDeferredPrompt(null);
        dismissBanner();
    };

    if (!ready || !canRenderBase) {
        return null;
    }

    const showAndroidInstall = platform.isAndroid && deferredPrompt;
    const showIosInstructions = platform.isIOS;

    if (!showAndroidInstall && !showIosInstructions) {
        return null;
    }

    return (
        <div className="fixed inset-x-3 bottom-[calc(env(safe-area-inset-bottom)+84px)] z-[65] md:hidden">
            <div className="rounded-2xl border border-[#F1BF0C] bg-[var(--app-surface)]/95 p-3 shadow-xl backdrop-blur">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-sm font-bold text-[var(--app-text)]">Installer l&apos;application Jeudy</p>
                        {showAndroidInstall ? (
                            <p className="mt-1 text-xs text-[var(--app-muted)]">
                                Accédez rapidement à Jeudy depuis votre écran d&apos;accueil.
                            </p>
                        ) : (
                            <p className="mt-1 text-xs text-[var(--app-muted)]">
                                Safari iPhone: ••• / Partage → Sur l&apos;écran d&apos;accueil.
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={dismissBanner}
                        className="shrink-0 rounded-lg border border-[var(--app-border)] px-2 py-1 text-[11px] font-semibold text-[var(--app-muted)]"
                    >
                        Fermer
                    </button>
                </div>

                <div className="mt-3">
                    {showAndroidInstall ? (
                        <button
                            type="button"
                            onClick={handleInstall}
                            className="w-full rounded-xl bg-[#F1BF0C] px-3 py-2 text-sm font-semibold text-black hover:brightness-95"
                        >
                            Installer l&apos;application
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={dismissBanner}
                            className="w-full rounded-xl bg-[#F1BF0C] px-3 py-2 text-sm font-semibold text-black hover:brightness-95"
                        >
                            J&apos;ai compris
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
