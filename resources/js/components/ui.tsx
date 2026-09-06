import type {
    ButtonHTMLAttributes,
    CSSProperties,
    InputHTMLAttributes,
    ReactNode,
    SelectHTMLAttributes,
} from 'react';

/**
 * Interface primitives.
 *
 * The one rule running through all of them: status is never carried by colour
 * alone. Every badge pairs a colour with a glyph and a word, so the screen
 * still reads correctly in greyscale, at a glance, by someone who cannot
 * separate red from green.
 */

export type Tone = 'ok' | 'danger' | 'warn' | 'neutral' | 'accent';

const TONE_VARS: Record<Tone, { bg: string; fg: string; border: string }> = {
    ok: { bg: 'var(--ok-soft)', fg: 'var(--ok-text)', border: 'var(--ok)' },
    danger: { bg: 'var(--danger-soft)', fg: 'var(--danger-text)', border: 'var(--danger)' },
    warn: { bg: 'var(--warn-soft)', fg: 'var(--warn-text)', border: 'var(--warn)' },
    neutral: { bg: 'var(--neutral-soft)', fg: 'var(--neutral-text)', border: 'var(--neutral)' },
    accent: { bg: 'var(--accent-soft)', fg: 'var(--accent)', border: 'var(--accent)' },
};

export function Badge({
    tone = 'neutral',
    glyph,
    children,
}: {
    tone?: Tone;
    /** A shape, so the badge survives greyscale and colour blindness. */
    glyph?: string;
    children: ReactNode;
}) {
    const vars = TONE_VARS[tone];

    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
            style={{
                background: vars.bg,
                color: vars.fg,
                borderColor: vars.border,
            }}
        >
            {glyph && (
                <span aria-hidden="true" className="text-[0.7em] leading-none">
                    {glyph}
                </span>
            )}
            {children}
        </span>
    );
}

export function Button({
    variant = 'secondary',
    loading = false,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    loading?: boolean;
}) {
    const base =
        'inline-flex items-center justify-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-55';

    const styles: Record<string, React.CSSProperties> = {
        primary: { background: 'var(--accent)', color: 'var(--accent-text)' },
        secondary: {
            background: 'var(--surface-raised)',
            color: 'var(--text)',
            border: '1px solid var(--border-strong)',
        },
        danger: { background: 'var(--danger)', color: 'white' },
        ghost: { background: 'transparent', color: 'var(--text-muted)' },
    };

    return (
        <button
            {...props}
            className={`${base} ${props.className ?? ''}`}
            style={{ ...styles[variant], ...props.style }}
            disabled={props.disabled || loading}
            aria-busy={loading || undefined}
        >
            {loading && <Spinner />}
            {children}
        </button>
    );
}

export function Spinner({ label }: { label?: string }) {
    return (
        <span
            className="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
            role={label ? 'status' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : 'true'}
        />
    );
}

export function Field({
    label,
    error,
    hint,
    children,
    id,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
    id: string;
}) {
    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                {label}
            </label>
            {children}
            {hint && !error && (
                <p className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                    {hint}
                </p>
            )}
            {error && (
                // Announced to screen readers as it appears, not only shown.
                <p role="alert" className="text-xs font-medium" style={{ color: 'var(--danger-text)' }}>
                    {error}
                </p>
            )}
        </div>
    );
}

const controlClass =
    'w-full rounded-lg px-3 py-1.5 text-sm outline-none transition-colors';

const controlStyle: React.CSSProperties = {
    background: 'var(--surface)',
    color: 'var(--text)',
    border: '1px solid var(--border-strong)',
};

export function Input(props: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            className={`${controlClass} ${props.className ?? ''}`}
            style={{ ...controlStyle, ...props.style }}
        />
    );
}

export function Select(props: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            {...props}
            className={`${controlClass} ${props.className ?? ''}`}
            style={{ ...controlStyle, ...props.style }}
        />
    );
}

export function Panel({
    title,
    description,
    actions,
    children,
    className = '',
}: {
    title?: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={`panel ${className}`}>
            {(title || actions) && (
                <header
                    className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3"
                    style={{ borderColor: 'var(--border)' }}
                >
                    <div>
                        {title && <h2 className="text-sm font-semibold">{title}</h2>}
                        {description && (
                            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                {description}
                            </p>
                        )}
                    </div>
                    {actions && <div className="flex items-center gap-2">{actions}</div>}
                </header>
            )}
            {children}
        </section>
    );
}

/**
 * An empty state that says what to do, not just that there is nothing here.
 */
export function EmptyState({
    title,
    description,
    action,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
            <p className="text-sm font-medium">{title}</p>
            {description && (
                <p className="max-w-sm text-xs" style={{ color: 'var(--text-muted)' }}>
                    {description}
                </p>
            )}
            {action}
        </div>
    );
}

export function ErrorState({ message, retry }: { message: string; retry?: () => void }) {
    return (
        <div role="alert" className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <Badge tone="danger" glyph="✕">
                Failed to load
            </Badge>
            <p className="max-w-md text-xs" style={{ color: 'var(--text-muted)' }}>
                {message}
            </p>
            {retry && (
                <Button onClick={retry} variant="secondary">
                    Try again
                </Button>
            )}
        </div>
    );
}

export function LoadingRows({ rows = 5 }: { rows?: number }) {
    return (
        <div className="flex flex-col gap-2 p-4" aria-hidden="true">
            {Array.from({ length: rows }, (_, index) => (
                <div
                    key={index}
                    className="h-8 animate-pulse rounded"
                    style={{ background: 'var(--surface-sunken)' }}
                />
            ))}
        </div>
    );
}

/**
 * A scroll container for wide tables. The page body must never scroll
 * sideways; the table does.
 */
export function TableWrap({ children }: { children: ReactNode }) {
    return <div className="overflow-x-auto">{children}</div>;
}

export function Th({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <th
            scope="col"
            className={`px-4 py-2 text-left text-xs font-semibold whitespace-nowrap ${className}`}
            style={{ color: 'var(--text-muted)' }}
        >
            {children}
        </th>
    );
}

export function Td({
    children,
    className = '',
    style,
    colSpan,
}: {
    children: ReactNode;
    className?: string;
    style?: CSSProperties;
    colSpan?: number;
}) {
    return (
        <td className={`px-4 py-2 align-middle ${className}`} style={style} colSpan={colSpan}>
            {children}
        </td>
    );
}
