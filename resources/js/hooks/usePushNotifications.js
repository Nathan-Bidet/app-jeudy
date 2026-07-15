import { usePage } from '@inertiajs/react';
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

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function apiPost(url, data) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(data),
    });

    if (!response.ok) {
        const text = await response.text().catch(() => '');
        throw new Error(`HTTP ${response.status}${text ? `: ${text}` : ''}`);
    }

    return response;
}

async function apiDelete(url, data) {
    const response = await fetch(url, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(data),
    });

    if (!response.ok && response.status !== 204) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response;
}

export function usePushNotifications() {
    const { vapid_public_key: vapidPublicKey } = usePage().props;
    const [permission, setPermission] = useState(() =>
        typeof Notification !== 'undefined' ? Notification.permission : 'default'
    );
    const [isSubscribed, setIsSubscribed] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!isPushSupported() || !vapidPublicKey) return;

        navigator.serviceWorker.ready
            .then((registration) => registration.pushManager.getSubscription())
            .then((subscription) => setIsSubscribed(!!subscription))
            .catch(() => {});
    }, [vapidPublicKey]);

    const subscribe = useCallback(async () => {
        if (!isPushSupported() || !vapidPublicKey) return false;

        setIsLoading(true);
        setError(null);

        try {
            const registration = await navigator.serviceWorker.ready;
            const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey,
            });

            const newPermission = Notification.permission;
            setPermission(newPermission);

            if (newPermission !== 'granted') {
                return false;
            }

            const json = subscription.toJSON();

            await apiPost(route('push.subscribe'), {
                endpoint: json.endpoint,
                p256dh: json.keys?.p256dh ?? '',
                auth: json.keys?.auth ?? '',
                content_encoding: 'aesgcm',
            });

            setIsSubscribed(true);
            return true;
        } catch (err) {
            setPermission(typeof Notification !== 'undefined' ? Notification.permission : 'default');

            if (err.name === 'NotAllowedError') {
                // permission refusée par l'utilisateur — pas d'erreur affichée
            } else {
                setError('Impossible d\'activer les notifications. Veuillez réessayer.');
            }

            return false;
        } finally {
            setIsLoading(false);
        }
    }, [vapidPublicKey]);

    const unsubscribe = useCallback(async () => {
        if (!isPushSupported()) return;

        setIsLoading(true);
        setError(null);

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (!subscription) {
                setIsSubscribed(false);
                return;
            }

            const endpoint = subscription.endpoint;
            await subscription.unsubscribe();

            await apiDelete(route('push.unsubscribe'), { endpoint });

            setIsSubscribed(false);
        } catch (err) {
            setError('Impossible de désactiver les notifications. Veuillez réessayer.');
        } finally {
            setIsLoading(false);
        }
    }, []);

    return {
        isSupported: isPushSupported() && !!vapidPublicKey,
        permission,
        isSubscribed,
        isLoading,
        error,
        subscribe,
        unsubscribe,
    };
}
