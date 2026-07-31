import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The /terminal page, fully live-verified end-to-end via a real Playwright session (issue #23,
// closed 9/9): server/container selection, a real "Connect" round-trip producing a genuine shell
// prompt, and the no-shell-container case producing a real 422 + the "Terminal Not Available"
// panel. This suite locks that flow in as automated coverage; the page itself was previously
// entirely untested. TerminalWindow has its own dedicated suite (TerminalWindow.test.jsx), so
// it's mocked here to isolate this page's own connect()/select logic.

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Deferred: ({ children }) => children,
}));

let lastTerminalWindowProps = null;
vi.mock('../../Components/TerminalWindow', () => ({
    default: (props) => {
        lastTerminalWindowProps = props;

        return <div data-testid="terminal-window" />;
    },
}));

function baseProps(overrides = {}) {
    return {
        servers: [],
        containers: [],
        terminalConfig: { wsHost: 'localhost' },
        connectUrl: '/terminal/connect',
        ...overrides,
    };
}

function server(uuid, name) {
    return { uuid, name };
}

function container(uuid, name, serverUuid) {
    return { uuid, name, server_uuid: serverUuid };
}

describe('Terminal/Index', () => {
    beforeEach(() => {
        lastTerminalWindowProps = null;
        document.head.innerHTML = '<meta name="csrf-token" content="test-csrf-token">';
        global.fetch = vi.fn();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '';
    });

    it('shows "No servers with terminal access found." when there are none', () => {
        render(<Index {...baseProps()} />);
        expect(screen.getByText('No servers with terminal access found.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Connect' })).not.toBeInTheDocument();
    });

    it('renders each server as an optgroup with its own containers nested inside', () => {
        render(
            <Index
                {...baseProps({
                    servers: [server('srv-1', 'production-01'), server('srv-2', 'staging-01')],
                    containers: [container('cnt-1', 'app', 'srv-1'), container('cnt-2', 'db', 'srv-2')],
                })}
            />,
        );

        expect(screen.getByRole('option', { name: 'production-01' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'production-01 -> app' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'staging-01' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'staging-01 -> db' })).toBeInTheDocument();
        // srv-2's container must not leak under srv-1's optgroup.
        expect(screen.getByRole('option', { name: 'production-01 -> app' }).closest('optgroup').label).toBe('production-01');
    });

    it('blocks submit with an inline error when nothing is selected, without calling fetch', () => {
        render(<Index {...baseProps({ servers: [server('srv-1', 'production-01')] })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        expect(screen.getByText('Please select a server or a container.')).toBeInTheDocument();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('posts the selected uuid with the CSRF token and hands the returned command to TerminalWindow', async () => {
        global.fetch.mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ command: 'ssh production-01' }),
        });

        render(<Index {...baseProps({ servers: [server('srv-1', 'production-01')] })} />);

        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'srv-1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        expect(global.fetch).toHaveBeenCalledWith(
            '/terminal/connect',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'test-csrf-token' }),
                body: JSON.stringify({ selected_uuid: 'srv-1' }),
            }),
        );

        await screen.findByTestId('terminal-window');
        expect(lastTerminalWindowProps.pendingCommand).toMatchObject({ command: 'ssh production-01' });
        expect(lastTerminalWindowProps.noShell).toBe(false);
    });

    it('shows the "Terminal Not Available" no-shell case for a 422 with reason "no-shell"', async () => {
        global.fetch.mockResolvedValue({
            ok: false,
            json: () => Promise.resolve({ error: 'This container has no shell available.', reason: 'no-shell' }),
        });

        render(<Index {...baseProps({ servers: [server('srv-1', 'production-01')] })} />);

        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'srv-1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        expect(await screen.findByText('This container has no shell available.')).toBeInTheDocument();
        expect(lastTerminalWindowProps.noShell).toBe(true);
    });

    it('shows a generic error when the fetch itself fails (network error)', async () => {
        global.fetch.mockRejectedValue(new Error('network down'));

        render(<Index {...baseProps({ servers: [server('srv-1', 'production-01')] })} />);

        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'srv-1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        expect(await screen.findByText('Failed to connect.')).toBeInTheDocument();
    });
});
