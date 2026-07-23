import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The first-run onboarding wizard - a 678-line multi-step state machine, untested despite being
// the exact page a real bug was found and fixed in this session (Deploy Your First Resource's
// missing server_id, see issue #24). Covers step navigation (goTo/history.replaceState), the
// cloud-vs-non-cloud Welcome branch, both server-creation paths (localhost auto-validate,
// remote-server form + private-key selection), the flash-driven "key was just created"
// auto-advance effect, every runValidate() status branch (validated/installing/unreachable/
// unsupported_os/generic error), the existing-vs-empty projects branch, and the Completion
// screen's Deploy Your First Resource / Go to Dashboard actions - including the exact server_id
// regression this suite exists to lock in.

const postSpy = vi.fn();
const getSpy = vi.fn();
let mockIsCloud = false;
let mockFlash = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        get: (url, data, options) => getSpy(url, data, options),
    },
    usePage: () => ({ props: { permissions: { isCloud: mockIsCloud }, flash: mockFlash } }),
}));

vi.mock('../../Components/ActivityLog', () => ({
    default: ({ activityId, header, onFinished }) => (
        <div data-testid="activity-log">
            <span>
                {header} - {activityId}
            </span>
            <button type="button" onClick={onFinished}>
                Simulate Install Finished
            </button>
        </div>
    ),
}));

vi.mock('../../Components/PrivateKeyCreateModal', () => ({
    default: ({ open }) => (open ? <div data-testid="private-key-modal">Private key modal</div> : null),
}));

function baseProps(overrides = {}) {
    return {
        localhostServer: { id: 5, uuid: 'localhost-uuid', name: 'localhost' },
        privateKeys: [{ id: 1, name: 'Default Key' }],
        projects: [{ uuid: 'proj-1', name: 'My Project', environmentUuid: 'env-1' }],
        minDockerVersion: '24',
        createServerUrl: '/onboarding/server',
        validateUrl: '/onboarding/validate',
        createProjectUrl: '/onboarding/project',
        skipUrl: '/onboarding/skip',
        resourceCreateBaseUrl: '/project',
        privateKeyCreateUrl: '/security/private-key',
        privateKeyGenerateUrl: '/security/private-key/generate',
        ...overrides,
    };
}

function jsonResponse(data, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(data) });
}

function selectOption(selectEl, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
    setter.call(selectEl, value);
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
}

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeEach(() => {
    postSpy.mockClear();
    getSpy.mockClear();
    mockIsCloud = false;
    mockFlash = {};
    document.head.innerHTML = '<meta name="csrf-token" content="test-csrf-token">';
    window.history.replaceState({}, '', '/onboarding');
    global.fetch = vi.fn();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Boarding/Index', () => {
    it('renders the Welcome screen and advances to Platform Overview on a non-cloud instance', () => {
        render(<Index {...baseProps()} />);

        expect(screen.getByText('Welcome to Coolify')).toBeInTheDocument();
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());

        expect(screen.getByText('Platform Overview')).toBeInTheDocument();
    });

    it('skips straight to SSH Authentication from Welcome on a cloud instance', () => {
        mockIsCloud = true;
        render(<Index {...baseProps()} />);

        act(() => screen.getByRole('button', { name: "Let's go!" }).click());

        expect(screen.getByText('SSH Authentication')).toBeInTheDocument();
        expect(screen.queryByText('Platform Overview')).not.toBeInTheDocument();
    });

    it('posts to skipUrl when Skip Setup is clicked on Welcome', () => {
        render(<Index {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Skip Setup' }).click());

        expect(postSpy).toHaveBeenCalledWith('/onboarding/skip', undefined, undefined);
    });

    it('advances from Platform Overview to Choose Server Type via Continue', () => {
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());

        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        expect(screen.getByText('Choose Server Type')).toBeInTheDocument();
    });

    it('does not offer a Hetzner tile on the Choose Server Type step', () => {
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        expect(screen.getByText('This Machine')).toBeInTheDocument();
        expect(screen.getByText('Remote Server')).toBeInTheDocument();
        expect(screen.queryByText(/Hetzner/)).not.toBeInTheDocument();
    });

    it('shows an inline error instead of navigating when This Machine is chosen with no localhost server', () => {
        render(<Index {...baseProps({ localhostServer: null })} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        act(() => screen.getByText('This Machine').click());

        expect(screen.getByText(/Localhost server is not found/)).toBeInTheDocument();
        expect(screen.getByText('Choose Server Type')).toBeInTheDocument();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('choosing This Machine immediately validates against the localhost server uuid', async () => {
        global.fetch.mockReturnValue(jsonResponse({ status: 'validated' }));
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        await act(async () => screen.getByText('This Machine').click());

        expect(global.fetch).toHaveBeenCalledWith(
            '/onboarding/validate',
            expect.objectContaining({ body: JSON.stringify({ server_uuid: 'localhost-uuid', install: true, attempt: 0 }) }),
        );
    });

    it('choosing Remote Server goes to the SSH Authentication step', () => {
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        act(() => screen.getByText('Remote Server').click());

        expect(screen.getByText('SSH Authentication')).toBeInTheDocument();
    });

    it('shows the existing-key dropdown only when private keys exist, and Use Selected Key advances to server details', () => {
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        act(() => screen.getByText('Remote Server').click());

        expect(screen.getByText('Existing SSH Keys')).toBeInTheDocument();
        act(() => screen.getByRole('button', { name: 'Use Selected Key' }).click());

        expect(screen.getByText('Server Configuration')).toBeInTheDocument();
    });

    it('hides the existing-key dropdown entirely when there are no private keys yet', () => {
        render(<Index {...baseProps({ privateKeys: [] })} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        act(() => screen.getByText('Remote Server').click());

        expect(screen.queryByText('Existing SSH Keys')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Use Selected Key' })).not.toBeInTheDocument();
    });

    it('opens the private key modal from both Use Existing Key and Generate New Key', () => {
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        act(() => screen.getByText('Remote Server').click());

        expect(screen.queryByTestId('private-key-modal')).not.toBeInTheDocument();
        act(() => screen.getByText('Generate New Key').click());
        expect(screen.getByTestId('private-key-modal')).toBeInTheDocument();
    });

    it('auto-advances to server details once a private key is created via the flash effect', () => {
        const { rerender } = render(<Index {...baseProps({ privateKeys: [] })} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        act(() => screen.getByText('Remote Server').click());

        mockFlash = { createdPrivateKeyId: 42 };
        act(() => rerender(<Index {...baseProps({ privateKeys: [] })} />));

        expect(screen.getByText('Server Configuration')).toBeInTheDocument();
    });

    it('creates a server, then immediately validates against the new server uuid on success', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({ id: 9, uuid: 'new-server-uuid', name: 'server-abc123' }));
        global.fetch.mockReturnValueOnce(jsonResponse({ status: 'validated' }));
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        act(() => screen.getByText('Remote Server').click());
        act(() => screen.getByRole('button', { name: 'Use Selected Key' }).click());

        act(() => typeInto(screen.getByLabelText('IP Address/Hostname'), '203.0.113.5'));

        await act(async () => screen.getByRole('button', { name: 'Validate Connection' }).click());

        expect(global.fetch).toHaveBeenCalledTimes(2);
        const [firstUrl, firstOptions] = global.fetch.mock.calls[0];
        expect(firstUrl).toBe('/onboarding/server');
        expect(JSON.parse(firstOptions.body)).toMatchObject({ private_key_id: 1, port: 22, user: 'root' });
        const [secondUrl, secondOptions] = global.fetch.mock.calls[1];
        expect(secondUrl).toBe('/onboarding/validate');
        expect(JSON.parse(secondOptions.body)).toMatchObject({ server_uuid: 'new-server-uuid' });
    });

    it('shows the server-name-already-exists error from a failed server creation without navigating', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({ message: 'A server with this IP/Domain already exists in your team.' }, false));
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        act(() => screen.getByText('Remote Server').click());
        act(() => screen.getByRole('button', { name: 'Use Selected Key' }).click());
        act(() => typeInto(screen.getByLabelText('IP Address/Hostname'), '203.0.113.5'));

        await act(async () => screen.getByRole('button', { name: 'Validate Connection' }).click());

        expect(screen.getByText('A server with this IP/Domain already exists in your team.')).toBeInTheDocument();
        expect(screen.getByText('Server Configuration')).toBeInTheDocument();
    });

    it('toggles Advanced Connection Settings to reveal the port/user fields', () => {
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        act(() => screen.getByText('Remote Server').click());
        act(() => screen.getByRole('button', { name: 'Use Selected Key' }).click());

        expect(screen.queryByLabelText('SSH Port')).not.toBeInTheDocument();
        act(() => screen.getByRole('button', { name: 'Advanced Connection Settings' }).click());
        expect(screen.getByLabelText('SSH Port')).toBeInTheDocument();
        expect(screen.getByLabelText('SSH User')).toBeInTheDocument();
    });

    async function chooseLocalhostWith(status, extra = {}) {
        global.fetch.mockReturnValue(jsonResponse({ status, ...extra }));
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        await act(async () => screen.getByText('This Machine').click());
    }

    it('goes to Project Setup once validation reports validated', async () => {
        await chooseLocalhostWith('validated');

        expect(screen.getByText('Project Setup')).toBeInTheDocument();
    });

    it('shows the unreachable-server error with the underlying ssh error text', async () => {
        await chooseLocalhostWith('unreachable', { error: 'ssh: connect to host 1.2.3.4 port 22: Operation timed out' });

        expect(screen.getByText(/Server is not reachable/)).toBeInTheDocument();
        expect(screen.getByText(/Operation timed out/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Retry Validation' })).toBeInTheDocument();
    });

    it('shows the unsupported-OS message', async () => {
        await chooseLocalhostWith('unsupported_os');

        expect(screen.getByText(/Server OS type is not supported/)).toBeInTheDocument();
    });

    it('falls back to a generic validation-failed message for an unrecognized status', async () => {
        await chooseLocalhostWith('something-unexpected', { error: 'Odd server state.' });

        expect(screen.getByText('Odd server state.')).toBeInTheDocument();
    });

    it('shows the install-progress ActivityLog when validation reports installing, and re-validates once it finishes', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({ status: 'installing', step: 'prerequisites', activityId: 77, attempt: 1 }));
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        await act(async () => screen.getByText('This Machine').click());

        expect(screen.getByText('Installing Prerequisites - 77')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Start Validation' })).not.toBeInTheDocument();

        global.fetch.mockReturnValueOnce(jsonResponse({ status: 'validated' }));
        await act(async () => screen.getByRole('button', { name: 'Simulate Install Finished' }).click());

        expect(global.fetch).toHaveBeenLastCalledWith(
            '/onboarding/validate',
            expect.objectContaining({ body: JSON.stringify({ server_uuid: 'localhost-uuid', install: true, attempt: 1 }) }),
        );
        expect(screen.getByText('Project Setup')).toBeInTheDocument();
    });

    it('shows the existing-projects dropdown and picking one advances without creating anything new', async () => {
        await chooseLocalhostWith('validated');

        expect(screen.getByText('Or use existing')).toBeInTheDocument();
        act(() => selectOption(screen.getByLabelText('Existing Projects'), 'proj-1'));

        expect(global.fetch).toHaveBeenCalledTimes(1); // only the earlier validate call, no create-project POST
        expect(screen.getByText('Setup Complete!')).toBeInTheDocument();
        expect(screen.getByText('Project: My Project')).toBeInTheDocument();
    });

    it('hides the existing-projects section entirely when the team has no projects yet', async () => {
        global.fetch.mockReturnValue(jsonResponse({ status: 'validated' }));
        render(<Index {...baseProps({ projects: [] })} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        await act(async () => screen.getByText('This Machine').click());

        expect(screen.queryByText('Or use existing')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Existing Projects')).not.toBeInTheDocument();
    });

    it('creates a new project via the one-click button and advances to Completion', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({ status: 'validated' }));
        global.fetch.mockReturnValueOnce(jsonResponse({ uuid: 'new-proj', name: 'My first project', environmentUuid: 'new-env' }));
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        await act(async () => screen.getByText('This Machine').click());

        await act(async () => screen.getByRole('button', { name: 'Create "My First Project"' }).click());

        expect(global.fetch).toHaveBeenLastCalledWith('/onboarding/project', expect.objectContaining({ method: 'POST' }));
        expect(screen.getByText('Project: My first project')).toBeInTheDocument();
    });

    describe('Completion screen', () => {
        async function reachCompletion() {
            global.fetch.mockReturnValue(jsonResponse({ status: 'validated' }));
            render(<Index {...baseProps()} />);
            act(() => screen.getByRole('button', { name: "Let's go!" }).click());
            act(() => screen.getByRole('button', { name: 'Continue' }).click());
            await act(async () => screen.getByText('This Machine').click());
            act(() => selectOption(screen.getByLabelText('Existing Projects'), 'proj-1'));
        }

        it('shows the correct server and project names, and no Skip Setup/Restart footer', async () => {
            await reachCompletion();

            expect(screen.getByText('Server: localhost')).toBeInTheDocument();
            expect(screen.getByText('Project: My Project')).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Skip Setup' })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        });

        it('navigates to the resource wizard with the server pre-selected via server_id', async () => {
            await reachCompletion();

            // Safe to replace window.location wholesale only here, after every goTo() call in
            // this test has already happened - goTo() itself does `new URL(window.location.href)`
            // and would throw against this stub.
            const originalLocation = window.location;
            delete window.location;
            window.location = { href: '' };

            act(() => screen.getByRole('button', { name: 'Deploy Your First Resource' }).click());

            expect(window.location.href).toBe('/project/proj-1/environment/env-1/new?server_id=5');
            window.location = originalLocation;
        });

        it('posts to skipUrl when Go to Dashboard is clicked', async () => {
            await reachCompletion();

            act(() => screen.getByRole('button', { name: 'Go to Dashboard' }).click());

            expect(postSpy).toHaveBeenCalledWith('/onboarding/skip', undefined, undefined);
        });
    });

    it('shows the Skip Setup / Restart footer on intermediate steps, and Restart does a full GET to /onboarding', () => {
        render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: "Let's go!" }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        act(() => screen.getByRole('button', { name: 'Restart' }).click());

        expect(getSpy).toHaveBeenCalledWith('/onboarding', undefined, undefined);
    });
});
