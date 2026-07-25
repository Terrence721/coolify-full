import { render, screen, waitFor } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Command from './Command';

// The server-itself Command terminal, live-verified 2026-07-25 during the Server management
// smoke test (issue #26): connecting against the real production-01 server produced a genuine
// shell prompt and real streamed output. TerminalWindow.jsx (already 17 tests) is mocked out here,
// same as every other page that embeds it - this suite covers Command's own connect() wiring:
// the fetch call, the connecting/disabled button state, and the success/error/no-shell/network-
// failure branches that feed TerminalWindow its pendingCommand/noShell props.

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));

const terminalWindowProps = [];
vi.mock('../../Components/TerminalWindow', () => ({
    default: (props) => {
        terminalWindowProps.push(props);
        return <div data-testid="terminal-window">{props.noShell ? 'no-shell' : 'has-shell'}</div>;
    },
}));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        isFunctional: true,
        isTerminalEnabled: true,
        terminalConfig: { host: 'coolify-realtime' },
        connectUrl: '/server/localhost/terminal/connect',
        ...overrides,
    };
}

function deferred() {
    let resolve;
    const promise = new Promise((res) => {
        resolve = res;
    });
    return { promise, resolve };
}

describe('Server/Command', () => {
    beforeEach(() => {
        terminalWindowProps.length = 0;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the Terminal form and TerminalWindow when the server is functional and terminal access is enabled', () => {
        render(<Command {...baseProps()} />);
        expect(screen.getByText('Terminal')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect' })).toBeInTheDocument();
        expect(screen.getByTestId('terminal-window')).toBeInTheDocument();
    });

    it('shows a plain message instead, when the server is not functional', () => {
        render(<Command {...baseProps({ isFunctional: false })} />);
        expect(screen.getByText('Server is not functional or terminal access is disabled.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Connect' })).not.toBeInTheDocument();
        expect(screen.queryByTestId('terminal-window')).not.toBeInTheDocument();
    });

    it('shows the same plain message when terminal access is disabled', () => {
        render(<Command {...baseProps({ isTerminalEnabled: false })} />);
        expect(screen.getByText('Server is not functional or terminal access is disabled.')).toBeInTheDocument();
    });

    it('calls fetch(connectUrl) with the right method/headers on Connect, and feeds the resulting command to TerminalWindow', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ command: 'bash -l' }) }));
        render(<Command {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(global.fetch).toHaveBeenCalledWith(
            '/server/localhost/terminal/connect',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'Content-Type': 'application/json', Accept: 'application/json' }),
            }),
        );
        const lastProps = terminalWindowProps[terminalWindowProps.length - 1];
        expect(lastProps.pendingCommand).toEqual(expect.objectContaining({ command: 'bash -l' }));
        expect(lastProps.noShell).toBe(false);
    });

    it('shows "Connecting..." and disables the button while the connect request is in flight', async () => {
        const { promise, resolve } = deferred();
        global.fetch = vi.fn(() => promise);
        render(<Command {...baseProps()} />);

        const button = screen.getByRole('button', { name: 'Connect' });
        act(() => {
            button.click();
        });

        expect(screen.getByRole('button', { name: 'Connecting...' })).toBeDisabled();

        await act(async () => {
            resolve({ ok: true, json: () => Promise.resolve({ command: 'bash -l' }) });
            await promise;
        });

        await waitFor(() => expect(screen.getByRole('button', { name: 'Connect' })).not.toBeDisabled());
    });

    it('shows the real error message and passes noShell through, for a no-shell rejection', async () => {
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: false,
                json: () => Promise.resolve({ error: 'No shell available in this container.', reason: 'no-shell' }),
            }),
        );
        render(<Command {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(screen.getByText('No shell available in this container.')).toBeInTheDocument();
        const lastProps = terminalWindowProps[terminalWindowProps.length - 1];
        expect(lastProps.noShell).toBe(true);
    });

    it('shows a generic error for a non-no-shell rejection, without setting noShell', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, json: () => Promise.resolve({ error: 'Server unreachable.' }) }));
        render(<Command {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(screen.getByText('Server unreachable.')).toBeInTheDocument();
        const lastProps = terminalWindowProps[terminalWindowProps.length - 1];
        expect(lastProps.noShell).toBe(false);
    });

    it('shows a generic "Failed to connect." error when the fetch itself throws', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('network down')));
        render(<Command {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(screen.getByText('Failed to connect.')).toBeInTheDocument();
    });

    it('gives each successful connect a fresh pendingCommand key, so TerminalWindow reconnects even to the same command', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ command: 'bash -l' }) }));
        render(<Command {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });
        const firstKey = terminalWindowProps[terminalWindowProps.length - 1].pendingCommand.key;

        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });
        const secondKey = terminalWindowProps[terminalWindowProps.length - 1].pendingCommand.key;

        expect(secondKey).toBeGreaterThanOrEqual(firstKey);
    });
});
