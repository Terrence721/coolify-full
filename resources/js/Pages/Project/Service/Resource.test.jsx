import { act, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Resource from './Resource';

// Previously entirely untested. This suite focuses on ApplicationGeneral's port-warning-modal
// flash-driven open/reset logic (issue #33's react-hooks/set-state-in-effect fix: adjusting state
// during render instead of via a useEffect, tracking the whole flash object's identity since
// showPortWarningModal is a plain boolean flag that can repeat across two separate flash
// triggers) - not full page coverage of every resource-tab action.

const postSpy = vi.fn();
let mockFlash = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { flash: mockFlash } }),
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, data, options),
            processing: false,
            errors: {},
        };
    },
}));

vi.mock('../../../Components/ServiceHeading', () => ({ default: () => <div data-testid="service-heading" /> }));
vi.mock('../../../Components/DomainConflictModal', () => ({ default: () => <div data-testid="domain-conflict-modal" /> }));
vi.mock('../../../Components/PasswordConfirmModal', () => ({ default: () => <div data-testid="password-confirm-modal" /> }));
vi.mock('../../../Components/DatabaseImportTab', () => ({ default: () => <div data-testid="database-import-tab" /> }));

function baseProps(overrides = {}) {
    return {
        resourceType: 'application',
        tab: 'general',
        service: { name: 'my-service' },
        serviceHeadingUrls: {},
        parameters: { stack_service_uuid: 'app-1' },
        serviceParameters: { project_uuid: 'p1', environment_uuid: 'e1', service_uuid: 's1' },
        application: {
            humanName: 'My App',
            name: 'my-app',
            requiredPort: '5432',
            isKnownServiceType: false,
            requiredFqdn: false,
        },
        database: null,
        urls: { update: '/resource/app-1/update' },
        importTab: null,
        ...overrides,
    };
}

describe('Project/Service/Resource - ApplicationGeneral port warning modal', () => {
    beforeEach(() => {
        postSpy.mockClear();
        mockFlash = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('does not show the port-removal warning modal without a flash', () => {
        render(<Resource {...baseProps()} />);
        expect(screen.queryByText('Remove Required Port?')).not.toBeInTheDocument();
    });

    it('shows the port-removal warning modal when flash.showPortWarningModal is true', () => {
        mockFlash = { showPortWarningModal: true, requiredPort: '5432' };
        render(<Resource {...baseProps()} />);

        expect(screen.getByText('Remove Required Port?')).toBeInTheDocument();
        expect(screen.getAllByText('5432').length).toBeGreaterThan(0);
    });

    it('dismisses the modal via Cancel, and a genuinely new warning flash reopens it even after a prior close', () => {
        mockFlash = { showPortWarningModal: true, requiredPort: '5432' };
        const { rerender } = render(<Resource {...baseProps()} />);
        expect(screen.getByText('Remove Required Port?')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Cancel - Keep Port' }).click());
        expect(screen.queryByText('Remove Required Port?')).not.toBeInTheDocument();

        // Same flash reference re-renders (e.g. an unrelated state change) - stays dismissed.
        rerender(<Resource {...baseProps()} />);
        expect(screen.queryByText('Remove Required Port?')).not.toBeInTheDocument();

        // A genuinely new flash object (same boolean value: true) must still reopen it - a naive
        // by-value comparison of the boolean can't distinguish two separate occurrences.
        mockFlash = { showPortWarningModal: true, requiredPort: '5432' };
        rerender(<Resource {...baseProps()} />);
        expect(screen.getByText('Remove Required Port?')).toBeInTheDocument();
    });

    it('confirming port removal posts force_remove_port: true and closes the modal', () => {
        mockFlash = { showPortWarningModal: true, requiredPort: '5432' };
        render(<Resource {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'I understand, remove port anyway' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/resource/app-1/update',
            expect.anything(),
            expect.objectContaining({
                preserveScroll: true,
                data: expect.objectContaining({ force_remove_port: true }),
            }),
        );
        expect(screen.queryByText('Remove Required Port?')).not.toBeInTheDocument();
    });
});
