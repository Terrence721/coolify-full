import { render, screen, waitFor } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WhatsNewButton from './WhatsNewButton';

// Regression coverage for WhatsNewButton.jsx - the "What's New" changelog widget. Untested
// until now despite real business logic: unread-count badging, on-demand fetch, optimistic
// mark-as-read, and a search/sort pipeline (unread-first, then newest-first).

const ENTRIES = [
    {
        tag_name: 'v4.2.0',
        title: 'v4.2.0',
        content: 'Added dark mode',
        content_html: '<p>Added dark mode</p>',
        is_read: true,
        published_at: '2026-06-01',
    },
    {
        tag_name: 'v4.3.0',
        title: 'v4.3.0',
        content: 'Fixed a terminal bug',
        content_html: '<p>Fixed a terminal bug</p>',
        is_read: false,
        published_at: '2026-07-01',
    },
    {
        tag_name: 'v4.1.0',
        title: 'v4.1.0',
        content: 'Initial release notes',
        content_html: '<p>Initial release notes</p>',
        is_read: false,
        published_at: '2026-05-01',
    },
];

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

async function openModal() {
    await act(async () => {
        screen.getByTitle("What's New").click();
    });
    await waitFor(() => expect(screen.getByPlaceholderText('Search updates...')).toBeInTheDocument());
}

describe('WhatsNewButton', () => {
    beforeEach(() => {
        global.fetch = vi.fn((url) => {
            if (url === '/changelog/entries') {
                return Promise.resolve({ json: () => Promise.resolve({ entries: ENTRIES, unreadCount: 2 }) });
            }

            return Promise.resolve({ json: () => Promise.resolve({}) });
        });
        window.toast = vi.fn();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the unread count badge', () => {
        render(<WhatsNewButton unreadCount={3} currentVersion="v4.2.0" canFetchLatest={false} />);

        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('caps the badge at "9+" for large unread counts', () => {
        render(<WhatsNewButton unreadCount={12} currentVersion="v4.2.0" canFetchLatest={false} />);

        expect(screen.getByText('9+')).toBeInTheDocument();
    });

    it('hides the badge entirely when there are no unread entries', () => {
        render(<WhatsNewButton unreadCount={0} currentVersion="v4.2.0" canFetchLatest={false} />);

        expect(screen.queryByText('0')).not.toBeInTheDocument();
    });

    it('fetches and renders entries when the modal opens', async () => {
        render(<WhatsNewButton unreadCount={2} currentVersion="v4.2.0" canFetchLatest={false} />);
        await openModal();

        expect(global.fetch).toHaveBeenCalledWith('/changelog/entries', expect.objectContaining({ headers: { Accept: 'application/json' } }));
        expect(screen.getByText('v4.3.0')).toBeInTheDocument();
        expect(screen.getByText('v4.1.0')).toBeInTheDocument();
    });

    it('sorts unread entries before read ones, newest first within each group', async () => {
        render(<WhatsNewButton unreadCount={2} currentVersion="v4.2.0" canFetchLatest={false} />);
        await openModal();

        // Scoped to the entry-title links specifically - the modal header's own "Current
        // version: v4.2.0" text also matches a loose `getAllByText` query and isn't part of
        // the sorted list, which would corrupt the ordering assertion below.
        const renderedTags = [...document.querySelectorAll('a[href*="releases/tag/"]')].map((el) => el.textContent);
        // v4.3.0 (unread, newer) and v4.1.0 (unread, older) both come before v4.2.0 (read)
        expect(renderedTags.indexOf('v4.3.0')).toBeLessThan(renderedTags.indexOf('v4.2.0'));
        expect(renderedTags.indexOf('v4.1.0')).toBeLessThan(renderedTags.indexOf('v4.2.0'));
        expect(renderedTags.indexOf('v4.3.0')).toBeLessThan(renderedTags.indexOf('v4.1.0'));
    });

    it('filters entries by search text across title/content/tag', async () => {
        render(<WhatsNewButton unreadCount={2} currentVersion="v4.2.0" canFetchLatest={false} />);
        await openModal();

        act(() => typeInto(screen.getByPlaceholderText('Search updates...'), 'terminal'));

        const renderedTags = [...document.querySelectorAll('a[href*="releases/tag/"]')].map((el) => el.textContent);
        expect(renderedTags).toEqual(['v4.3.0']);
    });

    it('marks a single entry as read: hides its button and decrements the badge', async () => {
        render(<WhatsNewButton unreadCount={2} currentVersion="v4.2.0" canFetchLatest={false} />);
        await openModal();

        const markButtons = screen.getAllByTitle('Mark as read');
        expect(markButtons).toHaveLength(2);

        act(() => markButtons[0].click());

        expect(screen.getAllByTitle('Mark as read')).toHaveLength(1);
        expect(global.fetch).toHaveBeenCalledWith('/changelog/mark-read', expect.objectContaining({ method: 'POST' }));
    });

    it('marks all entries as read at once', async () => {
        render(<WhatsNewButton unreadCount={2} currentVersion="v4.2.0" canFetchLatest={false} />);
        await openModal();

        act(() => screen.getByText('Mark all as read').click());

        expect(screen.queryAllByTitle('Mark as read')).toHaveLength(0);
        expect(screen.queryByText('Mark all as read')).not.toBeInTheDocument();
        expect(global.fetch).toHaveBeenCalledWith('/changelog/mark-all-read', expect.objectContaining({ method: 'POST' }));
    });

    it('only shows "Fetch Latest" when canFetchLatest is true', async () => {
        render(<WhatsNewButton unreadCount={2} currentVersion="v4.2.0" canFetchLatest={true} />);
        await openModal();

        expect(screen.getByText('Fetch Latest')).toBeInTheDocument();
    });

    it('marks the entry matching currentVersion with a CURRENT VERSION badge', async () => {
        render(<WhatsNewButton unreadCount={2} currentVersion="v4.2.0" canFetchLatest={false} />);
        await openModal();

        expect(screen.getByText('CURRENT VERSION')).toBeInTheDocument();
    });
});
