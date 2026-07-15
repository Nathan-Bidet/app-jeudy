import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const output = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
        output[i] = rawData.charCodeAt(i);
    }
    return output;
}

function isPushSupported() {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

export function usePushNotifications() {
    const { vapid_public_key: vapidPublicKey } = usePage().props;
    const [permission, setPermission] = useState(() =>
        typeof Notification !== 'undefined' ? Notification.permission : 'default'
    );
    const [isSubscribed, setIsSubscribed] = useState(false);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (!isPushSupported() || !vapidPublicKey) return;

        navigator.serviceWorker.ready.then((registration) => {
            return registration.pushManager.getSubscription();
        }).then((subscription) => {
            setIsSubscribed(!!subscription);
        }).catch(() => {});
    }, [vapidPublicKey]);

    const subscribe = useCallback(async () => {
        if (!isPushSupported() || !vapidPublicKey) return false;

        setIsLoading(true);
        try {
            const registration = await navigator.serviceWorker.ready;
            const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey,
            });

            setPermission(Notification.permission);

            if (Notification.permission !== 'granted') {
                setIsLoading(false);
                return false;
            }

            const json = subscription.toJSON();

            await new Promise((resolve, reject) => {
                router.post(
                    route('push.subscribe'),
                    {
                        endpoint: json.endpoint,
                        p256dh: json.keys?.p256dh ?? '',
                        auth: json.keys?.auth ?? '',
                        content_encoding: 'aesgcm',
                    },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        onSuccess: () => resolve(),
                        onError: () => reject(new Error('Subscribe failed')),
                    }
                );
            });

            setIsSubscribed(true);
            return true;
        } catch (err) {
            setPermission(typeof Notification !== 'undefined' ? Notification.permission : 'default');
            return false;
        } finally {
            setIsLoading(false);
        }
    }, [vapidPublicKey]);

    const unsubscribe = useCallback(async () => {
        if (!isPushSupported()) return;

        setIsLoading(true);
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (!subscription) {
                setIsSubscribed(false);
                return;
            }

            const endpoint = subscription.endpoint;
            await subscription.unsubscribe();

            await new Promise((resolve) => {
                router.delete(
                    route('push.unsubscribe'),
                    {
                        data: { endpoint },
                        preserveState: true,
                        preserveScroll: true,
                        onFinish: () => resolve(),
                    }
                );
            });

            setIsSubscribed(false);
        } catch (err) {
            // ignore
        } finally {
            setIsLoading(false);
        }
    }, []);

    return {
        isSupported: isPushSupported() && !!vapidPublicKey,
        permission,
        isSubscribed,
        isLoading,
        subscribe,
        unsubscribe,
    };
}
