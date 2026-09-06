import { useEffect, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { config, realtimeAvailable } from '@/lib/config';
import { Badge, Button } from './ui';
import { TenantSwitcher } from './TenantSwitcher';

interface NavItem {
    to: string;
    label: string;
    glyph: string;
    /** Hidden when the operator lacks this permission. */
    permission?: string;
}

const NAV: NavItem[] = [
    { to: '/', label: 'Dashboard', glyph: '▤' },
    { to: '/devices', label: 'Devices', glyph: '▣', permission: 'device.view' },
    { to: '/members', label: 'Members', glyph: '☺', permission: 'member.view' },
    { to: '/events', label: 'Access events', glyph: '≡', permission: 'event.view' },
    { to: '/backup-codes', label: 'Backup codes', glyph: '⌨', permission: 'backupcode.view' },
    { to: '/schedules', label: 'Schedules', glyph: '◷', permission: 'schedule.view' },
    { to: '/users', label: 'Users & roles', glyph: '⚿', permission: 'user.view' },
    { to: '/webhooks', label: 'Webhooks', glyph: '⇄', permission: 'webhook.manage' },
    { to: '/audit', label: 'Audit log', glyph: '✎', permission: 'audit.view' },
];

export function Layout() {
    const { user, can, logout } = useAuth();
    const navigate = useNavigate();
    const [menuOpen, setMenuOpen] = useState(false);

    // Navigating on a phone should close the menu it was opened from.
    useEffect(() => {
        const close = () => setMenuOpen(false);
        window.addEventListener('popstate', close);

        return () => window.removeEventListener('popstate', close);
    }, []);

    const visible = NAV.filter((item) => !item.permission || can(item.permission));

    return (
        <div className="flex min-h-full flex-col lg:flex-row">
            <a href="#main" className="skip-link">
                Skip to main content
            </a>

            {/* ---------------------------------------------------------- */}
            {/* Sidebar                                                     */}
            {/* ---------------------------------------------------------- */}
            <header
                className="flex shrink-0 flex-col border-b lg:h-screen lg:w-60 lg:border-r lg:border-b-0"
                style={{ background: 'var(--surface)', borderColor: 'var(--border)' }}
            >
                <div className="flex items-center justify-between gap-2 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <span
                            aria-hidden="true"
                            className="flex h-7 w-7 items-center justify-center rounded-lg text-sm"
                            style={{ background: 'var(--accent)', color: 'var(--accent-text)' }}
                        >
                            ⌾
                        </span>
                        <span className="text-sm font-semibold">{config.appName}</span>
                    </div>

                    <button
                        type="button"
                        className="rounded-lg px-2 py-1 text-lg lg:hidden"
                        aria-expanded={menuOpen}
                        aria-controls="primary-navigation"
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        <span aria-hidden="true">☰</span>
                        <span className="sr-only">
                            {menuOpen ? 'Close navigation' : 'Open navigation'}
                        </span>
                    </button>
                </div>

                <nav
                    id="primary-navigation"
                    aria-label="Primary"
                    className={`${menuOpen ? 'flex' : 'hidden'} flex-1 flex-col gap-0.5 overflow-y-auto px-2 pb-3 lg:flex`}
                >
                    {visible.map((item) => (
                        <NavLink
                            key={item.to}
                            to={item.to}
                            end={item.to === '/'}
                            onClick={() => setMenuOpen(false)}
                            className="flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm"
                            style={({ isActive }) => ({
                                background: isActive ? 'var(--accent-soft)' : 'transparent',
                                color: isActive ? 'var(--accent)' : 'var(--text-muted)',
                                fontWeight: isActive ? 600 : 400,
                            })}
                        >
                            <span aria-hidden="true" className="w-4 text-center">
                                {item.glyph}
                            </span>
                            {item.label}
                        </NavLink>
                    ))}
                </nav>

                <div
                    className={`${menuOpen ? 'block' : 'hidden'} border-t px-4 py-3 lg:block`}
                    style={{ borderColor: 'var(--border)' }}
                >
                    <TenantSwitcher />

                    <div className="mt-3 flex items-center justify-between gap-2">
                        <div className="min-w-0">
                            <p className="truncate text-xs font-medium">{user?.name}</p>
                            <p className="truncate text-xs" style={{ color: 'var(--text-subtle)' }}>
                                {user?.roles?.join(', ') ?? user?.email}
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            onClick={async () => {
                                await logout();
                                navigate('/login');
                            }}
                        >
                            Sign out
                        </Button>
                    </div>

                    {!realtimeAvailable && (
                        <p className="mt-2 text-xs" style={{ color: 'var(--text-subtle)' }}>
                            Live updates are off — no websocket configured.
                        </p>
                    )}

                    {user && !user.two_factor_enabled && (
                        <NavLink to="/security" className="mt-2 inline-block">
                            <Badge tone="warn" glyph="!">
                                Two-factor not set up
                            </Badge>
                        </NavLink>
                    )}
                </div>
            </header>

            {/* ---------------------------------------------------------- */}
            {/* Main                                                        */}
            {/* ---------------------------------------------------------- */}
            <main id="main" className="min-w-0 flex-1 overflow-y-auto p-4 lg:h-screen lg:p-6">
                <Outlet />
            </main>
        </div>
    );
}
