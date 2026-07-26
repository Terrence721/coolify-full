/**
 * When a browser restores a page from the back/forward cache (bfcache) - e.g. hitting Back
 * after logging out and logging back in - the restored DOM is a frozen snapshot from before
 * that session change, including a now-stale CSRF token baked into the
 * <meta name="csrf-token"> tag. Nothing re-reads that tag from the server on an Inertia
 * client-side navigation (only the initial full document load sets it), so a bfcache-restored
 * page can submit a stale token on its next request (e.g. the sidebar's Logout form) and get a
 * real 419 even though the page looked perfectly normal. Forcing a reload on restore guarantees
 * a fresh document whose token matches the live session.
 */
export function reloadOnBFCacheRestore() {
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });
}
