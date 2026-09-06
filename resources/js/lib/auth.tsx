import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { api, ensureCsrfCookie, installAuthRedirect, toApiError } from './api';
import { disconnectEcho } from './echo';
import type { User } from '@/types';

/**
 * Who is signed in, and what they may do.
 *
 * Permissions are mirrored here so the UI can hide controls a user cannot use.
 * That is a courtesy, not a control: every one of them is enforced again by a
 * policy on the server, and nothing here is trusted for authorisation.
 */

interface AuthState {
    user: User | null;
    permissions: Set<string>;
    /** The UUID of the tenant a SuperAdmin has switched into, if any. */
    impersonatedTenantId: string | null;
    loading: boolean;
    /** Set when a password was accepted and a TOTP code is still needed. */
    awaitingTwoFactor: boolean;
}

interface AuthContextValue extends AuthState {
    can(permission: string): boolean;
    login(email: string, password: string, remember: boolean): Promise<void>;
    submitTwoFactor(code: string): Promise<void>;
    logout(): Promise<void>;
    refresh(): Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [state, setState] = useState<AuthState>({
        user: null,
        permissions: new Set(),
        impersonatedTenantId: null,
        loading: true,
        awaitingTwoFactor: false,
    });

    const refresh = useCallback(async () => {
        try {
            const { data } = await api.get('/me');

            setState({
                user: data.user,
                permissions: new Set<string>(data.permissions ?? []),
                impersonatedTenantId: data.impersonated_tenant_id ?? null,
                loading: false,
                awaitingTwoFactor: false,
            });
        } catch {
            // Not signed in is the normal case on first load, not an error.
            setState({
                user: null,
                permissions: new Set(),
                impersonatedTenantId: null,
                loading: false,
                awaitingTwoFactor: false,
            });
        }
    }, []);

    useEffect(() => {
        installAuthRedirect(() => {
            disconnectEcho();
            setState((previous) => ({
                ...previous,
                user: null,
                permissions: new Set(),
                loading: false,
            }));
        });

        void refresh();
    }, [refresh]);

    const login = useCallback(
        async (email: string, password: string, remember: boolean) => {
            await ensureCsrfCookie();

            try {
                const { data } = await api.post('/login', {
                    email,
                    password,
                    remember,
                });

                if (data.two_factor_required) {
                    // The session is not authenticated yet — the server is
                    // holding a pending id until the code arrives.
                    setState((previous) => ({
                        ...previous,
                        awaitingTwoFactor: true,
                    }));

                    return;
                }

                await refresh();
            } catch (error) {
                throw toApiError(error);
            }
        },
        [refresh],
    );

    const submitTwoFactor = useCallback(
        async (code: string) => {
            try {
                await api.post('/two-factor-challenge', { code });
                await refresh();
            } catch (error) {
                throw toApiError(error);
            }
        },
        [refresh],
    );

    const logout = useCallback(async () => {
        try {
            await api.post('/logout');
        } finally {
            // The socket carries tenant data; it must not outlive the session
            // even if the sign-out request itself failed.
            disconnectEcho();
            setState({
                user: null,
                permissions: new Set(),
                impersonatedTenantId: null,
                loading: false,
                awaitingTwoFactor: false,
            });
        }
    }, []);

    const value = useMemo<AuthContextValue>(
        () => ({
            ...state,
            can: (permission: string) =>
                state.user?.is_super_admin === true ||
                state.permissions.has(permission),
            login,
            submitTwoFactor,
            logout,
            refresh,
        }),
        [state, login, submitTwoFactor, logout, refresh],
    );

    return <AuthContext value={value}>{children}</AuthContext>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);

    if (!context) {
        throw new Error('useAuth must be used inside an AuthProvider.');
    }

    return context;
}
