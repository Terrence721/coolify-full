import { Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * React port of the persistent navbar/sidebar shell (resources/views/components/navbar.blade.php
 * + resources/views/layouts/app.blade.php), used as an Inertia persistent layout so it survives
 * client-side page transitions instead of remounting per page (see inertia-app.jsx).
 *
 * Known v1 gaps, intentionally out of scope for this pass (mixing live Livewire components inside
 * a React tree is unsolved): team switching (read-only team name shown instead of
 * <livewire:switch-team/>), the settings dropdown, the upgrade banner, delete-team modal trigger,
 * and the help modal (<livewire:settings-dropdown/>, <livewire:upgrade/>,
 * <livewire:navbar-delete-team/>, <livewire:help/>). Also no mobile hamburger drawer or
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
                    <div className="px-4 py-4 text-sm text-neutral-500 dark:text-coolgray-400 border-t border-neutral-300 dark:border-coolgray-200">
                        {auth.user.name}
                    </div>
                )}
            </aside>
            <main className="flex-1 lg:pl-64 p-6">{children}</main>
        </div>
    );
}
