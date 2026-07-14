import { Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * React port of the persistent navbar/sidebar shell (resources/views/components/navbar.blade.php
 * + resources/views/layouts/app.blade.php), used as an Inertia persistent layout so it survives
 * client-side page transitions instead of remounting per page (see inertia-app.jsx).
 *
 * Known v1 gaps, intentionally out of scope for this pass (mixing live Livewire components inside
 * a React tree is unsolved): team switching (read-only team name shown instead of
 * <livewire:switch-team/>), the rest of the settings dropdown beyond Logout, the upgrade banner,
 * delete-team modal trigger, and the help modal (<livewire:settings-dropdown/>, <livewire:upgrade/>,
 * <livewire:navbar-delete-team/>, <livewire:help/>). Logout itself (POST /logout, matching the
 * plain <form action="/logout" method="POST"> in navbar.blade.php) is ported below since its
 * absence otherwise leaves no way to log out from any converted page — deliberately a real
 * browser form submission, not router.post(): logout redirects through a plain (non-Inertia)
 * Blade login page, and Inertia's XHR-based navigation has no reliable way to hand off to a
 * full page it doesn't control the rendering of. A native form POST lets the browser's own
 * navigation follow the redirect chain (POST 302 -> GET 302 -> GET /login) with no SPA
 * involved at all, exactly like the original Livewire navbar did. Also no mobile hamburger drawer or
 * collapse/expand toggle yet — the sidebar is always expanded. Global search
 * (<livewire:global-search/>) and deployment status indicator (<livewire:deployments-indicator/>)
 * are omitted for the same reason.
 */
const NAV_ITEMS = [
    { label: 'Dashboard', href: '/', match: '/' },
    { label: 'Projects', href: '/projects', match: '/project' },
    { label: 'Servers', href: '/servers', match: '/server' },
    { label: 'Sources', href: '/source', match: '/source' },
    { label: 'Destinations', href: '/destinations', match: '/destination' },
    { label: 'S3 Storages', href: '/storages', match: '/storage' },
    { label: 'Shared Variables', href: '/shared-variables', match: '/shared-variables' },
    { label: 'Notifications', href: '/notifications/email', match: '/notifications' },
    { label: 'Keys & Tokens', href: '/security/private-key', match: '/security' },
    { label: 'Tags', href: '/tags', match: '/tags' },
    { label: 'Terminal', href: '/terminal', match: '/terminal', permission: 'canAccessTerminal' },
    { label: 'Profile', href: '/profile', match: '/profile' },
    { label: 'Teams', href: '/team', match: '/team' },
    { label: 'Settings', href: '/settings', match: '/settings' },
    { label: 'Admin', href: '/admin', match: '/admin', permission: 'isInstanceAdmin' },
];

export default function AppLayout({ children }) {
    const { props } = usePage();
    const { auth, currentTeam, permissions, flash } = props;

    useEffect(() => {
        if (!flash) return;
        ['success', 'error', 'warning', 'info'].forEach((type) => {
            const message = flash[type];
            if (message && typeof window.toast === 'function') {
                window.toast(type.charAt(0).toUpperCase() + type.slice(1), {
                    type: type === 'error' ? 'danger' : type,
                    description: message,
                });
            }
        });
    }, [flash]);

    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';
    const csrfToken = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content : '';

    return (
        <div className="flex min-h-screen">
            <aside className="hidden lg:flex lg:w-64 lg:flex-col lg:fixed lg:inset-y-0 border-r border-neutral-300 dark:border-coolgray-200 bg-white dark:bg-base">
                <div className="flex flex-col gap-1 px-4 py-6">
                    <Link href="/" className="text-2xl font-bold tracking-tight dark:text-white">
                        Coolify
                    </Link>
                    {currentTeam && (
                        <div className="text-sm text-neutral-500 dark:text-coolgray-400">{currentTeam.name}</div>
                    )}
                </div>
                <nav className="flex flex-col gap-1 px-2 flex-1 overflow-y-auto">
                    {NAV_ITEMS.filter((item) => !item.permission || permissions?.[item.permission]).map((item) => {
                        const isActive = item.match === '/' ? currentPath === '/' : currentPath.startsWith(item.match);

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`menu-item ${isActive ? 'menu-item-active' : ''}`}
                            >
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
                {auth?.user && (
                    // pb-12 (not py-4's default) so this clears Laravel Debugbar's fixed-position
                    // bottom toolbar in local dev - that bar sits at viewport bottom with a high
                    // z-index, and this sidebar is itself position:fixed spanning the full
                    // viewport height, so without the extra clearance the toolbar covers the
                    // Logout button (still clickable-if-you-knew-it-was-there, but invisible).
                    <div className="flex flex-col gap-2 px-4 pt-4 pb-12 border-t border-neutral-300 dark:border-coolgray-200">
                        <div className="text-sm text-neutral-500 dark:text-coolgray-400">{auth.user.name}</div>
                        <form action="/logout" method="POST">
                            <input type="hidden" name="_token" value={csrfToken} />
                            <button type="submit" className="menu-item text-left w-full">
                                Logout
                            </button>
                        </form>
                    </div>
                )}
            </aside>
            <main className="flex-1 lg:pl-64 p-6">{children}</main>
        </div>
    );
}
