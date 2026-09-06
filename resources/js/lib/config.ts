/**
 * Runtime configuration, delivered through the served document rather than
 * baked into the bundle.
 *
 * This is what lets one production image be deployed against any host: the
 * websocket endpoint differs per deployment, and rebuilding the front-end to
 * change a hostname is not a deployment story anybody wants.
 */
export interface RuntimeConfig {
    appName: string;
    reverb: {
        key: string | null;
        host: string;
        port: number;
        scheme: 'http' | 'https';
    };
}

declare global {
    interface Window {
        __ACCESS_CONFIG__?: RuntimeConfig;
    }
}

const fallback: RuntimeConfig = {
    appName: 'Smart Access Control',
    reverb: {
        key: null,
        host: window.location.hostname,
        port: 8080,
        scheme: window.location.protocol === 'https:' ? 'https' : 'http',
    },
};

export const config: RuntimeConfig = window.__ACCESS_CONFIG__ ?? fallback;

/**
 * Realtime is optional. Without a Reverb key the dashboard still works — it
 * just polls instead of streaming, and says so.
 */
export const realtimeAvailable = Boolean(config.reverb.key);
