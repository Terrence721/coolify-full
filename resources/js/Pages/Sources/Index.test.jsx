import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The /sources list page, live-verified end-to-end during the 2026-07-23 Sources smoke test
// (issue #21, 28/28) and again during the 2026-07-24 /source/github/{uuid} smoke test (issue #25)
// that this suite's sibling Change.jsx bug fix came out of - previously untested itself. Covers
// the empty state, the configured/unconfigured/organization badge variants, the canCreate gate on
// "+ Add", and the ?create=1 query-param auto-open GlobalSearch relies on for its "GitHub App"
// quick action.

const modalPropsSpy = vi.fn();

vi.mock('../../Components/GithubAppCreateModal', () => ({
    default: (props) => {
        modalPropsSpy(props);
        return props.open ? (
            <div data-testid="github-app-create-modal">
                <button type="button" onClick={props.onClose}>
                    Close
                </button>
            </div>
        ) : null;
    },
}));

function source(overrides = {}) {
    return {
        uuid: 'gh-app-1',
        name: 'coolify-laravel-dev-public',
        organization: null,
        configured: true,
        url: '/source/github/gh-app-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        sources: [],
        canCreate: true,
        storeUrl: '/source/github',
        defaultName: 'encouraging-emu-z11htex4keu2y8',
        isCloud: false,
        ...overrides,
    };
}

beforeEach(() => {
    modalPropsSpy.mockClear();
    window.history.replaceState({}, '', '/sources');
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Sources/Index', () => {
    it('shows "No sources found." when there are none', () => {
        render(<Index {...baseProps({ sources: [] })} />);
        expect(screen.getByText('No sources found.')).toBeInTheDocument();
    });

    it('renders each source as a link to its own url', () => {
        render(<Index {...baseProps({ sources: [source()] })} />);
        expect(screen.queryByText('No sources found.')).not.toBeInTheDocument();
        expect(screen.getByText('coolify-laravel-dev-public')).toBeInTheDocument();
        expect(document.querySelector('a[href="/source/github/gh-app-1"]')).toBeInTheDocument();
    });

    it('shows "Configuration is not finished." for an unconfigured source, never the organization line', () => {
        render(<Index {...baseProps({ sources: [source({ configured: false, organization: 'my-org' })] })} />);
        expect(screen.getByText('Configuration is not finished.')).toBeInTheDocument();
        expect(screen.queryByText(/Organization:/)).not.toBeInTheDocument();
    });

    it('shows the Organization line for a configured source that has one, and neither badge when it has none', () => {
        const { unmount } = render(<Index {...baseProps({ sources: [source({ configured: true, organization: 'my-org' })] })} />);
        expect(screen.getByText('Organization: my-org')).toBeInTheDocument();
        expect(screen.queryByText('Configuration is not finished.')).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ sources: [source({ configured: true, organization: null })] })} />);
        expect(screen.queryByText(/Organization:/)).not.toBeInTheDocument();
        expect(screen.queryByText('Configuration is not finished.')).not.toBeInTheDocument();
    });

    it('shows the "+ Add" button only when canCreate is true', () => {
        const { unmount } = render(<Index {...baseProps({ canCreate: true })} />);
        expect(screen.getByRole('button', { name: '+ Add' })).toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ canCreate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('opens and closes GithubAppCreateModal via the "+ Add" button, passing storeUrl/defaultName/isCloud through', () => {
        render(<Index {...baseProps({ storeUrl: '/source/github', defaultName: 'encouraging-emu-z11htex4keu2y8', isCloud: true })} />);
        expect(screen.queryByTestId('github-app-create-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        expect(screen.getByTestId('github-app-create-modal')).toBeInTheDocument();
        expect(modalPropsSpy).toHaveBeenLastCalledWith(
            expect.objectContaining({
                open: true,
                storeUrl: '/source/github',
                defaultName: 'encouraging-emu-z11htex4keu2y8',
                isCloud: true,
            }),
        );

        act(() => screen.getByRole('button', { name: 'Close' }).click());
        expect(screen.queryByTestId('github-app-create-modal')).not.toBeInTheDocument();
    });

    it('auto-opens the create modal when the URL has ?create=1 (GlobalSearch\'s "GitHub App" quick action)', () => {
        window.history.replaceState({}, '', '/sources?create=1');
        render(<Index {...baseProps()} />);
        expect(screen.getByTestId('github-app-create-modal')).toBeInTheDocument();
    });

    it('does not auto-open the create modal without ?create=1', () => {
        window.history.replaceState({}, '', '/sources');
        render(<Index {...baseProps()} />);
        expect(screen.queryByTestId('github-app-create-modal')).not.toBeInTheDocument();
    });
});
