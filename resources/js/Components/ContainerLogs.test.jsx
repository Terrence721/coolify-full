import { fireEvent, render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ContainerLogs from './ContainerLogs';

// The shared log viewer behind 3 pages (Server/Proxy/Logs, Server/Sentinel/Logs,
// Project/Shared/Logs) - both Server-scoped consumers were live-verified end-to-end during the
// 2026-07-26/28 Server management smoke test (issue #26): real streaming output and download
// both confirmed working. This component itself was previously entirely untested. Testing it
// directly here, rather than adding near-duplicate wrapper-page suites for the two thin
// pass-through pages, matches this repo's existing convention for shared-component coverage.

const reloadSpy = vi.fn();
const getSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (options) => reloadSpy(options),
        get: (url, data, options) => getSpy(url, data, options),
    },
}));

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function baseLines() {
    return [
        { line: 'Server started successfully', timestamp: '2026-07-28 10:00:00' },
        { line: 'WARNING: disk usage high', timestamp: '2026-07-28 10:00:05' },
        { line: 'ERROR: connection refused', timestamp: '2026-07-28 10:00:10' },
    ];
}

function baseProps(overrides = {}) {
    return {
        displayName: 'Sentinel',
        logLines: baseLines(),
        numberOfLines: 100,
        showTimestamps: true,
        urls: { downloadAll: '/server/srv-uuid/sentinel/logs/download' },
        ...overrides,
    };
}

describe('ContainerLogs', () => {
    beforeEach(() => {
        reloadSpy.mockClear();
        getSpy.mockClear();
        localStorage.clear();
        window.history.pushState({}, '', '/server/srv-uuid/sentinel/logs');
        global.URL.createObjectURL = vi.fn(() => 'blob:mock-url');
        global.URL.revokeObjectURL = vi.fn();
        Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('shows "No logs yet." when there are no lines', () => {
        render(<ContainerLogs {...baseProps({ logLines: [] })} />);
        expect(screen.getByText('No logs yet.')).toBeInTheDocument();
    });

    it('renders every line with its timestamp when showTimestamps is true, and the displayName heading', () => {
        render(<ContainerLogs {...baseProps()} />);
        expect(screen.getByText('Sentinel')).toBeInTheDocument();
        expect(screen.getByText('Server started successfully')).toBeInTheDocument();
        expect(screen.getByText('2026-07-28 10:00:00')).toBeInTheDocument();
    });

    it('hides timestamps when showTimestamps is false', () => {
        render(<ContainerLogs {...baseProps({ showTimestamps: false })} />);
        expect(screen.getByText('Server started successfully')).toBeInTheDocument();
        expect(screen.queryByText('2026-07-28 10:00:00')).not.toBeInTheDocument();
    });

    it('omits the displayName heading when not provided', () => {
        render(<ContainerLogs {...baseProps({ displayName: undefined })} />);
        expect(screen.queryByRole('heading', { level: 4 })).not.toBeInTheDocument();
    });

    describe('search', () => {
        it('filters visible lines case-insensitively and shows a match count', () => {
            render(<ContainerLogs {...baseProps()} />);
            act(() => typeInto(screen.getByPlaceholderText('Find in logs'), 'warning'));

            // The matched substring is wrapped in its own highlight span, splitting the line's
            // text across nodes - match on the containing span's full textContent instead.
            expect(
                screen.getByText(
                    (_, el) =>
                        el?.tagName === 'SPAN' && el.className === 'whitespace-pre-wrap break-all' && el.textContent === 'WARNING: disk usage high',
                ),
            ).toBeInTheDocument();
            expect(screen.queryByText('Server started successfully')).not.toBeInTheDocument();
            expect(screen.getByText('1 matches')).toBeInTheDocument();
        });

        it('shows "No matches found." when the query matches nothing', () => {
            render(<ContainerLogs {...baseProps()} />);
            act(() => typeInto(screen.getByPlaceholderText('Find in logs'), 'nonexistent-token'));
            expect(screen.getByText('No matches found.')).toBeInTheDocument();
        });

        it('shows Clear only while searching, and it resets the query', () => {
            render(<ContainerLogs {...baseProps()} />);
            expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();

            act(() => typeInto(screen.getByPlaceholderText('Find in logs'), 'error'));
            expect(screen.getByRole('button', { name: 'Clear' })).toBeInTheDocument();

            act(() => screen.getByRole('button', { name: 'Clear' }).click());
            expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();
            expect(screen.getByText('Server started successfully')).toBeInTheDocument();
        });

        it('wraps the matched substring in a highlight span', () => {
            render(<ContainerLogs {...baseProps()} />);
            act(() => typeInto(screen.getByPlaceholderText('Find in logs'), 'refused'));
            expect(document.querySelector('.log-highlight')).toHaveTextContent('refused');
        });
    });

    describe('level filtering', () => {
        it('hides a level’s lines once its filter checkbox is unchecked, and persists the choice to localStorage', () => {
            render(<ContainerLogs {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Filter' }).click());
            act(() => screen.getByLabelText('Error').click());

            expect(screen.queryByText('ERROR: connection refused')).not.toBeInTheDocument();
            expect(screen.getByText('Server started successfully')).toBeInTheDocument();
            expect(JSON.parse(localStorage.getItem('coolify-log-filters'))).toMatchObject({ error: false });
        });

        it('reads the initial filter state back from localStorage', () => {
            localStorage.setItem('coolify-log-filters', JSON.stringify({ error: false, warning: true, debug: true, info: true }));
            render(<ContainerLogs {...baseProps()} />);
            expect(screen.queryByText('ERROR: connection refused')).not.toBeInTheDocument();
        });
    });

    it('toggles color-logs and persists the choice to localStorage', () => {
        render(<ContainerLogs {...baseProps()} />);
        const colorsBtn = screen.getByRole('button', { name: 'Colors' });
        expect(localStorage.getItem('coolify-color-logs')).toBeNull();

        act(() => colorsBtn.click());
        expect(localStorage.getItem('coolify-color-logs')).toBe('true');

        act(() => colorsBtn.click());
        expect(localStorage.getItem('coolify-color-logs')).toBe('false');
    });

    describe('streaming', () => {
        it('polls router.reload every 2s while streaming, and stops when toggled off', () => {
            vi.useFakeTimers();
            render(<ContainerLogs {...baseProps()} />);
            const streamBtn = screen.getByRole('button', { name: 'Stream' });

            act(() => streamBtn.click());
            expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();

            act(() => vi.advanceTimersByTime(2000));
            expect(reloadSpy).toHaveBeenCalledWith({ only: ['logLines'], preserveScroll: true, preserveState: true });
            expect(reloadSpy).toHaveBeenCalledTimes(1);

            act(() => vi.advanceTimersByTime(4000));
            expect(reloadSpy).toHaveBeenCalledTimes(3);

            act(() => screen.getByRole('button', { name: 'Stop' }).click());
            reloadSpy.mockClear();
            act(() => vi.advanceTimersByTime(4000));
            expect(reloadSpy).not.toHaveBeenCalled();
        });

        it('disables Refresh and makes the Lines input read-only while streaming', () => {
            render(<ContainerLogs {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Stream' }).click());

            expect(screen.getByRole('button', { name: 'Refresh' })).toBeDisabled();
            expect(screen.getByTitle('Number of Lines (max 50,000)')).toHaveAttribute('readonly');
        });
    });

    it('Refresh calls router.reload immediately with the given reloadKeys', () => {
        render(<ContainerLogs {...baseProps({ reloadKeys: ['logLines', 'numberOfLines'] })} />);
        act(() => screen.getByRole('button', { name: 'Refresh' }).click());
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['logLines', 'numberOfLines'], preserveScroll: true, preserveState: true });
    });

    it('submitting the Lines field calls router.get with the new line count and current timestamps setting', () => {
        render(<ContainerLogs {...baseProps({ numberOfLines: 100, showTimestamps: true })} />);
        const linesInput = screen.getByTitle('Number of Lines (max 50,000)');
        act(() => typeInto(linesInput, '500'));
        act(() => fireEvent.submit(linesInput.closest('form')));

        expect(getSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/sentinel/logs',
            { lines: 500, timestamps: 1 },
            { preserveState: true, preserveScroll: true, only: ['logLines'] },
        );
    });

    it('Toggle Timestamps calls router.get with the flipped value and unchanged line count', () => {
        render(<ContainerLogs {...baseProps({ numberOfLines: 100, showTimestamps: true })} />);
        act(() => screen.getByRole('button', { name: 'Timestamps' }).click());
        expect(getSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/sentinel/logs',
            { lines: 100, timestamps: 0 },
            { preserveState: true, preserveScroll: true, only: ['logLines'] },
        );
    });

    it('respects queryPrefix in both the query-string keys and the field ids', () => {
        window.history.pushState({}, '', '/project/proj/environment/env/logs');
        render(<ContainerLogs {...baseProps({ numberOfLines: 50, showTimestamps: false, reloadKeys: ['containerA'], queryPrefix: 'a-' })} />);

        expect(document.getElementById('a-logs-lines')).toBeInTheDocument();
        expect(document.getElementById('a-logs-search')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Timestamps' }).click());
        expect(getSpy).toHaveBeenCalledWith(
            '/project/proj/environment/env/logs',
            { 'a-lines': 50, 'a-timestamps': 1 },
            { preserveState: true, preserveScroll: true, only: ['containerA'] },
        );
    });

    describe('download and copy', () => {
        it('"Download displayed logs" builds a text blob and triggers a download, then closes the menu', () => {
            const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
            render(<ContainerLogs {...baseProps()} />);

            act(() => screen.getByRole('button', { name: 'Download' }).click());
            act(() => screen.getByRole('button', { name: 'Download displayed logs' }).click());

            expect(global.URL.createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
            expect(clickSpy).toHaveBeenCalledTimes(1);
            expect(global.URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-url');
            expect(screen.queryByRole('button', { name: 'Download displayed logs' })).not.toBeInTheDocument();
        });

        it('"Download all logs" links to urls.downloadAll and closes the menu on click', () => {
            render(<ContainerLogs {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Download' }).click());

            const link = screen.getByRole('link', { name: 'Download all logs' });
            expect(link).toHaveAttribute('href', '/server/srv-uuid/sentinel/logs/download');

            act(() => link.click());
            expect(screen.queryByRole('link', { name: 'Download all logs' })).not.toBeInTheDocument();
        });

        it('Copy writes the visible, timestamp-aware log text to the clipboard', () => {
            render(<ContainerLogs {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Copy' }).click());
            expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
                '2026-07-28 10:00:00 Server started successfully\n2026-07-28 10:00:05 WARNING: disk usage high\n2026-07-28 10:00:10 ERROR: connection refused',
            );
        });
    });

    it('Fullscreen toggles the container class and its own label', () => {
        render(<ContainerLogs {...baseProps()} />);
        const btn = screen.getByRole('button', { name: 'Fullscreen' });
        act(() => btn.click());
        expect(screen.getByRole('button', { name: 'Minimize' })).toBeInTheDocument();
        expect(document.querySelector('.fullscreen')).toBeInTheDocument();
    });

    describe('follow / auto-scroll', () => {
        it('Follow button manually toggles alwaysScroll styling', () => {
            render(<ContainerLogs {...baseProps()} />);
            const followBtn = screen.getByRole('button', { name: 'Follow' });
            expect(followBtn).not.toHaveClass('text-warning!');

            act(() => followBtn.click());
            expect(followBtn).toHaveClass('text-warning!');
        });

        it('scrolling within 10px of the bottom turns Follow on automatically', () => {
            render(<ContainerLogs {...baseProps()} />);
            const scrollEl = document.querySelector('.scrollbar');
            Object.defineProperty(scrollEl, 'scrollHeight', { value: 1000, configurable: true });
            Object.defineProperty(scrollEl, 'clientHeight', { value: 400, configurable: true });
            Object.defineProperty(scrollEl, 'scrollTop', { value: 595, configurable: true, writable: true });

            act(() => fireEvent.scroll(scrollEl));
            expect(screen.getByRole('button', { name: 'Follow' })).toHaveClass('text-warning!');
        });

        it('scrolling up (wheel deltaY < 0) turns Follow back off once active', () => {
            render(<ContainerLogs {...baseProps()} />);
            const followBtn = screen.getByRole('button', { name: 'Follow' });
            act(() => followBtn.click());
            expect(followBtn).toHaveClass('text-warning!');

            const scrollEl = document.querySelector('.scrollbar');
            act(() => fireEvent.wheel(scrollEl, { deltaY: -100 }));
            expect(followBtn).not.toHaveClass('text-warning!');
        });
    });
});
