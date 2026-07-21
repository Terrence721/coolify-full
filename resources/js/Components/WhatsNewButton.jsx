import { useState } from 'react';

/**
 * React port of the one genuinely-live piece of the otherwise-orphaned
 * App\Livewire\SettingsDropdown — the "What's New" changelog. Entries are fetched on-demand via
 * plain fetch() when the modal opens (ChangelogController::entries()), not carried as an
 * always-on Inertia prop, since the rendered HTML content could be large; only the cheap
 * unreadCount/currentVersion summary is shared globally (see HandleInertiaRequests).
 */
export default function WhatsNewButton({ unreadCount: initialUnreadCount, currentVersion, canFetchLatest }) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [entries, setEntries] = useState([]);
    const [unreadCount, setUnreadCount] = useState(initialUnreadCount ?? 0);
    const [search, setSearch] = useState('');

    function openModal() {
        setOpen(true);
        setLoading(true);
        fetch('/changelog/entries', { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data) => {
                setEntries(data.entries ?? []);
                setUnreadCount(data.unreadCount ?? 0);
            })
            .finally(() => setLoading(false));
    }

    function markAsRead(tagName) {
        setEntries((prev) => prev.map((e) => (e.tag_name === tagName ? { ...e, is_read: true } : e)));
        setUnreadCount((prev) => Math.max(0, prev - 1));
        fetch('/changelog/mark-read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ identifier: tagName }),
        });
    }

    function markAllAsRead() {
        setEntries((prev) => prev.map((e) => ({ ...e, is_read: true })));
        setUnreadCount(0);
        fetch('/changelog/mark-all-read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
    }

    function fetchLatest() {
        fetch('/changelog/fetch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        }).then(() => window.toast?.('Fetching', { type: 'info', description: 'Changelog fetch initiated! Check back in a few moments.' }));
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    const filtered = entries
        .filter((e) => {
            if (!search.trim()) return true;
            const term = search.trim().toLowerCase();

            return e.title?.toLowerCase().includes(term) || e.content?.toLowerCase().includes(term) || e.tag_name?.toLowerCase().includes(term);
        })
        .sort((a, b) => {
            if (a.is_read !== b.is_read) return a.is_read ? 1 : -1;

            return new Date(b.published_at) - new Date(a.published_at);
        });

    return (
        <>
            <button
                type="button"
                onClick={openModal}
                className="relative p-2 dark:text-neutral-400 hover:dark:text-white transition-colors"
                title="What's New"
            >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth="1.8"
                        d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.091-3.091L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.091-3.091L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.091 3.091L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.091 3.091ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"
                    />
                </svg>
                {unreadCount > 0 && (
                    <span className="absolute -top-1 -right-1 bg-error text-white text-xs rounded-full w-4.5 h-4.5 flex items-center justify-center">
                        {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                )}
            </button>

            {open && (
                <div className="fixed inset-0 z-60 flex items-center justify-center py-6 px-4">
                    <div className="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs" onClick={() => setOpen(false)} />
                    <div className="relative w-full h-full max-w-7xl py-6 border rounded-sm drop-shadow-sm bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300 flex flex-col">
                        <div className="flex items-center justify-between pb-3">
                            <div>
                                <h3 className="text-2xl font-bold dark:text-white">Changelog</h3>
                                <p className="mt-1 text-sm dark:text-neutral-400">Stay up to date with the latest features and improvements.</p>
                                <p className="mt-1 text-xs dark:text-neutral-500">
                                    Current version: <span className="font-semibold dark:text-neutral-300">{currentVersion}</span>
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                {canFetchLatest && (
                                    <button type="button" onClick={fetchLatest}>
                                        Fetch Latest
                                    </button>
                                )}
                                {unreadCount > 0 && (
                                    <button type="button" onClick={markAllAsRead}>
                                        Mark all as read
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    className="flex items-center justify-center w-8 h-8 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300"
                                >
                                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div className="pb-4 border-b dark:border-coolgray-200 shrink-0">
                            <input
                                id="whats-new-search"
                                name="whats-new-search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search updates..."
                                className="input"
                            />
                        </div>

                        <div className="py-4 flex-1 overflow-y-auto scrollbar">
                            {loading ? (
                                <div className="text-center py-8 dark:text-neutral-400">Loading...</div>
                            ) : filtered.length > 0 ? (
                                <div className="space-y-4">
                                    {filtered.map((entry) => (
                                        <div
                                            key={entry.tag_name}
                                            className={`relative p-4 border dark:border-coolgray-300 rounded-sm ${
                                                !entry.is_read ? 'dark:bg-coolgray-200 border-warning' : 'dark:bg-coolgray-100'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-2 mb-2">
                                                        {entry.title && (
                                                            <span className="px-2 py-1 text-xs font-semibold dark:bg-coolgray-300 dark:text-neutral-200 rounded-sm">
                                                                <a
                                                                    href={`https://github.com/Terrence721/coolify-full/releases/tag/${entry.tag_name}`}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    className="inline-flex items-center gap-1 hover:text-coolgray-500"
                                                                >
                                                                    {entry.title}
                                                                </a>
                                                            </span>
                                                        )}
                                                        {entry.tag_name === currentVersion && (
                                                            <span className="px-2 py-1 text-xs font-semibold bg-success text-white rounded-sm">
                                                                CURRENT VERSION
                                                            </span>
                                                        )}
                                                        <span className="text-xs dark:text-neutral-400">
                                                            {new Date(entry.published_at).toLocaleDateString('en-US', {
                                                                month: 'short',
                                                                day: 'numeric',
                                                                year: 'numeric',
                                                            })}
                                                        </span>
                                                    </div>
                                                    <div
                                                        className="dark:text-neutral-300 leading-relaxed max-w-none"
                                                        dangerouslySetInnerHTML={{ __html: entry.content_html }}
                                                    />
                                                </div>
                                                {!entry.is_read && (
                                                    <button
                                                        type="button"
                                                        onClick={() => markAsRead(entry.tag_name)}
                                                        className="ml-4 px-3 py-1 text-xs dark:text-neutral-400 hover:dark:text-white border dark:border-neutral-600 rounded hover:dark:bg-neutral-700"
                                                        title="Mark as read"
                                                    >
                                                        mark as read
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <h3 className="mt-2 text-sm font-medium dark:text-white">No updates found</h3>
                                    <p className="mt-1 text-sm dark:text-neutral-400">
                                        {search.trim() !== ''
                                            ? 'No updates match your search criteria.'
                                            : 'There are no updates available at the moment.'}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
