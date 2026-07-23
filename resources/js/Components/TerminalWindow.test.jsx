import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TerminalWindow from './TerminalWindow';

// Manually verified live end-to-end during the 2026-07-23 Live SSH terminal smoke test (issue
// #23); this locks that behavior in as automated coverage. TerminalSession itself (WebSocket +
// xterm.js) is unsuitable for jsdom, so it's mocked here - this suite covers TerminalWindow's
// own rendering/wiring: the no-shell panel, the session-countdown badge's warning/danger
// thresholds, the mobile toolbar's six control buttons, the fullscreen/minimize toggle, the
// pendingCommand-changed effect, and mount/unmount lifecycle wiring to the (mocked) session.

let lastSession = null;

vi.mock('../terminalSession', () => {
    class MockTerminalSession {
        constructor(options) {
            this.options = options;
            this.mount = vi.fn();
            this.unmount = vi.fn();
            this.makeFullscreen = vi.fn();
            this.toggleMobileToolbar = vi.fn();
            this.sendTerminalControl = vi.fn();
            this.sendCommandWhenReady = vi.fn();
            lastSession = this;
        }
    }

    return { TerminalSession: MockTerminalSession };
});

function baseProps(overrides = {}) {
    return {
        terminalConfig: { echoHost: 'localhost' },
        pendingCommand: null,
        noShell: false,
        ...overrides,
    };
}

function activeState(overrides = {}) {
    return {
        fullscreen: false,
        terminalActive: true,
        mobileToolbarCollapsed: false,
        terminalSessionRemainingSeconds: null,
        ...overrides,
    };
}

beforeEach(() => {
    lastSession = null;
    window.toast = undefined;
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('TerminalWindow', () => {
    it('mounts a TerminalSession with the given terminalConfig and calls session.mount() with real DOM elements', () => {
        render(<TerminalWindow {...baseProps()} />);

        expect(lastSession).not.toBeNull();
        expect(lastSession.options.terminalConfig).toEqual({ echoHost: 'localhost' });
        expect(lastSession.mount).toHaveBeenCalledTimes(1);
        const [wrapperEl, terminalEl] = lastSession.mount.mock.calls[0];
        expect(wrapperEl).toBeInstanceOf(HTMLElement);
        expect(terminalEl).toBeInstanceOf(HTMLElement);
    });

    it('calls session.unmount() on unmount', () => {
        const { unmount } = render(<TerminalWindow {...baseProps()} />);

        unmount();

        expect(lastSession.unmount).toHaveBeenCalledTimes(1);
    });

    it('shows the "Terminal Not Available" panel when noShell is true', () => {
        render(<TerminalWindow {...baseProps({ noShell: true })} />);

        expect(screen.getByText('Terminal Not Available')).toBeInTheDocument();
        expect(screen.getByText(/No shell \(bash\/sh\) is available/)).toBeInTheDocument();
    });

    it('does not show the "Terminal Not Available" panel when noShell is false', () => {
        render(<TerminalWindow {...baseProps()} />);

        expect(screen.queryByText('Terminal Not Available')).not.toBeInTheDocument();
    });

    it('shows no session-countdown badge or mobile toolbar before the session reports terminalActive', () => {
        render(<TerminalWindow {...baseProps()} />);

        expect(screen.queryByText(/Session expires in/)).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Previous command')).not.toBeInTheDocument();
    });

    it('shows the session-countdown badge once the session reports terminalActive, blank until seconds are known', () => {
        render(<TerminalWindow {...baseProps()} />);

        act(() => lastSession.options.onStateChange(activeState()));

        // terminalSessionRemainingSeconds is null - the badge container renders but the label is empty
        expect(screen.queryByText(/Session expires in/)).not.toBeInTheDocument();

        act(() => lastSession.options.onStateChange(activeState({ terminalSessionRemainingSeconds: 3600 })));
        expect(screen.getByText('Session expires in 1h 00m 00s')).toBeInTheDocument();
    });

    it('applies the danger styling once remaining seconds drops to the danger threshold', () => {
        render(<TerminalWindow {...baseProps()} />);

        act(() => lastSession.options.onStateChange(activeState({ terminalSessionRemainingSeconds: 300 })));

        const badge = screen.getByText('Session expires in 5m 00s');
        expect(badge).toHaveClass('text-red-200');
    });

    it('applies the warning styling once remaining seconds drops to the warning threshold but not danger', () => {
        render(<TerminalWindow {...baseProps()} />);

        act(() => lastSession.options.onStateChange(activeState({ terminalSessionRemainingSeconds: 1800 })));

        const badge = screen.getByText('Session expires in 30m 00s');
        expect(badge).toHaveClass('text-yellow-200');
    });

    it('renders all 6 mobile toolbar keys and calls sendTerminalControl with the right sequence for each', () => {
        render(<TerminalWindow {...baseProps()} />);
        act(() => lastSession.options.onStateChange(activeState()));

        act(() => screen.getByLabelText('Previous command').click());
        expect(lastSession.sendTerminalControl).toHaveBeenLastCalledWith('arrowUp');

        act(() => screen.getByLabelText('Next command').click());
        expect(lastSession.sendTerminalControl).toHaveBeenLastCalledWith('arrowDown');

        act(() => screen.getByLabelText('Move cursor left').click());
        expect(lastSession.sendTerminalControl).toHaveBeenLastCalledWith('arrowLeft');

        act(() => screen.getByLabelText('Move cursor right').click());
        expect(lastSession.sendTerminalControl).toHaveBeenLastCalledWith('arrowRight');

        act(() => screen.getByText('Tab', { exact: true }).click());
        expect(lastSession.sendTerminalControl).toHaveBeenLastCalledWith('tab');

        act(() => screen.getByText('Esc', { exact: true }).click());
        expect(lastSession.sendTerminalControl).toHaveBeenLastCalledWith('escape');
    });

    it('hides the 6 key buttons but keeps the toolbar header when mobileToolbarCollapsed is true', () => {
        render(<TerminalWindow {...baseProps()} />);
        act(() => lastSession.options.onStateChange(activeState({ mobileToolbarCollapsed: true })));

        expect(screen.getByText('Terminal keys')).toBeInTheDocument();
        expect(screen.queryByLabelText('Previous command')).not.toBeInTheDocument();
        // The button's aria-label ("Toggle mobile terminal toolbar") overrides its visible
        // Show/Hide text for accessible-name purposes, so assert the visible label directly.
        expect(screen.getByLabelText('Toggle mobile terminal toolbar')).toHaveTextContent('Show');
    });

    it('toggles the mobile toolbar via the Show/Hide button', () => {
        render(<TerminalWindow {...baseProps()} />);
        act(() => lastSession.options.onStateChange(activeState()));

        const toggleButton = screen.getByLabelText('Toggle mobile terminal toolbar');
        expect(toggleButton).toHaveTextContent('Hide');
        act(() => toggleButton.click());

        expect(lastSession.toggleMobileToolbar).toHaveBeenCalledTimes(1);
    });

    it('shows the Fullscreen button only when not fullscreen and terminalActive, and calls makeFullscreen() on click', () => {
        render(<TerminalWindow {...baseProps()} />);
        act(() => lastSession.options.onStateChange(activeState()));

        const fullscreenBtn = screen.getByTitle('Fullscreen');
        expect(screen.queryByTitle('Minimize')).not.toBeInTheDocument();

        act(() => fullscreenBtn.click());
        expect(lastSession.makeFullscreen).toHaveBeenCalledTimes(1);
    });

    it('shows the Minimize button instead once fullscreen is true, and calls makeFullscreen() on click', () => {
        render(<TerminalWindow {...baseProps()} />);
        act(() => lastSession.options.onStateChange(activeState({ fullscreen: true })));

        expect(screen.queryByTitle('Fullscreen')).not.toBeInTheDocument();
        const minimizeBtn = screen.getByTitle('Minimize');

        act(() => minimizeBtn.click());
        expect(lastSession.makeFullscreen).toHaveBeenCalledTimes(1);
    });

    it('does not call sendCommandWhenReady on initial mount when pendingCommand is null', () => {
        render(<TerminalWindow {...baseProps()} />);

        expect(lastSession.sendCommandWhenReady).not.toHaveBeenCalled();
    });

    it('calls sendCommandWhenReady when pendingCommand changes to a new key', () => {
        const { rerender } = render(<TerminalWindow {...baseProps()} />);

        rerender(<TerminalWindow {...baseProps({ pendingCommand: { command: 'ls -la', key: 1 } })} />);
        expect(lastSession.sendCommandWhenReady).toHaveBeenCalledWith('ls -la');

        lastSession.sendCommandWhenReady.mockClear();
        rerender(<TerminalWindow {...baseProps({ pendingCommand: { command: 'ls -la', key: 1 } })} />);
        expect(lastSession.sendCommandWhenReady).not.toHaveBeenCalled();

        rerender(<TerminalWindow {...baseProps({ pendingCommand: { command: 'whoami', key: 2 } })} />);
        expect(lastSession.sendCommandWhenReady).toHaveBeenCalledWith('whoami');
    });

    it('calls window.toast on error when window.toast is defined', () => {
        window.toast = vi.fn();
        render(<TerminalWindow {...baseProps()} />);

        act(() => lastSession.options.onError('Connection failed'));

        expect(window.toast).toHaveBeenCalledWith('Error', { type: 'danger', description: 'Connection failed' });
    });

    it('does not crash calling onError when window.toast is undefined', () => {
        render(<TerminalWindow {...baseProps()} />);

        expect(() => act(() => lastSession.options.onError('Connection failed'))).not.toThrow();
    });
});
