import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PublicGitRepository from './PublicGitRepository';

// The public-repository application creation flow, live-verified end-to-end during the
// 2026-07-29 Git-based creation flows smoke test (issue #27): the dev-only default URL
// (.../coolify-examples/tree/v4.x) correctly auto-detected branch v4.x from the /tree/ segment,
// showed real GitHub rate-limit info, and kept the branch field disabled (github.com).
// Switching to a non-GitHub URL correctly appended .git and re-enabled the branch field.
// Submitting created a real Application row and redirected to its config page.
// GitApplicationFields already has its own dedicated suite - mocked out here to keep this suite
// focused on the page's own logic: the check-repository fetch, rate-limit display, the
// isGithub-driven branch-field disabled state, and the submit wiring.

const postSpy = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');
    return {
        useForm: (initial) => {
            const [data, setDataState] = useState(initial);
            return {
                data,
                setData: (keyOrObject, value) => {
                    setDataState((prev) => (typeof keyOrObject === 'string' ? { ...prev, [keyOrObject]: value } : keyOrObject));
                },
                post: (url) => postSpy(url),
                processing: false,
                errors: {},
            };
        },
    };
});

vi.mock('../../../Components/GitApplicationFields', () => ({
    default: ({ children }) => <div data-testid="git-application-fields">{children}</div>,
}));

function jsonResponse(body, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(body) });
}

function baseProps(overrides = {}) {
    return {
        defaultRepositoryUrl: 'https://github.com/coollabsio/coolify-examples/tree/v4.x',
        checkUrl: '/new/git/check-repository',
        submitUrl: '/new/git/public',
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
    global.fetch = vi.fn(() => jsonResponse({ branchFound: false }));
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Project/New/PublicGitRepository', () => {
    it('prefills the repository URL from defaultRepositoryUrl', () => {
        render(<PublicGitRepository {...baseProps()} />);
        expect(screen.getByLabelText(/Repository URL/)).toHaveValue('https://github.com/coollabsio/coolify-examples/tree/v4.x');
    });

    it('calls checkUrl with a POST + JSON body of the current repository_url', async () => {
        render(<PublicGitRepository {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Check repository' }).click());

        expect(global.fetch).toHaveBeenCalledWith(
            '/new/git/check-repository',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'Content-Type': 'application/json', Accept: 'application/json' }),
                body: JSON.stringify({ repository_url: 'https://github.com/coollabsio/coolify-examples/tree/v4.x' }),
            }),
        );
    });

    it('shows "Checking..." and disables the button while the check is in flight', async () => {
        let resolveFetch;
        global.fetch = vi.fn(() => new Promise((resolve) => (resolveFetch = resolve)));
        render(<PublicGitRepository {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Check repository' }).click());
        expect(screen.getByRole('button', { name: 'Checking...' })).toBeDisabled();

        await act(async () => {
            resolveFetch(await jsonResponse({ branchFound: false }));
        });
    });

    it('shows rate-limit info and auto-detects the branch for a real GitHub repo, disabling the branch field', async () => {
        global.fetch = vi.fn(() =>
            jsonResponse({
                repositoryUrl: 'https://github.com/coollabsio/coolify-examples',
                branch: 'v4.x',
                branchFound: true,
                baseDirectory: '/',
                isGithub: true,
                rateLimitRemaining: 58,
                rateLimitReset: '2026-Jul-29 12:00:00',
            }),
        );
        render(<PublicGitRepository {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Check repository' }).click());

        expect(screen.getByText('Rate Limit Remaining: 58')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeInTheDocument();
        const branchField = screen.getByLabelText('Branch');
        expect(branchField).toHaveValue('v4.x');
        expect(branchField).toBeDisabled();
    });

    it('re-enables the branch field and does not show rate-limit info for a non-GitHub URL', async () => {
        global.fetch = vi.fn(() =>
            jsonResponse({
                repositoryUrl: 'https://gitlab.com/some-owner/some-repo.git',
                branch: 'main',
                branchFound: true,
                baseDirectory: '/',
                isGithub: false,
                rateLimitRemaining: null,
                rateLimitReset: null,
            }),
        );
        render(<PublicGitRepository {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Check repository' }).click());

        expect(screen.queryByText(/Rate Limit Remaining/)).not.toBeInTheDocument();
        expect(screen.getByLabelText(/Repository URL/)).toHaveValue('https://gitlab.com/some-owner/some-repo.git');
        expect(screen.getByLabelText('Branch')).not.toBeDisabled();
    });

    it('does not show the Continue section when the check has not found a branch', async () => {
        global.fetch = vi.fn(() => jsonResponse({ branchFound: false }));
        render(<PublicGitRepository {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Check repository' }).click());

        expect(screen.queryByRole('button', { name: 'Continue' })).not.toBeInTheDocument();
    });

    it('shows the server-provided error message when the check fails', async () => {
        global.fetch = vi.fn(() => jsonResponse({ message: 'Repository not found.' }, false));
        render(<PublicGitRepository {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Check repository' }).click());

        expect(screen.getByText('Repository not found.')).toBeInTheDocument();
    });

    it('shows a generic error when the fetch itself throws', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('network down')));
        render(<PublicGitRepository {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Check repository' }).click());

        expect(screen.getByText('Failed to check the repository.')).toBeInTheDocument();
    });

    it('submits to submitUrl via post when Continue is clicked', async () => {
        global.fetch = vi.fn(() =>
            jsonResponse({
                repositoryUrl: 'https://github.com/coollabsio/coolify-examples',
                branch: 'v4.x',
                branchFound: true,
                baseDirectory: '/',
                isGithub: true,
            }),
        );
        render(<PublicGitRepository {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Check repository' }).click());
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        expect(postSpy).toHaveBeenCalledWith('/new/git/public');
    });
});
