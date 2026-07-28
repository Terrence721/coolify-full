import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TerminalAccess from './TerminalAccess';

// The /server/{uuid}/security/terminal-access page, live-verified end-to-end during the
// 2026-07-25 Server management smoke test (issue #26): a deliberately-wrong password was
// correctly rejected server-side ("The provided password is incorrect.", status stayed
// "Operational" - proving the password is independently re-validated, not just the typed-name
// match), and the real password correctly flipped the badge both directions. This suite locks
// in the previously-untested frontend logic: the two-prompt confirm-then-password gate, the
// enable/disable action wording, the isAdmin visibility gate, and the status badge.

const putSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        put: (url, data, options) => putSpy(url, data, options),
    },
}));

vi.mock('../../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        serverName: 'production-01',
        isTerminalEnabled: false,
        isAdmin: true,
        toggleUrl: '/server/srv-uuid/security/terminal-access',
        ...overrides,
    };
}

describe('Server/Security/TerminalAccess', () => {
    beforeEach(() => {
        putSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the Disabled badge and "Enable Terminal" button when disabled', () => {
        render(<TerminalAccess {...baseProps({ isTerminalEnabled: false })} />);
        expect(screen.getByText('Disabled')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enable Terminal' })).toBeInTheDocument();
    });

    it('shows the Operational badge and "Disable Terminal" button when enabled', () => {
        render(<TerminalAccess {...baseProps({ isTerminalEnabled: true })} />);
        expect(screen.getByText('Operational')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Disable Terminal' })).toBeInTheDocument();
    });

    it('hides the toggle button for non-admins, but still shows the status badge', () => {
        render(<TerminalAccess {...baseProps({ isAdmin: false, isTerminalEnabled: true })} />);
        expect(screen.queryByRole('button', { name: 'Disable Terminal' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Enable Terminal' })).not.toBeInTheDocument();
        expect(screen.getByText('Operational')).toBeInTheDocument();
    });

    it('does not submit if the typed confirmation does not match the server name', () => {
        vi.spyOn(window, 'prompt').mockReturnValueOnce('wrong-name');
        render(<TerminalAccess {...baseProps({ isTerminalEnabled: false })} />);

        act(() => screen.getByRole('button', { name: 'Enable Terminal' }).click());

        expect(window.prompt).toHaveBeenCalledTimes(1);
        expect(putSpy).not.toHaveBeenCalled();
    });

    it('does not submit if the password prompt is cancelled', () => {
        vi.spyOn(window, 'prompt').mockReturnValueOnce('production-01').mockReturnValueOnce(null);
        render(<TerminalAccess {...baseProps({ isTerminalEnabled: false })} />);

        act(() => screen.getByRole('button', { name: 'Enable Terminal' }).click());

        expect(window.prompt).toHaveBeenCalledTimes(2);
        expect(putSpy).not.toHaveBeenCalled();
    });

    it('submits router.put with the password once both prompts are answered correctly', () => {
        vi.spyOn(window, 'prompt').mockReturnValueOnce('production-01').mockReturnValueOnce('my-password');
        render(<TerminalAccess {...baseProps({ isTerminalEnabled: false, toggleUrl: '/server/srv-uuid/security/terminal-access' })} />);

        act(() => screen.getByRole('button', { name: 'Enable Terminal' }).click());

        expect(putSpy).toHaveBeenCalledWith('/server/srv-uuid/security/terminal-access', { password: 'my-password' }, { preserveScroll: true });
    });

    it('asks to "enable" when currently disabled, and "disable" when currently enabled', () => {
        const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null);

        const { unmount } = render(<TerminalAccess {...baseProps({ isTerminalEnabled: false })} />);
        act(() => screen.getByRole('button', { name: 'Enable Terminal' }).click());
        expect(promptSpy.mock.calls[0][0]).toContain('enable terminal access');
        unmount();

        promptSpy.mockClear();
        render(<TerminalAccess {...baseProps({ isTerminalEnabled: true })} />);
        act(() => screen.getByRole('button', { name: 'Disable Terminal' }).click());
        expect(promptSpy.mock.calls[0][0]).toContain('disable terminal access');
    });
});
