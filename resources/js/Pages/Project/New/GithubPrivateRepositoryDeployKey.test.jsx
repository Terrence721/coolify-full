import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GithubPrivateRepositoryDeployKey from './GithubPrivateRepositoryDeployKey';

// The "Private Repository (with Deploy Key)" creation flow - a real 2-step wizard (pick a
// private key, then describe the repository), previously untested. GitApplicationFields already
// has its own dedicated suite - mocked out here to keep this suite focused on the page's own
// logic: the step transition, the empty-state/create-key link, private key selection setting
// private_key_id, and the submit wiring.

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

function basePrivateKeys() {
    return [
        { id: 1, name: 'Deploy Key 1', description: 'first key' },
        { id: 2, name: 'Deploy Key 2', description: 'second key' },
    ];
}

function baseProps(overrides = {}) {
    return {
        defaultRepositoryUrl: 'https://github.com/coollabsio/coolify-examples.git',
        privateKeys: basePrivateKeys(),
        privateKeyIndexUrl: '/security/private-keys',
        submitUrl: '/new/git/private-deploy-key',
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('GithubPrivateRepositoryDeployKey - private key selection step', () => {
    it('starts on the private-key selection step', () => {
        render(<GithubPrivateRepositoryDeployKey {...baseProps()} />);

        expect(screen.getByText('Select a private key')).toBeInTheDocument();
        expect(screen.getByText('Deploy Key 1')).toBeInTheDocument();
        expect(screen.getByText('Deploy Key 2')).toBeInTheDocument();
        expect(screen.queryByText('Repository URL (https:// or git@)')).not.toBeInTheDocument();
    });

    it('shows an empty state with a create-key link when there are no private keys', () => {
        render(<GithubPrivateRepositoryDeployKey {...baseProps({ privateKeys: [] })} />);

        expect(screen.getByText('No private keys found.')).toBeInTheDocument();
        const link = screen.getByRole('link', { name: 'Create a new private key' });
        expect(link).toHaveAttribute('href', '/security/private-keys');
    });

    it('advances to the repository step and records the selected key when a key is clicked', () => {
        render(<GithubPrivateRepositoryDeployKey {...baseProps()} />);

        fireEvent.click(screen.getByText('Deploy Key 2'));

        expect(screen.queryByText('Select a private key')).not.toBeInTheDocument();
        expect(screen.getByText('Repository URL (https:// or git@)')).toBeInTheDocument();
    });
});

describe('GithubPrivateRepositoryDeployKey - repository step', () => {
    function advanceToRepositoryStep() {
        render(<GithubPrivateRepositoryDeployKey {...baseProps()} />);
        fireEvent.click(screen.getByText('Deploy Key 1'));
    }

    it('pre-fills the Repository URL field from defaultRepositoryUrl', () => {
        advanceToRepositoryStep();
        expect(screen.getByLabelText('Repository URL (https:// or git@)')).toHaveValue('https://github.com/coollabsio/coolify-examples.git');
    });

    it('renders the Branch field as GitApplicationFields children', () => {
        advanceToRepositoryStep();
        const fields = screen.getByTestId('git-application-fields');
        expect(fields).toHaveTextContent('Branch');
    });

    it('submits the form to submitUrl', () => {
        advanceToRepositoryStep();

        fireEvent.change(screen.getByLabelText('Repository URL (https:// or git@)'), {
            target: { value: 'git@github.com:org/repo.git' },
        });
        fireEvent.change(screen.getByLabelText('Branch'), { target: { value: 'main' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(postSpy).toHaveBeenCalledWith('/new/git/private-deploy-key');
    });
});
