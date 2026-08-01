import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DangerTab, ResourceLimitsTab, ResourceOperationsTab, ServersTab, TagsTab, WebhooksTab } from './ResourceTabs';

// The 6 shared Configuration-tab panels rendered on every Application/Database/Service
// configuration page - all 3 Configuration.test.jsx suites (Application/Database/Service) mock
// this module out rather than exercise it, so its real logic has had zero coverage despite being
// universally depended on. Picked as the highest-priority remaining suite: broadest blast radius
// of anything left untested (shared across all 3 resource types), and ResourceOperationsTab's
// cascading server->destination / project->environment select-filtering is exactly the shape of
// derived-state logic that's easy to get subtly wrong.

const routerPost = vi.fn();
const routerDelete = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args) => routerPost(...args),
        delete: (...args) => routerDelete(...args),
    },
    useForm: (initial) => {
        let data = initial;
        const setData = (key, value) => {
            data = { ...data, [key]: value };
        };
        return {
            get data() {
                return data;
            },
            setData,
            post: vi.fn((url, opts) => opts?.onSuccess?.()),
            patch: vi.fn(),
            processing: false,
            errors: {},
            reset: vi.fn(),
        };
    },
}));

vi.mock('./PasswordConfirmModal', () => ({
    default: ({ title, onClose }) => (
        <div data-testid="PasswordConfirmModal">
            <div>{title}</div>
            <button type="button" onClick={onClose}>
                close-modal
            </button>
        </div>
    ),
}));

afterEach(() => {
    routerPost.mockClear();
    routerDelete.mockClear();
});

describe('TagsTab', () => {
    it('hides the add-tag form and shows a permission message when canUpdate is false', () => {
        render(<TagsTab tags={[]} availableTags={[]} tagsStoreUrl="/tags" canUpdate={false} />);

        expect(screen.queryByPlaceholderText(/example: prod app1/)).not.toBeInTheDocument();
        expect(screen.getByText(/don't have permission to manage tags/)).toBeInTheDocument();
    });

    it('renders assigned tags and quick-adds an existing tag via router.post', () => {
        render(<TagsTab tags={[{ id: 1, name: 'prod' }]} availableTags={[{ id: 2, name: 'staging' }]} tagsStoreUrl="/tags" canUpdate={true} />);

        expect(screen.getByText('prod')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'staging' }));

        expect(routerPost).toHaveBeenCalledWith('/tags', { tag_id: 2 }, { preserveScroll: true });
    });

    it('deletes an assigned tag via router.delete using the tag-specific destroy URL', () => {
        render(<TagsTab tags={[{ id: 1, name: 'prod', destroyUrl: '/tags/1' }]} availableTags={[]} tagsStoreUrl="/tags" canUpdate={true} />);

        fireEvent.click(screen.getByText('✕'));

        expect(routerDelete).toHaveBeenCalledWith('/tags/1', { preserveScroll: true });
    });
});

describe('DangerTab', () => {
    it('shows a permission message instead of the Delete button when canDelete is false', () => {
        render(<DangerTab resourceName="my-app" canDelete={false} destroyUrl="/apps/1" />);

        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
        expect(screen.getByText(/don't have permission to delete/)).toBeInTheDocument();
    });

    it('opens and closes the password-confirmation modal', () => {
        render(<DangerTab resourceName="my-app" canDelete={true} destroyUrl="/apps/1" />);

        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(screen.getByTestId('PasswordConfirmModal')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'close-modal' }));
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();
    });
});

describe('WebhooksTab', () => {
    it('renders only the read-only deploy webhook when manualWebhooks is absent', () => {
        render(<WebhooksTab deployWebhook="https://example.com/hook" />);

        expect(screen.getByDisplayValue('https://example.com/hook')).toBeInTheDocument();
        expect(screen.queryByText('Manual Git Webhooks')).not.toBeInTheDocument();
    });

    it('shows the official-Git-App message instead of a form when usesOfficialGitApp is true', () => {
        render(
            <WebhooksTab deployWebhook="https://example.com/hook" manualWebhooks={{ usesOfficialGitApp: true, providers: {}, canUpdate: true }} />,
        );

        expect(screen.getByText(/using an official Git App/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });

    it('renders the manual-webhook form and submits secrets when not using an official Git App', () => {
        render(
            <WebhooksTab
                deployWebhook="https://example.com/hook"
                manualWebhooks={{
                    usesOfficialGitApp: false,
                    canUpdate: true,
                    updateUrl: '/webhooks/1',
                    providers: {
                        github: { url: 'https://gh/hook', secret: 'gh-secret' },
                        gitlab: { url: '', secret: '' },
                        bitbucket: { url: '', secret: '' },
                        gitea: { url: '', secret: '' },
                    },
                }}
            />,
        );

        expect(screen.getByDisplayValue('gh-secret')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
    });

    it('disables webhook-secret inputs and hides Save when manual webhooks cannot be updated', () => {
        render(
            <WebhooksTab
                deployWebhook="https://example.com/hook"
                manualWebhooks={{
                    usesOfficialGitApp: false,
                    canUpdate: false,
                    providers: {
                        github: { url: '', secret: '' },
                        gitlab: { url: '', secret: '' },
                        bitbucket: { url: '', secret: '' },
                        gitea: { url: '', secret: '' },
                    },
                }}
            />,
        );

        expect(screen.getByLabelText('GitHub Webhook Secret')).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });
});

describe('ResourceLimitsTab', () => {
    it('disables every field and hides Save when canUpdate is false', () => {
        render(<ResourceLimitsTab limits={{}} limitsUpdateUrl="/limits" canUpdate={false} />);

        expect(screen.getByLabelText('Number of CPUs')).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });

    it('seeds fields from the limits prop, falling back to defaults for missing values', () => {
        render(<ResourceLimitsTab limits={{ limitsCpus: '2' }} limitsUpdateUrl="/limits" canUpdate={true} />);

        expect(screen.getByLabelText('Number of CPUs')).toHaveValue('2');
        expect(screen.getByLabelText('Maximum Memory Limit')).toHaveValue('0');
    });
});

describe('ResourceOperationsTab', () => {
    const servers = [
        {
            id: 1,
            name: 'server-a',
            destinations: [{ id: 10, name: 'dest-a1' }],
        },
        {
            id: 2,
            name: 'server-b',
            destinations: [{ id: 20, name: 'dest-b1' }],
        },
    ];
    const projects = [
        {
            id: 100,
            name: 'current-project',
            environments: [
                { id: 1000, name: 'production' },
                { id: 1001, name: 'staging' },
            ],
        },
        { id: 200, name: 'other-project', environments: [{ id: 2000, name: 'production' }] },
    ];

    function renderTab(overrides = {}) {
        return render(
            <ResourceOperationsTab
                servers={servers}
                projects={projects}
                currentProjectId={100}
                currentEnvironmentId={1000}
                operationUrls={{ clone: '/clone', move: '/move' }}
                canUpdate={true}
                {...overrides}
            />,
        );
    }

    it('shows a permission message and no controls when canUpdate is false', () => {
        renderTab({ canUpdate: false });

        expect(screen.queryByLabelText('Select Server')).not.toBeInTheDocument();
        expect(screen.getByText(/don't have permission to clone or move/)).toBeInTheDocument();
    });

    it('scopes the destination dropdown to the selected server, and resets it when the server changes', () => {
        renderTab();

        fireEvent.change(screen.getByLabelText('Select Server'), { target: { value: '1' } });
        expect(screen.getByRole('option', { name: 'dest-a1' })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: 'dest-b1' })).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Select Network Destination'), { target: { value: '10' } });
        expect(screen.getByRole('button', { name: 'Clone Resource' })).toBeInTheDocument();

        // Switching servers must reset the now-stale destination selection and hide Clone again.
        fireEvent.change(screen.getByLabelText('Select Server'), { target: { value: '2' } });
        expect(screen.queryByRole('button', { name: 'Clone Resource' })).not.toBeInTheDocument();
    });

    it('posts the selected destination to operationUrls.clone', () => {
        renderTab();

        fireEvent.change(screen.getByLabelText('Select Server'), { target: { value: '1' } });
        fireEvent.change(screen.getByLabelText('Select Network Destination'), { target: { value: '10' } });
        fireEvent.click(screen.getByRole('button', { name: 'Clone Resource' }));

        expect(routerPost).toHaveBeenCalledWith('/clone', { destination_id: '10', clone_volume_data: false }, { preserveScroll: true });
    });

    it('excludes the current environment from the target list only when moving within the current project', () => {
        renderTab();

        // Same project as the resource lives in: current environment (production/1000) excluded.
        fireEvent.change(screen.getByLabelText('Select Target Project'), { target: { value: '100' } });
        expect(screen.queryByRole('option', { name: 'production' })).not.toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'staging' })).toBeInTheDocument();

        // A different project: its own "production" environment is a distinct row, not excluded.
        fireEvent.change(screen.getByLabelText('Select Target Project'), { target: { value: '200' } });
        expect(screen.getByRole('option', { name: 'production' })).toBeInTheDocument();
    });

    it('posts the selected environment to operationUrls.move', () => {
        renderTab();

        fireEvent.change(screen.getByLabelText('Select Target Project'), { target: { value: '100' } });
        fireEvent.change(screen.getByLabelText('Select Target Environment (current is excluded)'), { target: { value: '1001' } });
        fireEvent.click(screen.getByRole('button', { name: 'Move Resource' }));

        expect(routerPost).toHaveBeenCalledWith('/move', { environment_id: '1001' }, { preserveScroll: true });
    });
});

describe('ServersTab', () => {
    it('shows a running badge when the primary server status starts with running', () => {
        render(<ServersTab primaryServer={{ name: 'server-a', network: 'coolify', status: 'running:healthy' }} />);

        expect(screen.getByTitle('running:healthy')).toHaveClass('bg-success');
    });

    it('shows an exited badge when the primary server status starts with exited', () => {
        render(<ServersTab primaryServer={{ name: 'server-a', network: 'coolify', status: 'exited' }} />);

        expect(screen.getByTitle('exited')).toHaveClass('bg-error');
    });

    it('shows neither badge for an unrecognized status', () => {
        render(<ServersTab primaryServer={{ name: 'server-a', network: 'coolify', status: 'degraded:unhealthy' }} />);

        expect(screen.queryByTitle('degraded:unhealthy')).not.toBeInTheDocument();
    });
});
