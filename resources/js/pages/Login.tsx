import { useState, type FormEvent } from 'react';
import { useAuth } from '@/lib/auth';
import { ApiError } from '@/lib/api';
import { config } from '@/lib/config';
import { Button, Field, Input, Panel } from '@/components/ui';

/**
 * Sign-in, in two legs.
 *
 * A correct password does not authenticate the session when the account
 * carries a second factor — the server holds a pending id and waits for the
 * code. Nothing in this component decides that; it only renders whichever leg
 * the server says it is on.
 */
export function LoginPage() {
    const { login, submitTwoFactor, awaitingTwoFactor } = useAuth();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [code, setCode] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setError(null);
        setBusy(true);

        try {
            if (awaitingTwoFactor) {
                await submitTwoFactor(code.trim());
            } else {
                await login(email, password, remember);
            }
        } catch (caught) {
            setError(caught as ApiError);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="flex min-h-full items-center justify-center p-4">
            <div className="w-full max-w-sm">
                <div className="mb-5 flex items-center gap-2">
                    <span
                        aria-hidden="true"
                        className="flex h-8 w-8 items-center justify-center rounded-lg"
                        style={{ background: 'var(--accent)', color: 'var(--accent-text)' }}
                    >
                        ⌾
                    </span>
                    <h1 className="text-base font-semibold">{config.appName}</h1>
                </div>

                <Panel className="p-5">
                    <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                        {awaitingTwoFactor ? (
                            <>
                                <div>
                                    <h2 className="text-sm font-semibold">Two-factor code</h2>
                                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                        Enter the six-digit code from your authenticator app, or one
                                        of your recovery codes.
                                    </p>
                                </div>

                                <Field
                                    id="code"
                                    label="Authentication code"
                                    error={error?.fieldError('code') ?? undefined}
                                >
                                    <Input
                                        id="code"
                                        name="one-time-code"
                                        autoComplete="one-time-code"
                                        inputMode="numeric"
                                        autoFocus
                                        required
                                        value={code}
                                        onChange={(event) => setCode(event.target.value)}
                                    />
                                </Field>
                            </>
                        ) : (
                            <>
                                <div>
                                    <h2 className="text-sm font-semibold">Sign in</h2>
                                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                        Access control management.
                                    </p>
                                </div>

                                <Field
                                    id="email"
                                    label="Email"
                                    error={error?.fieldError('email') ?? undefined}
                                >
                                    <Input
                                        id="email"
                                        type="email"
                                        autoComplete="username"
                                        autoFocus
                                        required
                                        value={email}
                                        onChange={(event) => setEmail(event.target.value)}
                                    />
                                </Field>

                                <Field
                                    id="password"
                                    label="Password"
                                    error={error?.fieldError('password') ?? undefined}
                                >
                                    <Input
                                        id="password"
                                        type="password"
                                        autoComplete="current-password"
                                        required
                                        value={password}
                                        onChange={(event) => setPassword(event.target.value)}
                                    />
                                </Field>

                                <label className="flex items-center gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        checked={remember}
                                        onChange={(event) => setRemember(event.target.checked)}
                                    />
                                    Keep me signed in on this device
                                </label>
                            </>
                        )}

                        {/* A failure with no field attached still has to be
                            announced, not just styled. */}
                        {error && Object.keys(error.fields).length === 0 && (
                            <p role="alert" className="text-xs" style={{ color: 'var(--danger-text)' }}>
                                {error.message}
                            </p>
                        )}

                        <Button type="submit" variant="primary" loading={busy}>
                            {awaitingTwoFactor ? 'Verify' : 'Sign in'}
                        </Button>
                    </form>
                </Panel>
            </div>
        </div>
    );
}
