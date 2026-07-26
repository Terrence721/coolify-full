import { describe, expect, it, vi, afterEach } from 'vitest';
import { reloadOnBFCacheRestore } from './reloadOnBFCacheRestore';

// jsdom's window.location.reload() is a stubbed no-op that logs a "Not implemented" warning
// rather than a real navigation - replacing it with a spy keeps the test output clean and lets
// us assert it was actually called.
const originalLocation = window.location;

afterEach(() => {
    window.location = originalLocation;
    vi.restoreAllMocks();
});

describe('reloadOnBFCacheRestore', () => {
    it('reloads the page when pageshow fires with event.persisted true (a bfcache restore)', () => {
        window.location = { ...originalLocation, reload: vi.fn() };
        reloadOnBFCacheRestore();

        window.dispatchEvent(new Event('pageshow'));
        expect(window.location.reload).not.toHaveBeenCalled();

        const persistedEvent = new Event('pageshow');
        Object.defineProperty(persistedEvent, 'persisted', { value: true });
        window.dispatchEvent(persistedEvent);

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it("does not reload on a normal (non-persisted) pageshow, e.g. the page's first real load", () => {
        window.location = { ...originalLocation, reload: vi.fn() };
        reloadOnBFCacheRestore();

        const freshLoadEvent = new Event('pageshow');
        Object.defineProperty(freshLoadEvent, 'persisted', { value: false });
        window.dispatchEvent(freshLoadEvent);

        expect(window.location.reload).not.toHaveBeenCalled();
    });
});
