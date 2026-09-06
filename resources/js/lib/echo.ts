import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { config, realtimeAvailable } from './config';

/**
 * The live feed.
 *
 * Channels are private and tenant-addressed; authorisation happens server-side
 * in routes/channels.php against the operator's session, so subscribing to
 * another tenant's feed fails there rather than being prevented here.
 *
 * Connection is lazy: a dashboard that nobody has signed into does not need a
 * websocket, and the auth endpoint would reject it anyway.
 */

let echo: Echo<'reverb'> | null = null;

export function getEcho(): Echo<'reverb'> | null {
    if (!realtimeAvailable) {
        return null;
    }

    if (echo) {
        return echo;
    }

    // laravel-echo's reverb broadcaster is pusher-protocol underneath.
    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

    echo = new Echo({
        broadcaster: 'reverb',
        key: config.reverb.key!,
        wsHost: config.reverb.host,
        wsPort: config.reverb.port,
        wssPort: config.reverb.port,
        forceTLS: config.reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // Same-origin, so the session cookie authorises the subscription.
        authEndpoint: '/broadcasting/auth',
        withCredentials: true,
    });

    return echo;
}

/** Called on sign-out: the socket carries tenant data and must not outlive the session. */
export function disconnectEcho(): void {
    echo?.disconnect();
    echo = null;
}
