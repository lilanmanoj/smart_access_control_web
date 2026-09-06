import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { api, ensureCsrfCookie, toApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { Badge, Button, Field, Input, Panel } from '@/components/ui';

/**
 * Two-factor enrolment.
 *
 * Required for any role that can manage devices or members — anyone who can
 * open a door, or change who may. Those roles reach this page and nothing else
 * until they finish.
 */
export function SecurityPage() {
    const { user, refresh } = useAuth();
    const [enrollment, setEnrollment] = useState<{
        secret: string;
        qr_svg: string;
    } | null>(null);
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);

    const begin = useMutation({
        mutationFn: async () => {
            await ensureCsrfCookie();
            const { data } = await api.post('/two-factor/enroll');

            return data as { secret: string; qr_svg: string };
        },
        onSuccess: setEnrollment,
    });

    const confirm = useMutation({
        mutationFn: async () => {
            const { data } = await api.post('/two-factor/confirm', { code: code.trim() });

            return data.recovery_codes as string[];
        },
        onSuccess: async (codes) => {
            setRecoveryCodes(codes);
            setEnrollment(null);
            setCode('');
            await refresh();
        },
    });

    return (
        <div className="flex max-w-2xl flex-col gap-4">
            <header>
                <h1 className="text-lg font-semibold">Security</h1>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    Two-factor authentication for your own account.
                </p>
            </header>

            <Panel
                title="Two-factor authentication"
                actions={
                    user?.two_factor_enabled ? (
                        <Badge tone="ok" glyph="✓">
                            Enabled
                        </Badge>
                    ) : user?.two_factor_required ? (
                        <Badge tone="danger" glyph="!">
                            Required by your role
                        </Badge>
                    ) : (
                        <Badge tone="neutral" glyph="—">
                            Not enabled
                        </Badge>
                    )
                }
                className="p-4"
            >
                {user?.two_factor_enabled ? (
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        Your account asks for a code from your authenticator app at every sign-in.
                        {user.two_factor_required &&
                            ' Your role requires it, so it cannot be turned off.'}
                    </p>
                ) : !enrollment ? (
                    <div className="flex flex-col gap-3">
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            {user?.two_factor_required
                                ? 'Your role can open doors and change who may enter, so it requires a second factor. Until you finish, the rest of the dashboard is closed to you.'
                                : 'Add a second factor to your sign-in.'}
                        </p>
                        <div>
                            <Button
                                variant="primary"
                                loading={begin.isPending}
                                onClick={() => begin.mutate()}
                            >
                                Set up two-factor
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col gap-4">
                        <ol className="flex flex-col gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                            <li>1. Scan this code with your authenticator app.</li>
                            <li>2. Enter the six-digit code it shows.</li>
                        </ol>

                        <div className="flex flex-wrap items-start gap-4">
                            {/* The QR is rendered server-side as SVG, so it
                                needs no image extension in the container and
                                stays sharp at any zoom. */}
                            <div
                                className="rounded-lg bg-white p-2"
                                aria-label="Two-factor setup QR code"
                                dangerouslySetInnerHTML={{ __html: enrollment.qr_svg }}
                            />

                            <div className="flex flex-col gap-2">
                                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                    Cannot scan? Enter this key by hand:
                                </p>
                                <code
                                    className="rounded p-2 font-mono text-xs break-all"
                                    style={{ background: 'var(--surface-sunken)' }}
                                >
                                    {enrollment.secret}
                                </code>
                            </div>
                        </div>

                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                confirm.mutate();
                            }}
                        >
                            <Field
                                id="totp"
                                label="Code from your app"
                                error={
                                    confirm.error
                                        ? (toApiError(confirm.error).fieldError('code') ??
                                          toApiError(confirm.error).message)
                                        : undefined
                                }
                            >
                                <Input
                                    id="totp"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    required
                                    className="w-40"
                                    value={code}
                                    onChange={(event) => setCode(event.target.value)}
                                />
                            </Field>

                            <Button type="submit" variant="primary" loading={confirm.isPending}>
                                Confirm
                            </Button>
                        </form>
                    </div>
                )}
            </Panel>

            {recoveryCodes && (
                <Panel
                    title="Recovery codes"
                    className="p-4"
                    description="Each works once, if you lose your authenticator. They are shown here and nowhere else."
                >
                    <ul className="grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">
                        {recoveryCodes.map((recoveryCode) => (
                            <li
                                key={recoveryCode}
                                className="rounded px-2 py-1"
                                style={{ background: 'var(--surface-sunken)' }}
                            >
                                {recoveryCode}
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </div>
    );
}
