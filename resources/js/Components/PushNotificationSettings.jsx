import { usePushNotifications } from '@/hooks/usePushNotifications';
import { Bell, BellOff, Smartphone } from 'lucide-react';

export default function PushNotificationSettings({ className = '' }) {
    const { isSupported, permission, isSubscribed, isLoading, subscribe, unsubscribe } = usePushNotifications();

    if (!isSupported) {
        return (
            <section className={className}>
                <header className="mb-4">
                    <h2 className="text-base font-semibold">Notifications Push</h2>
                    <p className="mt-1 text-sm text-[var(--app-muted)]">
                        Les notifications push ne sont pas disponibles sur cet appareil ou navigateur.
                    </p>
                </header>
            </section>
        );
    }

    const isBlocked = permission === 'denied';

    return (
        <section className={className}>
            <header className="mb-4">
                <h2 className="text-base font-semibold">Notifications Push</h2>
                <p className="mt-1 text-sm text-[var(--app-muted)]">
                    Recevez des notifications sur cet appareil (demandes de congé, etc.).
                </p>
            </header>

            {isBlocked ? (
                <div className="flex items-start gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                    <BellOff className="mt-0.5 h-4 w-4 shrink-0 text-[var(--app-muted)]" strokeWidth={2} />
                    <p className="text-sm text-[var(--app-muted)]">
                        Les notifications sont bloquées par le navigateur. Pour les activer, modifiez les autorisations du site dans les paramètres de votre navigateur.
                    </p>
                </div>
            ) : (
                <div className="flex items-center justify-between gap-4 rounded-xl border border-[var(--app-border)] p-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--app-surface-soft)]">
                            <Smartphone className="h-4 w-4" strokeWidth={2} />
                        </div>
                        <div>
                            <p className="text-sm font-medium">Cet appareil</p>
                            <p className="text-xs text-[var(--app-muted)]">
                                {isSubscribed ? 'Notifications activées' : 'Notifications désactivées'}
                            </p>
                        </div>
                    </div>

                    {isSubscribed ? (
                        <button
                            type="button"
                            onClick={unsubscribe}
                            disabled={isLoading}
                            className="flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--app-surface-soft)] disabled:opacity-50"
                        >
                            <BellOff className="h-3.5 w-3.5" />
                            Désactiver
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={subscribe}
                            disabled={isLoading}
                            className="flex items-center gap-1.5 rounded-xl bg-[#F1BF0C] px-3 py-1.5 text-xs font-semibold text-black hover:brightness-95 disabled:opacity-50"
                        >
                            <Bell className="h-3.5 w-3.5" />
                            {isLoading ? 'Activation…' : 'Activer'}
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}
