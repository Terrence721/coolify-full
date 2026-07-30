import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ServiceStackTab from './ServiceStackTab';

// Previously entirely untested. This suite focuses on the Edit Domains modal's port-warning
// flash logic (issue #33's react-hooks/set-state-in-effect fix: adjusting state during render
// instead of via a useEffect) - not full page coverage of the whole Service Stack tab, given
// the component's size.
//
// showPortWarningModal is a plain boolean flag (unlike activityId, which is a unique id per
// occurrence), so the fix tracks the whole flash object's identity rather than the boolean's
// own value - Inertia hands back a fresh flash object on every page-visit response regardless
// of whether the boolean happens to be true twice in a row, but true !== true is always false
// and can't tell two separate occurrences apart on its own.

const patchSpy = vi.fn();
let mockFlash = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, options) => patchSpy(url, data, options),
        post: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({ props: { flash: mockFlash } }),
}));

vi.mock('../hooks/useTeamChannel', () => ({ useTeamChannel: () => {} }));

vi.mock('./DomainConflictModal', () => ({ default: () => <div data-testid="domain-conflict-modal" /> }));
vi.mock('./ResourceDetailsModal', () => ({ default: () => <div data-testid="resource-details-modal" /> }));

function baseResource(overrides = {}) {
    return {
        uuid: 'app-uuid-1',
        name: 'storefront-web',
        image: 'node:20',
        status: 'running',
        statusFormatted: 'Running',
        configurationRequired: false,
        description: null,
        isApplication: true,
        fqdn: 'https://app.example.com',
        showBackups: false,
        urls: { backups: '/backups', settings: '/settings', restart: '/restart', domain: '/domain' },
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        stackForm: { name: 'My Stack', description: '', fields: [], connectToDockerNetwork: false, dockerComposeRaw: '' },
        resources: [baseResource()],
        resourceDetails: {},
        generalUrls: { update: '/update', settings: '/settings', validateCompose: '/validate' },
        canUpdate: true,
        ...overrides,
    };
}

function openEditDomains() {
    act(() => screen.getByTitle('Edit Domains').click());
}

describe('ServiceStackTab / EditDomainModal port warning', () => {
    beforeEach(() => {
        patchSpy.mockClear();
        mockFlash = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('does not show the port warning when no flash is present', () => {
        render(<ServiceStackTab {...baseProps()} />);
        openEditDomains();
        expect(screen.queryByText(/requires port/)).not.toBeInTheDocument();
    });

    it('shows the port warning immediately when the flash is already present on first render', () => {
        mockFlash = { showPortWarningModal: true, requiredPort: 8080 };
        render(<ServiceStackTab {...baseProps()} />);
        openEditDomains();
        expect(screen.getByText('8080')).toBeInTheDocument();
        expect(screen.getByText(/requires port/)).toBeInTheDocument();
    });

    it('Cancel dismisses the warning without saving', () => {
        mockFlash = { showPortWarningModal: true, requiredPort: 8080 };
        render(<ServiceStackTab {...baseProps()} />);
        openEditDomains();

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());

        expect(screen.queryByText(/requires port/)).not.toBeInTheDocument();
        expect(patchSpy).not.toHaveBeenCalled();
    });

    it('"Continue without port" saves the domain with force_remove_port: true', () => {
        mockFlash = { showPortWarningModal: true, requiredPort: 8080 };
        render(<ServiceStackTab {...baseProps()} />);
        openEditDomains();

        act(() => screen.getByRole('button', { name: 'Continue without port' }).click());

        expect(patchSpy).toHaveBeenCalledWith(
            '/domain',
            expect.objectContaining({ force_remove_port: true }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('a second, genuinely new port-warning flash re-shows the warning after Cancel dismissed the first', () => {
        mockFlash = { showPortWarningModal: true, requiredPort: 8080 };
        const { rerender } = render(<ServiceStackTab {...baseProps()} />);
        openEditDomains();
        expect(screen.getByText(/requires port/)).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());
        expect(screen.queryByText(/requires port/)).not.toBeInTheDocument();

        // A brand new flash object, same boolean value as before - Inertia always sends a
        // fresh object per response, so this must still count as a new occurrence.
        mockFlash = { showPortWarningModal: true, requiredPort: 8080 };
        rerender(<ServiceStackTab {...baseProps()} />);
        expect(screen.getByText(/requires port/)).toBeInTheDocument();
    });
});
