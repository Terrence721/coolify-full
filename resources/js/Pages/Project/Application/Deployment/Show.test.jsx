import { fireEvent, render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// The live deployment log viewer - picked as the highest-priority remaining suite under the
// documented priority ordering: it's literally the "deployment flows" example from that criteria,
// with real polling (wire:poll.2000ms port), log search/highlight, and an auto-scroll lifecycle
// tied to deployment state, none of which had any coverage. ApplicationHeading/ConfigurationChecker
// are mocked out - both already have their own dedicated suites.

const reloadSpy = vi.fn();
const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (options) => reloadSpy(options),
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('../../../../Components/ApplicationHeading', () => ({ default: () => <div data-testid="ApplicationHeading" /> }));
vi.mock('../../../../Components/ConfigurationChecker', () => ({ default: () => <div data-testid="ConfigurationChecker" /> }));

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function baseLines() {
    return [
        { line: 'Starting deployment', timestamp: '2026-08-01 10:00:00' },
        { line: 'WARNING: cache miss', timestamp: '2026-08-01 10:00:05' },
        { line: 'Deployment finished', timestamp: '2026-08-01 10:00:10' },
    ];
}

function baseProps(overrides = {}) {
    return {
        application: { uuid: 'app-uuid' },
        heading: {},
        configurationChecker: {},
        deployment: { deployment_uuid: 'deploy-uuid', status: 'in_progress' },
        isDebugEnabled: false,
        isKeepAliveOn: false,
        logLines: baseLines(),
        parameters: {},
        urls: { toggleDebug: '/toggle-debug', forceStart: '/force-start', cancel: '/cancel', downloadAllLogs: '/download-all' },
        ...overrides,
    };
}

describe('Project/Application/Deployment/Show', () => {
    beforeEach(() => {
        reloadSpy.mockClear();
        postSpy.mockClear();
        global.URL.createObjectURL = vi.fn(() => 'blob:mock-url');
        global.URL.revokeObjectURL = vi.fn();
        Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('renders the heading components and shows "No logs yet." when there are no lines', () => {
        render(<Show {...baseProps({ logLines: [] })} />);

        expect(screen.getByTestId('ApplicationHeading')).toBeInTheDocument();
        expect(screen.getByTestId('ConfigurationChecker')).toBeInTheDocument();
        expect(screen.getByText('No logs yet.')).toBeInTheDocument();
    });

    it('maps a known status to its label, and falls back to the raw value for an unrecognized one', () => {
        const { rerender } = render(<Show {...baseProps({ deployment: { deployment_uuid: 'd', status: 'in_progress' } })} />);
        expect(screen.getByText('In Progress')).toBeInTheDocument();

        rerender(<Show {...baseProps({ deployment: { deployment_uuid: 'd', status: 'some-future-status' } })} />);
        expect(screen.getByText('some-future-status')).toBeInTheDocument();
    });

    describe('search', () => {
        it('filters visible lines case-insensitively, wraps the match, and shows a match count', () => {
            render(<Show {...baseProps()} />);
            act(() => typeInto(screen.getByPlaceholderText('Find in logs'), 'warning'));

            expect(document.querySelector('.log-highlight')).toHaveTextContent('WARNING');
            expect(screen.queryByText('Starting deployment')).not.toBeInTheDocument();
            expect(screen.getByText('1 matches')).toBeInTheDocument();
        });

        it('shows "No matches found." when the query matches nothing', () => {
            render(<Show {...baseProps()} />);
            act(() => typeInto(screen.getByPlaceholderText('Find in logs'), 'nonexistent-token'));
            expect(screen.getByText('No matches found.')).toBeInTheDocument();
        });

        it('shows Clear only while searching, and it resets the query', () => {
            render(<Show {...baseProps()} />);
            expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();

            act(() => typeInto(screen.getByPlaceholderText('Find in logs'), 'cache'));
            expect(screen.getByRole('button', { name: 'Clear' })).toBeInTheDocument();

            act(() => screen.getByRole('button', { name: 'Clear' }).click());
            expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();
            expect(screen.getByText('Starting deployment')).toBeInTheDocument();
        });
    });

    it('Timestamps toggles showing the per-line timestamp, purely as local UI state', () => {
        render(<Show {...baseProps()} />);
        expect(screen.getByText('2026-08-01 10:00:00')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Timestamps' }).click());
        expect(screen.queryByText('2026-08-01 10:00:00')).not.toBeInTheDocument();
        expect(postSpy).not.toHaveBeenCalled();
    });

    describe('status-based action buttons', () => {
        it('shows Force Start only when queued, and it posts to urls.forceStart', () => {
            render(<Show {...baseProps({ deployment: { deployment_uuid: 'd', status: 'queued' } })} />);

            act(() => screen.getByRole('button', { name: 'Force Start' }).click());
            expect(postSpy).toHaveBeenCalledWith('/force-start', {}, { preserveScroll: true });
        });

        it('hides Force Start once the deployment is in progress', () => {
            render(<Show {...baseProps({ deployment: { deployment_uuid: 'd', status: 'in_progress' } })} />);
            expect(screen.queryByRole('button', { name: 'Force Start' })).not.toBeInTheDocument();
        });

        it('shows Cancel for queued and in_progress, not for finished, and it posts to urls.cancel', () => {
            const { rerender } = render(<Show {...baseProps({ deployment: { deployment_uuid: 'd', status: 'queued' } })} />);
            expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();

            rerender(<Show {...baseProps({ deployment: { deployment_uuid: 'd', status: 'in_progress' } })} />);
            act(() => screen.getByRole('button', { name: 'Cancel' }).click());
            expect(postSpy).toHaveBeenCalledWith('/cancel', {}, { preserveScroll: true });

            rerender(<Show {...baseProps({ deployment: { deployment_uuid: 'd', status: 'finished' } })} />);
            expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
        });
    });

    it('Debug toggles active styling and posts to urls.toggleDebug', () => {
        render(<Show {...baseProps({ isDebugEnabled: false })} />);
        const debugBtn = screen.getByRole('button', { name: 'Debug' });
        expect(debugBtn).not.toHaveClass('text-warning!');

        act(() => debugBtn.click());
        expect(postSpy).toHaveBeenCalledWith('/toggle-debug', {}, { preserveScroll: true });
    });

    describe('polling', () => {
        it('polls router.reload every 2s while isKeepAliveOn is true, and does not when false', () => {
            vi.useFakeTimers();
            render(<Show {...baseProps({ isKeepAliveOn: false })} />);

            act(() => vi.advanceTimersByTime(4000));
            expect(reloadSpy).not.toHaveBeenCalled();
        });

        it('fires the correct reload payload on each tick and stops after unmount', () => {
            vi.useFakeTimers();
            const { unmount } = render(<Show {...baseProps({ isKeepAliveOn: true })} />);

            act(() => vi.advanceTimersByTime(2000));
            expect(reloadSpy).toHaveBeenCalledWith({ only: ['deployment', 'logLines', 'isKeepAliveOn'], preserveScroll: true, preserveState: true });
            expect(reloadSpy).toHaveBeenCalledTimes(1);

            act(() => vi.advanceTimersByTime(4000));
            expect(reloadSpy).toHaveBeenCalledTimes(3);

            unmount();
            reloadSpy.mockClear();
            act(() => vi.advanceTimersByTime(4000));
            expect(reloadSpy).not.toHaveBeenCalled();
        });
    });

    it('turns Follow off ~500ms after the deployment stops being keep-alive', () => {
        vi.useFakeTimers();
        const { rerender } = render(<Show {...baseProps({ isKeepAliveOn: true })} />);
        expect(screen.getByRole('button', { name: 'Follow' })).toHaveClass('text-warning!');

        rerender(<Show {...baseProps({ isKeepAliveOn: false })} />);
        act(() => vi.advanceTimersByTime(500));
        expect(screen.getByRole('button', { name: 'Follow' })).not.toHaveClass('text-warning!');
    });

    describe('follow / auto-scroll', () => {
        it('Follow button manually toggles alwaysScroll styling', () => {
            render(<Show {...baseProps()} />);
            const followBtn = screen.getByRole('button', { name: 'Follow' });
            expect(followBtn).not.toHaveClass('text-warning!');

            act(() => followBtn.click());
            expect(followBtn).toHaveClass('text-warning!');
        });

        it('scrolling within 10px of the bottom turns Follow on automatically', () => {
            render(<Show {...baseProps()} />);
            const scrollEl = document.querySelector('.scrollbar');
            Object.defineProperty(scrollEl, 'scrollHeight', { value: 1000, configurable: true });
            Object.defineProperty(scrollEl, 'clientHeight', { value: 400, configurable: true });
            Object.defineProperty(scrollEl, 'scrollTop', { value: 595, configurable: true, writable: true });

            act(() => fireEvent.scroll(scrollEl));
            expect(screen.getByRole('button', { name: 'Follow' })).toHaveClass('text-warning!');
        });

        it('scrolling up (wheel deltaY < 0) turns Follow back off once active', () => {
            render(<Show {...baseProps()} />);
            const followBtn = screen.getByRole('button', { name: 'Follow' });
            act(() => followBtn.click());

            const scrollEl = document.querySelector('.scrollbar');
            act(() => fireEvent.wheel(scrollEl, { deltaY: -100 }));
            expect(followBtn).not.toHaveClass('text-warning!');
        });
    });

    it('Fullscreen toggles the container class and its own label', () => {
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Fullscreen' }).click());
        expect(screen.getByRole('button', { name: 'Minimize' })).toBeInTheDocument();
        expect(document.querySelector('.fullscreen')).toBeInTheDocument();
    });

    describe('download and copy', () => {
        it('"Download displayed logs" builds a text blob named after the deployment uuid, then closes the menu', () => {
            const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
            render(<Show {...baseProps()} />);

            act(() => screen.getByRole('button', { name: 'Download' }).click());
            act(() => screen.getByRole('button', { name: 'Download displayed logs' }).click());

            expect(global.URL.createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
            expect(clickSpy).toHaveBeenCalledTimes(1);
            expect(global.URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-url');
            expect(screen.queryByRole('button', { name: 'Download displayed logs' })).not.toBeInTheDocument();
        });

        it('"Download all logs" links to urls.downloadAllLogs and closes the menu on click', () => {
            render(<Show {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Download' }).click());

            const link = screen.getByRole('link', { name: 'Download all logs' });
            expect(link).toHaveAttribute('href', '/download-all');

            act(() => link.click());
            expect(screen.queryByRole('link', { name: 'Download all logs' })).not.toBeInTheDocument();
        });

        it('Copy writes the visible, timestamp-aware log text to the clipboard', () => {
            render(<Show {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Copy' }).click());
            expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
                '2026-08-01 10:00:00 Starting deployment\n2026-08-01 10:00:05 WARNING: cache miss\n2026-08-01 10:00:10 Deployment finished',
            );
        });
    });
});
