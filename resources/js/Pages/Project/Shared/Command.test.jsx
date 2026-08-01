import { render, screen, waitFor } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Command from './Command';

// The application/database/service terminal-connect page - the project-resource sibling of
// Server/Command.jsx (already 8 tests), sharing the same connect() state machine but adding a
// container picker (containers.length===0 gate, a client-side "must select a container" check)
// in place of Server/Command's isFunctional/isTerminalEnabled gate. TerminalWindow.jsx (already
// its own suite) is mocked out, same as every other page that embeds it - this suite covers
// Command's own container-select + connect() wiring: the fetch call, the connecting/disabled
// button state, and the success/error/no-shell/network-failure branches that feed TerminalWindow
// its pendingCommand/noShell props.

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

const terminalWindowProps = [];
vi.mock('../../../Components/TerminalWindow', () => ({
    default: (props) => {
        terminalWindowProps.push(props);
        return <div data-testid="terminal-window">{props.noShell ? 'no-shell' : 'has-shell'}</div>;
    },
}));

function baseProps(overrides = {}) {
    return {
        title: 'my-app',
        containers: [
            { name: 'my-app-abc123', serverName: 'production-01' },
            { name: 'my-app-abc123-preview', serverName: 'production-01' },
        ],
        terminalConfig: { host: 'coolify-realtime' },
        connectUrl: '/project/proj-1/environment/env-1/application/app-1/command/connect',
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

function selectContainer(name) {
    const select = screen.getByRole('combobox');
    select.value = name;
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('Project/Shared/Command', () => {
    beforeEach(() => {
        terminalWindowProps.length = 0;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the "no containers" message and no form when containers is empty, but still renders TerminalWindow', () => {
        render(<Command {...baseProps({ containers: [] })} />);
        expect(screen.getByText('No containers are running or terminal access is disabled on this server.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Connect' })).not.toBeInTheDocument();
        // Unlike Server/Command.jsx, TerminalWindow isn't gated behind the container check here -
        // it's always mounted so a previously-open session (from before containers dropped to 0)
        // stays visible.
        expect(screen.getByTestId('terminal-window')).toBeInTheDocument();
    });

    it('shows the container select, Connect button, and TerminalWindow when containers are present', () => {
        render(<Command {...baseProps()} />);
        expect(screen.getByRole('combobox')).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'my-app-abc123 (production-01)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect' })).toBeInTheDocument();
        expect(screen.getByTestId('terminal-window')).toBeInTheDocument();
    });

    it('shows a client-side error and never calls fetch when submitting without selecting a container', async () => {
        global.fetch = vi.fn();
        render(<Command {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(screen.getByText('Please select a container.')).toBeInTheDocument();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('calls fetch(connectUrl) with the selected container, and feeds the resulting command to TerminalWindow', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ command: 'bash -l' }) }));
        render(<Command {...baseProps()} />);

        act(() => selectContainer('my-app-abc123-preview'));
        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(global.fetch).toHaveBeenCalledWith(
            '/project/proj-1/environment/env-1/application/app-1/command/connect',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'Content-Type': 'application/json', Accept: 'application/json' }),
                body: JSON.stringify({ selected_container: 'my-app-abc123-preview' }),
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

        act(() => selectContainer('my-app-abc123'));
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

        act(() => selectContainer('my-app-abc123'));
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

        act(() => selectContainer('my-app-abc123'));
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

        act(() => selectContainer('my-app-abc123'));
        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(screen.getByText('Failed to connect.')).toBeInTheDocument();
    });

    it('clears a prior no-shell state on a fresh connect attempt that succeeds', async () => {
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: false,
                json: () => Promise.resolve({ error: 'No shell available in this container.', reason: 'no-shell' }),
            }),
        );
        render(<Command {...baseProps()} />);

        act(() => selectContainer('my-app-abc123'));
        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });
        expect(terminalWindowProps[terminalWindowProps.length - 1].noShell).toBe(true);

        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ command: 'bash -l' }) }));
        await act(async () => {
            screen.getByRole('button', { name: 'Connect' }).click();
        });

        expect(terminalWindowProps[terminalWindowProps.length - 1].noShell).toBe(false);
    });

    it('gives each successful connect a fresh pendingCommand key, so TerminalWindow reconnects even to the same command', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ command: 'bash -l' }) }));
        render(<Command {...baseProps()} />);

        act(() => selectContainer('my-app-abc123'));
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
