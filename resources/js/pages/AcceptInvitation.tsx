import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { config } from '@/lib/config';
import { Button, Field, Input, Panel } from '@/components/ui';

/**
 * Where the invitation e-mail lands. The link carries a token and the
 * invitee's address; they choose a password here and then sign in normally,
 * which is also where a role that needs a second factor sets one up.
 */
export function AcceptInvitationPage() {
    const { user, logout } = useAuth();
    const [params] = useSearchParams();
    const token = params.get('token') ?? '';
    const email = params.get('email') ?? '';

    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setError(null);
        setBusy(true);

        try {
            await ensureCsrfCookie();
            await api.post('/invitation-acceptances', {
                email,
                token,
                password,
                password_confirmation: confirmation,
            });
            setDone(true);
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    const linkIsIncomplete = token === '' || email === '';

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
                    {user ? (
                        <div className="flex flex-col gap-3 text-xs">
                            <h2 className="text-sm font-semibold">Accept invitation</h2>
                            <p style={{ color: 'var(--text-muted)' }}>
                                You are signed in as {user.email}. Sign out first, then open the
                                invitation link again.
                            </p>
                            <div>
                                <Button onClick={() => void logout()}>Sign out</Button>
                            </div>
                        </div>
                    ) : done ? (
                        <div className="flex flex-col gap-3 text-xs">
                            <h2 className="text-sm font-semibold">Password set</h2>
                            <p role="status" style={{ color: 'var(--text-muted)' }}>
                                Your account is ready. Sign in with {email} and your new password.
                            </p>
                            <Link to="/login" className="underline underline-offset-2">
                                Go to sign in
                            </Link>
                        </div>
                    ) : linkIsIncomplete ? (
                        <div className="flex flex-col gap-3 text-xs">
                            <h2 className="text-sm font-semibold">Accept invitation</h2>
                            <p role="alert" style={{ color: 'var(--danger-text)' }}>
                                This link is incomplete. Open it straight from the invitation
                                e-mail, or ask an administrator to send a new one.
                            </p>
                        </div>
                    ) : (
                        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                            <div>
                                <h2 className="text-sm font-semibold">Accept invitation</h2>
                                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                    Choose a password for {email}.
                                </p>
                            </div>

                            <Field
                                id="password"
                                label="New password"
                                hint="At least 12 characters."
                                error={error?.fieldError('password') ?? undefined}
                            >
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    autoFocus
                                    required
                                    minLength={12}
                                    value={password}
                                    onChange={(event) => setPassword(event.target.value)}
                                />
                            </Field>

                            <Field id="password_confirmation" label="Confirm password">
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                    value={confirmation}
                                    onChange={(event) => setConfirmation(event.target.value)}
                                />
                            </Field>

                            {error && !error.fieldError('password') && (
                                <p role="alert" className="text-xs" style={{ color: 'var(--danger-text)' }}>
                                    {error.message}
                                </p>
                            )}

                            <Button type="submit" variant="primary" loading={busy}>
                                Set password
                            </Button>
                        </form>
                    )}
                </Panel>
            </div>
        </div>
    );
}
