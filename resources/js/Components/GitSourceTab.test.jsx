import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import GitSourceTab from './GitSourceTab';

// The Application Configuration "Source" tab - repository/branch/commit form, deploy-key
// selection, and the Change Git Source picker. Real logic: a 2-way branch on whether a private
// key is attached (deploy-key management vs. a "change source" candidate list), nested branches
// within each side (other keys available or not; other sources available or not; current
// candidate disabled), and a confirmation-modal flow before actually changing the source.

const routerPatch = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, opts) => routerPatch(url, data, opts),
    },
}));

vi.mock('./PasswordConfirmModal', () => ({
    default: ({ title, action, onClose }) => (
        <div data-testid="PasswordConfirmModal">
            <div>{title}</div>
            <div>{JSON.stringify(action)}</div>
            <button type="button" onClick={onClose}>
                close-modal
            </button>
        </div>
    ),
}));

afterEach(() => {
    routerPatch.mockClear();
});

function baseProps(overrides = {}) {
    return {
        source: {
            gitRepository: 'coollabsio/coolify',
            gitBranch: 'main',
            gitCommitSha: '',
            gitBranchLocation: 'https://github.com/coollabsio/coolify/tree/main',
            gitCommits: 'https://github.com/coollabsio/coolify/commits/main',
            isSourcePublic: false,
            installationPath: null,
            privateKeyId: null,
            privateKeyName: null,
            privateKeys: [],
            currentSourceName: 'Public source',
            sources: [],
        },
        sourceUrls: {
            update: '/source/update',
            setPrivateKey: '/source/set-private-key',
            changeSource: '/source/change',
        },
        canUpdate: true,
        ...overrides,
    };
}

it('submits the form fields via router.patch to sourceUrls.update', () => {
    render(<GitSourceTab {...baseProps()} />);
    fireEvent.change(screen.getByDisplayValue('coollabsio/coolify'), { target: { value: 'me/fork' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(routerPatch).toHaveBeenCalledWith(
        '/source/update',
        { gitRepository: 'me/fork', gitBranch: 'main', gitCommitSha: '' },
        { preserveScroll: true },
    );
});

it('disables the inputs and hides Save when canUpdate is false', () => {
    render(<GitSourceTab {...baseProps({ canUpdate: false })} />);
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    expect(screen.getByDisplayValue('coollabsio/coolify')).toBeDisabled();
});

it('shows "Open Git App" only for a non-public source with an installation path', () => {
    const { rerender } = render(<GitSourceTab {...baseProps({ source: { ...baseProps().source, isSourcePublic: true } })} />);
    expect(screen.queryByRole('link', { name: 'Open Git App' })).not.toBeInTheDocument();

    rerender(
        <GitSourceTab
            {...baseProps({
                source: { ...baseProps().source, isSourcePublic: false, installationPath: 'https://github.com/apps/coolify/installations/1' },
            })}
        />,
    );
    expect(screen.getByRole('link', { name: 'Open Git App' })).toHaveAttribute('href', 'https://github.com/apps/coolify/installations/1');
});

it('shows the "currently connected source" line only when no private key is attached', () => {
    const { rerender } = render(<GitSourceTab {...baseProps()} />);
    expect(screen.getByText('Public source')).toBeInTheDocument();

    rerender(
        <GitSourceTab
            {...baseProps({
                source: { ...baseProps().source, privateKeyId: 1, privateKeyName: 'Deploy Key #1' },
            })}
        />,
    );
    expect(screen.queryByText('Public source')).not.toBeInTheDocument();
});

describe('with a private key attached', () => {
    function propsWithKey(overrides = {}) {
        return baseProps({
            source: {
                ...baseProps().source,
                privateKeyId: 1,
                privateKeyName: 'Deploy Key #1',
                privateKeys: [],
                ...overrides,
            },
        });
    }

    it('shows the currently attached key and hides "Select another" when there are no other keys', () => {
        render(<GitSourceTab {...propsWithKey()} />);
        expect(screen.getByText('Deploy Key #1')).toBeInTheDocument();
        expect(screen.queryByText('Select another Private Key')).not.toBeInTheDocument();
        expect(screen.queryByText('Change Git Source')).not.toBeInTheDocument();
    });

    it('lists other private keys and switches via router.patch to sourceUrls.setPrivateKey', () => {
        render(<GitSourceTab {...propsWithKey({ privateKeys: [{ id: 2, name: 'Deploy Key #2' }] })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Deploy Key #2' }));

        expect(routerPatch).toHaveBeenCalledWith('/source/set-private-key', { privateKeyId: 2 }, { preserveScroll: true });
    });

    it('hides "Select another Private Key" when canUpdate is false, even with other keys available', () => {
        render(<GitSourceTab {...propsWithKey({ privateKeys: [{ id: 2, name: 'Deploy Key #2' }] })} canUpdate={false} />);
        expect(screen.queryByText('Select another Private Key')).not.toBeInTheDocument();
    });
});

describe('without a private key attached', () => {
    it('shows "No other sources found" when the source list is empty', () => {
        render(<GitSourceTab {...baseProps()} />);
        expect(screen.getByText('Change Git Source')).toBeInTheDocument();
        expect(screen.getByText('No other sources found')).toBeInTheDocument();
    });

    it('hides the whole "Change Git Source" section when canUpdate is false', () => {
        render(<GitSourceTab {...baseProps({ canUpdate: false })} />);
        expect(screen.queryByText('Change Git Source')).not.toBeInTheDocument();
    });

    it('marks the current candidate disabled and shows "(current)"', () => {
        render(
            <GitSourceTab
                {...baseProps({
                    source: {
                        ...baseProps().source,
                        sources: [{ id: 1, name: 'GitHub App A', organization: null, isCurrent: true }],
                    },
                })}
            />,
        );
        const candidate = screen.getByRole('button', { name: /GitHub App A/ });
        expect(candidate).toBeDisabled();
        expect(screen.getByText('(current)')).toBeInTheDocument();
    });

    it('opens the confirmation modal for a non-current candidate, wired to sourceUrls.changeSource', () => {
        render(
            <GitSourceTab
                {...baseProps({
                    source: {
                        ...baseProps().source,
                        sources: [{ id: 5, name: 'GitHub App B', organization: 'Acme Inc', type: 'github' }],
                    },
                })}
            />,
        );
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();
        expect(screen.getByText('Acme Inc')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /GitHub App B/ }));

        const modal = screen.getByTestId('PasswordConfirmModal');
        expect(modal).toHaveTextContent('Change Git Source');
        expect(modal).toHaveTextContent('"url":"/source/change"');
        expect(modal).toHaveTextContent('"sourceId":5');
        expect(modal).toHaveTextContent('"sourceType":"github"');

        fireEvent.click(screen.getByRole('button', { name: 'close-modal' }));
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();
    });

    it('falls back to "Personal Account" when a candidate has no organization', () => {
        render(
            <GitSourceTab
                {...baseProps({
                    source: {
                        ...baseProps().source,
                        sources: [{ id: 5, name: 'GitHub App B', organization: null, type: 'github' }],
                    },
                })}
            />,
        );
        expect(screen.getByText('Personal Account')).toBeInTheDocument();
    });
});
