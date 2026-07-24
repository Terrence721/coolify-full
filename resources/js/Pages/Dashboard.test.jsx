import { render, screen, within } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard';

// The default (empty) Projects section also renders its own "Add" button, so server-section
// assertions need to be scoped to the Servers <section> specifically to avoid ambiguous matches.
function serversSection() {
    return screen.getByText('Servers').closest('section');
}

function projectsHeader() {
    return screen.getByRole('heading', { name: 'Projects' }).closest('div');
}

function serversHeader() {
    return screen.getByRole('heading', { name: 'Servers' }).closest('div');
}

// Regression coverage for a real bug found while writing this suite: the "No projects found",
// "No private keys found", and "No servers found" empty states each rendered their inline "Add"
// button unconditionally, with zero permission check - unlike the equivalent header "Add" buttons
// on the same page, which correctly gate on canCreateProject/canCreateServer. PrivateKey::create()
// is genuinely admin-only right now (app/Policies/PrivateKeyPolicy.php), so a plain member landing
// on an empty dashboard would see a clickable "add a private key" button that hits a real 403 on
// submit, with the same silent-failure shape as other bugs found this session. Fixed by adding a
// canCreateKey prop and gating all three empty-state buttons the same way the non-empty-state ones
// already were.

const addProjectModalSpy = vi.fn();
const addServerModalSpy = vi.fn();
const privateKeyModalSpy = vi.fn();

vi.mock('../Components/AddProjectModal', () => ({
    default: (props) => {
        addProjectModalSpy(props);
        return (
            <div data-testid="add-project-modal">
                <button type="button" onClick={props.onClose}>
                    Close Project Modal
                </button>
            </div>
        );
    },
}));

vi.mock('../Components/AddServerModal', () => ({
    default: (props) => {
        addServerModalSpy(props);
        return (
            <div data-testid="add-server-modal">
                <button type="button" onClick={props.onClose}>
                    Close Server Modal
                </button>
            </div>
        );
    },
}));

vi.mock('../Components/PrivateKeyCreateModal', () => ({
    default: (props) => {
        privateKeyModalSpy(props);
        return props.open ? <div data-testid="private-key-modal">Private key modal</div> : null;
    },
}));

function project(overrides = {}) {
    return {
        uuid: 'proj-1',
        name: 'E-Commerce Platform',
        description: 'Customer-facing storefront',
        navigateUrl: '/project/proj-1',
        addResourceUrl: '/project/proj-1/environment/env-1/new',
        canUpdate: true,
        editUrl: '/project/proj-1/edit',
        ...overrides,
    };
}

function server(overrides = {}) {
    return {
        uuid: 'server-1',
        name: 'production-01',
        description: 'Primary Docker host',
        isReachable: true,
        isUsable: true,
        forceDisabled: false,
        showUrl: '/server/server-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        projects: [],
        servers: [],
        privateKeys: [],
        canCreateProject: true,
        canCreateServer: true,
        canCreateKey: true,
        defaultServerName: 'random-name',
        defaultPrivateKeyId: 1,
        createProjectUrl: '/projects',
        createServerUrl: '/servers',
        createKeyUrl: '/security/private-key',
        generateKeyUrl: '/security/private-key/generate',
        onboardingUrl: '/onboarding',
        ...overrides,
    };
}

describe('Dashboard', () => {
    beforeEach(() => {
        addProjectModalSpy.mockClear();
        addServerModalSpy.mockClear();
        privateKeyModalSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('Projects section', () => {
        it('renders each project with name, description, and navigateUrl', () => {
            render(<Dashboard {...baseProps({ projects: [project()] })} />);
            expect(screen.getByText('E-Commerce Platform')).toBeInTheDocument();
            expect(screen.getByText('Customer-facing storefront')).toBeInTheDocument();
            const link = document.querySelector('a[href="/project/proj-1"]');
            expect(link).toBeInTheDocument();
        });

        it('shows "+ Add Resource" only when addResourceUrl is present, and "Settings" only when canUpdate', () => {
            const { unmount } = render(<Dashboard {...baseProps({ projects: [project({ addResourceUrl: null, canUpdate: false })] })} />);
            expect(screen.queryByRole('link', { name: '+ Add Resource' })).not.toBeInTheDocument();
            expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
            unmount();

            render(<Dashboard {...baseProps({ projects: [project()] })} />);
            expect(screen.getByRole('link', { name: '+ Add Resource' })).toBeInTheDocument();
            expect(screen.getByRole('link', { name: 'Settings' })).toBeInTheDocument();
        });

        it('shows the header "Add" button only when there are projects and canCreateProject is true', () => {
            const { unmount } = render(<Dashboard {...baseProps({ projects: [], canCreateProject: true })} />);
            expect(within(projectsHeader()).queryByRole('button', { name: 'Add' })).not.toBeInTheDocument();
            unmount();

            render(<Dashboard {...baseProps({ projects: [project()], canCreateProject: false })} />);
            expect(within(projectsHeader()).queryByRole('button', { name: 'Add' })).not.toBeInTheDocument();
        });

        it('shows the empty-state Add button and normal copy when canCreateProject is true', () => {
            render(<Dashboard {...baseProps({ projects: [], canCreateProject: true })} />);
            expect(screen.getByText('No projects found.')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
            expect(screen.getByText(/your first project or go to the/)).toBeInTheDocument();
        });

        it('hides the empty-state Add button and shows an admin-contact message when canCreateProject is false', () => {
            render(<Dashboard {...baseProps({ projects: [], canCreateProject: false })} />);
            expect(screen.getByText('No projects found.')).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Add' })).not.toBeInTheDocument();
            expect(screen.getByText(/Contact your team administrator, or go to the/)).toBeInTheDocument();
        });

        it('opens and closes AddProjectModal via the empty-state Add button', () => {
            render(<Dashboard {...baseProps({ projects: [], canCreateProject: true })} />);
            expect(screen.queryByTestId('add-project-modal')).not.toBeInTheDocument();

            act(() => screen.getByRole('button', { name: 'Add' }).click());
            expect(screen.getByTestId('add-project-modal')).toBeInTheDocument();
            expect(addProjectModalSpy).toHaveBeenCalledWith(expect.objectContaining({ createUrl: '/projects' }));

            act(() => screen.getByRole('button', { name: 'Close Project Modal' }).click());
            expect(screen.queryByTestId('add-project-modal')).not.toBeInTheDocument();
        });
    });

    describe('Servers section', () => {
        it('renders each server with a red border only when unreachable or force-disabled', () => {
            const { unmount } = render(<Dashboard {...baseProps({ servers: [server()] })} />);
            expect(document.querySelector('a[href="/server/server-1"]').className).not.toContain('border-red-500');
            unmount();

            render(<Dashboard {...baseProps({ servers: [server({ isReachable: false })] })} />);
            expect(document.querySelector('a[href="/server/server-1"]').className).toContain('border-red-500');
            expect(screen.getByText('Not reachable')).toBeInTheDocument();
        });

        it('shows the header "Add" button only when servers, private keys, and canCreateServer all allow it', () => {
            const cases = [
                { servers: [], privateKeys: [{ id: 1, name: 'k' }], canCreateServer: true },
                { servers: [server()], privateKeys: [], canCreateServer: true },
                { servers: [server()], privateKeys: [{ id: 1, name: 'k' }], canCreateServer: false },
            ];
            for (const c of cases) {
                const { unmount } = render(<Dashboard {...baseProps({ projects: [project()], ...c })} />);
                expect(within(serversHeader()).queryByRole('button', { name: 'Add' })).not.toBeInTheDocument();
                unmount();
            }

            render(
                <Dashboard
                    {...baseProps({ projects: [project()], servers: [server()], privateKeys: [{ id: 1, name: 'k' }], canCreateServer: true })}
                />,
            );
            expect(within(serversHeader()).getByRole('button', { name: 'Add' })).toBeInTheDocument();
        });

        it('shows the "no private keys" empty state, gated by canCreateKey', () => {
            const { unmount } = render(<Dashboard {...baseProps({ projects: [project()], servers: [], privateKeys: [], canCreateKey: true })} />);
            expect(screen.getByText('No private keys found.')).toBeInTheDocument();
            expect(within(serversSection()).getByRole('button', { name: 'add' })).toBeInTheDocument();
            unmount();

            render(<Dashboard {...baseProps({ projects: [project()], servers: [], privateKeys: [], canCreateKey: false })} />);
            expect(screen.getByText('No private keys found.')).toBeInTheDocument();
            expect(within(serversSection()).queryByRole('button', { name: 'add' })).not.toBeInTheDocument();
            expect(screen.getByText(/Contact your team administrator to add a private key/)).toBeInTheDocument();
        });

        it('opens the PrivateKeyCreateModal via the empty-state "add" button', () => {
            render(<Dashboard {...baseProps({ projects: [project()], servers: [], privateKeys: [], canCreateKey: true })} />);
            expect(screen.queryByTestId('private-key-modal')).not.toBeInTheDocument();

            act(() => within(serversSection()).getByRole('button', { name: 'add' }).click());
            expect(screen.getByTestId('private-key-modal')).toBeInTheDocument();
        });

        it('shows the "no servers" empty state (private keys exist), gated by canCreateServer', () => {
            const { unmount } = render(
                <Dashboard {...baseProps({ projects: [project()], servers: [], privateKeys: [{ id: 1, name: 'k' }], canCreateServer: true })} />,
            );
            expect(screen.getByText('No servers found.')).toBeInTheDocument();
            expect(within(serversSection()).getByRole('button', { name: 'Add' })).toBeInTheDocument();
            unmount();

            render(<Dashboard {...baseProps({ projects: [project()], servers: [], privateKeys: [{ id: 1, name: 'k' }], canCreateServer: false })} />);
            expect(screen.getByText('No servers found.')).toBeInTheDocument();
            expect(within(serversSection()).queryByRole('button', { name: 'Add' })).not.toBeInTheDocument();
            expect(screen.getByText(/Contact your team administrator, or go to the/)).toBeInTheDocument();
        });

        it('opens and closes AddServerModal via the empty-state Add button, passing privateKeys through', () => {
            render(<Dashboard {...baseProps({ projects: [project()], servers: [], privateKeys: [{ id: 1, name: 'k' }], canCreateServer: true })} />);

            act(() => within(serversSection()).getByRole('button', { name: 'Add' }).click());
            expect(screen.getByTestId('add-server-modal')).toBeInTheDocument();
            expect(addServerModalSpy).toHaveBeenCalledWith(expect.objectContaining({ privateKeys: [{ id: 1, name: 'k' }], storeUrl: '/servers' }));

            act(() => screen.getByRole('button', { name: 'Close Server Modal' }).click());
            expect(screen.queryByTestId('add-server-modal')).not.toBeInTheDocument();
        });
    });
});
