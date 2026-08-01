import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import GithubPrivateRepository from './GithubPrivateRepository';

// A 244-line multi-step GitHub App connection wizard on the high-traffic "create application"
// path: cascading async fetches (app -> repositories -> branches, each with its own loading/error
// state), a memoized case-insensitive repository filter, disabled-button gating tied to async
// state, and default-seeding (selected repository, git_branch) from fetch responses.

const postSpy = vi.fn();
vi.mock('@inertiajs/react', () => ({
    useForm: () => {
        const state = mockFormState;
        return {
            data: state.data,
            setData: (a, b) => {
                if (typeof a === 'string') {
                    state.data = { ...state.data, [a]: b };
                } else {
                    state.data = a;
                }
            },
            post: (url, options) => postSpy(url, options),
            processing: false,
            errors: {},
        };
    },
}));

vi.mock('../../../Components/GitApplicationFields', () => ({
    default: ({ children }) => <div data-testid="git-application-fields">{children}</div>,
}));

vi.mock('../../../Components/GithubAppCreateModal', () => ({
    default: ({ open }) => (open ? <div data-testid="create-app-modal">Create App Modal</div> : null),
}));

let mockFormState;

function baseProps(overrides = {}) {
    return {
        githubApps: [{ id: 1, name: 'my-github-app', htmlUrl: 'https://github.com/apps/my-github-app' }],
        githubAppStoreUrl: '/security/github-apps',
        githubAppDefaultName: 'coolify',
        isCloud: false,
        repositoriesUrl: '/github-apps/repositories',
        branchesUrl: '/github-apps/branches',
        submitUrl: '/project/new/github-private',
        ...overrides,
    };
}

function jsonResponse(data, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(data) });
}

beforeEach(() => {
    mockFormState = {
        data: {
            github_app_id: null,
            repository_id: null,
            owner: '',
            repo: '',
            git_branch: '',
            port: 3000,
            is_static: false,
            publish_directory: '',
            build_pack: 'nixpacks',
            base_directory: '/',
            docker_compose_location: '/docker-compose.yaml',
        },
    };
    global.fetch = vi.fn();
});

afterEach(() => {
    postSpy.mockClear();
    vi.restoreAllMocks();
});

it('shows the empty-app hero when there are no GitHub Apps', () => {
    render(<GithubPrivateRepository {...baseProps({ githubApps: [] })} />);
    expect(screen.getByText('No GitHub Application found. Please create a new GitHub Application.')).toBeInTheDocument();
});

it('loads repositories for the clicked app and advances to the repository step', async () => {
    global.fetch.mockReturnValue(
        jsonResponse({
            repositories: [
                { id: 10, owner: 'acme', name: 'widgets' },
                { id: 11, owner: 'acme', name: 'gadgets' },
            ],
            installationUrl: 'https://github.com/settings/installations/1',
        }),
    );

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));

    expect(await screen.findByPlaceholderText('Search repositories...')).toBeInTheDocument();
    expect(global.fetch).toHaveBeenCalledWith('/github-apps/repositories?github_app_id=1', { headers: { Accept: 'application/json' } });
    expect(screen.getByRole('option', { name: 'widgets' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'gadgets' })).toBeInTheDocument();
    expect(screen.getByText('Change Repositories on GitHub ↗').closest('a')).toHaveAttribute('href', 'https://github.com/settings/installations/1');
});

it('shows a loading indicator only next to the app currently being loaded', async () => {
    let resolveFetch;
    global.fetch.mockReturnValue(new Promise((resolve) => (resolveFetch = resolve)));

    render(
        <GithubPrivateRepository
            {...baseProps({
                githubApps: [
                    { id: 1, name: 'app-one', htmlUrl: 'https://github.com/apps/app-one' },
                    { id: 2, name: 'app-two', htmlUrl: 'https://github.com/apps/app-two' },
                ],
            })}
        />,
    );
    fireEvent.click(screen.getByText('app-one'));

    expect(await screen.findByText('Loading...')).toBeInTheDocument();
    const rowOne = screen.getByText('app-one').closest('.coolbox').parentElement;
    const rowTwo = screen.getByText('app-two').closest('.coolbox').parentElement;
    expect(within(rowOne).queryByText('Loading...')).toBeInTheDocument();
    expect(within(rowTwo).queryByText('Loading...')).not.toBeInTheDocument();

    resolveFetch(jsonResponse({ repositories: [], installationUrl: null }));
    await waitFor(() => expect(screen.queryByText('Loading...')).not.toBeInTheDocument());
});

it('shows the load error message when the repositories request fails', async () => {
    global.fetch.mockReturnValue(jsonResponse({ message: 'GitHub App is not installed on any repositories.' }, false));

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));

    expect(await screen.findByText('GitHub App is not installed on any repositories.')).toBeInTheDocument();
});

it('falls back to a generic error message when the repositories request throws', async () => {
    global.fetch.mockRejectedValue(new Error('network down'));

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));

    expect(await screen.findByText('Failed to load repositories.')).toBeInTheDocument();
});

it('filters the repository select case-insensitively as the filter input changes', async () => {
    global.fetch.mockReturnValue(
        jsonResponse({
            repositories: [
                { id: 10, owner: 'acme', name: 'Widgets' },
                { id: 11, owner: 'acme', name: 'gadgets' },
            ],
            installationUrl: null,
        }),
    );

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));
    await screen.findByPlaceholderText('Search repositories...');

    expect(screen.getByRole('option', { name: 'Widgets' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'gadgets' })).toBeInTheDocument();

    fireEvent.change(screen.getByPlaceholderText('Search repositories...'), { target: { value: 'WID' } });

    expect(screen.getByRole('option', { name: 'Widgets' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'gadgets' })).not.toBeInTheDocument();
});

it('disables Load Repository while its branch fetch is in flight, then seeds the form from the response', async () => {
    global.fetch.mockReturnValueOnce(
        jsonResponse({
            repositories: [{ id: 10, owner: 'acme', name: 'widgets' }],
            installationUrl: null,
        }),
    );

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));
    await screen.findByPlaceholderText('Search repositories...');

    const loadButton = screen.getByRole('button', { name: 'Load Repository' });
    expect(loadButton).not.toBeDisabled();

    let resolveBranches;
    global.fetch.mockReturnValueOnce(new Promise((resolve) => (resolveBranches = resolve)));
    fireEvent.click(loadButton);

    expect(screen.getByRole('button', { name: 'Loading...' })).toBeDisabled();
    expect(global.fetch).toHaveBeenLastCalledWith('/github-apps/branches?github_app_id=1&owner=acme&repo=widgets', {
        headers: { Accept: 'application/json' },
    });

    resolveBranches(jsonResponse({ branches: [{ name: 'develop' }, { name: 'main' }] }));

    expect(await screen.findByText('Configuration')).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'develop' })).toBeInTheDocument();
    expect(mockFormState.data.git_branch).toBe('develop');
    expect(mockFormState.data.repository_id).toBe(10);
});

it('defaults git_branch to "main" when the branches response is empty', async () => {
    global.fetch.mockReturnValueOnce(
        jsonResponse({
            repositories: [{ id: 10, owner: 'acme', name: 'widgets' }],
            installationUrl: null,
        }),
    );

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));
    await screen.findByPlaceholderText('Search repositories...');

    global.fetch.mockReturnValueOnce(jsonResponse({ branches: [] }));
    fireEvent.click(screen.getByRole('button', { name: 'Load Repository' }));

    await waitFor(() => expect(mockFormState.data.repository_id).toBe(10));
    expect(mockFormState.data.git_branch).toBe('main');
    expect(screen.queryByText('Configuration')).not.toBeInTheDocument();
});

it('shows "No repositories found" once the app step resolves to an empty repository list', async () => {
    global.fetch.mockReturnValue(jsonResponse({ repositories: [], installationUrl: null }));

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));

    expect(await screen.findByText('No repositories found. Check your GitHub App configuration.')).toBeInTheDocument();
});

it('only shows Refresh Repository List and the installation link once repositories are loaded', async () => {
    global.fetch.mockReturnValue(
        jsonResponse({
            repositories: [{ id: 10, owner: 'acme', name: 'widgets' }],
            installationUrl: 'https://github.com/settings/installations/1',
        }),
    );

    render(<GithubPrivateRepository {...baseProps()} />);
    expect(screen.queryByText('Refresh Repository List')).not.toBeInTheDocument();

    fireEvent.click(screen.getByText('my-github-app'));
    await screen.findByText('Refresh Repository List');

    global.fetch.mockClear();
    fireEvent.click(screen.getByText('Refresh Repository List'));
    expect(global.fetch).toHaveBeenCalledWith('/github-apps/repositories?github_app_id=1', { headers: { Accept: 'application/json' } });
});

it('submits to submitUrl once a repository and its branches are loaded', async () => {
    global.fetch.mockReturnValueOnce(
        jsonResponse({
            repositories: [{ id: 10, owner: 'acme', name: 'widgets' }],
            installationUrl: null,
        }),
    );

    render(<GithubPrivateRepository {...baseProps()} />);
    fireEvent.click(screen.getByText('my-github-app'));
    await screen.findByPlaceholderText('Search repositories...');

    global.fetch.mockReturnValueOnce(jsonResponse({ branches: [{ name: 'main' }] }));
    fireEvent.click(screen.getByRole('button', { name: 'Load Repository' }));
    await screen.findByText('Configuration');

    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(postSpy).toHaveBeenCalledWith('/project/new/github-private', undefined);
});

it('opens the GitHub App create modal from the + Add GitHub App button', () => {
    render(<GithubPrivateRepository {...baseProps()} />);
    expect(screen.queryByTestId('create-app-modal')).not.toBeInTheDocument();

    fireEvent.click(screen.getByText('+ Add GitHub App'));
    expect(screen.getByTestId('create-app-modal')).toBeInTheDocument();
});
