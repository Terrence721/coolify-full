import { Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import GlobalSearchModal from '../Components/GlobalSearchModal';
import LayoutPopups from '../Components/LayoutPopups';
import ThemeSwitcher from '../Components/ThemeSwitcher';
import Toast from '../Components/Toast';
import WhatsNewButton from '../Components/WhatsNewButton';
import { applyZoom, pageWidthClass } from '../hooks/useAppearance';

/**
 * React port of the persistent navbar/sidebar shell (formerly
 * resources/views/components/navbar.blade.php + resources/views/layouts/app.blade.php, both
 * deleted once the Livewire→React migration completed), used as an Inertia persistent layout so
 * it survives client-side page transitions instead of remounting per page (see inertia-app.jsx).
 * The app has no Livewire runtime left at all — every page is React/Inertia or a plain Blade
 * auth/error screen with no shared chrome.
 *
 * ThemeSwitcher.jsx and WhatsNewButton.jsx (ChangelogController) are React ports of the old
 * navbar's theme toggle and What's New changelog; ThemeSwitcher mirrors the original's `theme`
 * localStorage key so it stays consistent with pages that predate this shell. LayoutPopups.jsx
 * and GlobalSearchModal.jsx (the "/" or ⌘K command palette) are ports of the former
 * `<livewire:layout-popups/>` and `<livewire:global-search/>` components — the search side shares
 * its actual query logic with the backend via GlobalSearchService rather than duplicating it.
 * useAppearance.js's applyZoom()/pageWidthClass() are ports of the old navbar's checkZoom() and
 * app.blade.php's pageWidth class binding — both went dead (silently, for a while) once
 * navbar.blade.php/app.blade.php were deleted and nothing on the React side replaced them; see
 * todo.md's "Correction, 2026-07-19" note.
 * Toast.jsx is a from-scratch React port of components/toast.blade.php, which defined
 * window.toast() entirely via Alpine.js (x-data/x-init/x-teleport) - removing Alpine broke it
 * silently everywhere, and app-inertia.blade.php never included it in the first place, so every
 * window.toast(...) call site in the app (this file's own flash handler included) had been
 * no-op'ing since the migration completed. Found via manual smoke-test QA, not inspection - see
 * todo.md's "Correction, 2026-07-20" note.
 *
 * Known v1 gaps — features the old Livewire navbar had that have no React port yet, not deferred
 * for any architectural reason: team switching (read-only team name shown instead of a working
 * switcher), the upgrade banner, delete-team modal trigger, and the help modal. Logout itself
 * (POST /logout, matching the plain <form action="/logout" method="POST"> the old navbar used) is
 * ported below since its absence otherwise leaves no way to log out from any page — deliberately
 * a real browser form submission, not router.post(): logout redirects through a plain
 * (non-Inertia) Blade login page, and Inertia's XHR-based navigation has no reliable way to hand
 * off to a full page it doesn't control the rendering of. A native form POST lets the browser's
 * own navigation follow the redirect chain (POST 302 -> GET 302 -> GET /login) with no SPA
 * involved at all. Also no mobile hamburger drawer or collapse/expand toggle yet — the sidebar is
 * always expanded. A deployment status indicator is likewise not yet ported.
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
    const { auth, currentTeam, permissions, flash, changelog } = props;

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

    useEffect(() => {
        applyZoom(localStorage.getItem('zoom') || '100');
    }, []);

    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';
    const csrfToken = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content : '';
    const pageWidth = typeof window !== 'undefined' ? localStorage.getItem('pageWidth') || 'full' : 'full';

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
                {auth?.user && (
                    <div className="px-4 pb-2">
                        <button
                            type="button"
                            onClick={() => window.dispatchEvent(new CustomEvent('open-global-search'))}
                            title="Search (Press / or ⌘K)"
                            className="flex h-8 w-full items-center justify-between gap-1.5 px-2.5 py-1.5 bg-neutral-100 dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-200 rounded-md hover:bg-neutral-200 dark:hover:bg-coolgray-200 transition-colors"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-neutral-500 dark:text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <kbd className="px-1 py-0.5 text-xs font-semibold text-neutral-500 dark:text-neutral-400 bg-neutral-200 dark:bg-coolgray-200 rounded">/</kbd>
                        </button>
                    </div>
                )}
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
            <div className="flex-1 lg:pl-64 flex flex-col">
                <header className="sticky top-0 z-40 flex items-center justify-end gap-2 px-6 py-3 border-b border-neutral-300/50 dark:border-coolgray-200/50 bg-white/95 dark:bg-base/95 backdrop-blur-sm">
                    <ThemeSwitcher />
                    {auth?.user && changelog && (
                        <WhatsNewButton unreadCount={changelog.unreadCount} currentVersion={changelog.currentVersion} canFetchLatest={permissions?.isDev} />
                    )}
                </header>
                <main className={`flex-1 p-6 w-full ${pageWidthClass(pageWidth)}`}>{children}</main>
            </div>
            {auth?.user && <LayoutPopups />}
            {auth?.user && <GlobalSearchModal />}
            <Toast />
        </div>
    );
}
