import axios, { AxiosError } from 'axios';

/**
 * The dashboard's HTTP client.
 *
 * Sanctum SPA session auth: the browser holds an httpOnly session cookie and
 * axios echoes the XSRF cookie back as a header. No token is ever stored in
 * JavaScript, which is the point — an XSS on this page cannot walk away with a
 * credential that opens doors.
 */
export const api = axios.create({
    baseURL: '/api/admin/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Sanctum issues the XSRF cookie from this endpoint. Called once before the
 * first mutating request of a session.
 */
let csrfPromise: Promise<unknown> | null = null;

export function ensureCsrfCookie(): Promise<unknown> {
    csrfPromise ??= axios.get('/sanctum/csrf-cookie', { withCredentials: true });

    return csrfPromise;
}

export interface ApiErrorBody {
    error?: { code: string; message: string; fields?: Record<string, string[]> };
    message?: string;
    errors?: Record<string, string[]>;
}

/**
 * A failure in a form the UI can act on, rather than an axios object every
 * call site has to unpick.
 */
export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly code: string,
        readonly fields: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** The first message for one field, for inline form errors. */
    fieldError(name: string): string | undefined {
        return this.fields[name]?.[0];
    }
}

export function toApiError(error: unknown): ApiError {
    if (error instanceof ApiError) {
        return error;
    }

    const axiosError = error as AxiosError<ApiErrorBody>;
    const body = axiosError.response?.data;
    const status = axiosError.response?.status ?? 0;

    // The device API and the admin API share one error envelope; Laravel's own
    // validation responses use another. Both arrive here.
    const message =
        body?.error?.message ??
        body?.message ??
        (status === 0
            ? 'The server could not be reached.'
            : 'Something went wrong.');

    return new ApiError(
        message,
        status,
        body?.error?.code ?? (status === 422 ? 'validation_failed' : 'error'),
        body?.error?.fields ?? body?.errors ?? {},
    );
}

/**
 * A 401 anywhere means the session is gone. Rather than letting every screen
 * handle that, the app is sent back to sign-in — except on the sign-in call
 * itself, where a 401 is just a wrong password.
 */
export function installAuthRedirect(onUnauthenticated: () => void): void {
    api.interceptors.response.use(
        (response) => response,
        (error: AxiosError) => {
            const url = error.config?.url ?? '';
            const isAuthCall =
                url.includes('/login') || url.includes('/two-factor-challenge');

            if (error.response?.status === 401 && !isAuthCall) {
                onUnauthenticated();
            }

            return Promise.reject(error);
        },
    );
}
