import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const rawAppKey = String(import.meta.env.VITE_REVERB_APP_KEY ?? '').trim();
const appKey = rawAppKey.replace(/^\/?app\//, '');

if (typeof window !== 'undefined' && appKey) {
    const forceTLS = window.location.protocol === 'https:';
    const host = window.location.hostname;
    const port = window.location.port
        ? Number(window.location.port)
        : (forceTLS ? 443 : 80);
    const enabledTransports = forceTLS ? ['wss'] : ['ws'];

    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: appKey,
        cluster: '',
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS,
        disableStats: true,
        enabledTransports,
    });
}
