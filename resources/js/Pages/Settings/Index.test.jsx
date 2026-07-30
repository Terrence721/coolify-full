import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// ESLint set-state-in-effect cleanup (issue #33): both flash-triggered effects (the domain-
// conflict modal, the helper-image build activity log) restructured to adjust state during
// render instead. Previously zero coverage for this page at all. DomainConflictModal/ActivityLog
// are mocked out (both already have their own dedicated suites) so this stays focused on Index's
// own flash-detection logic - including the on-first-render case (a flash already present when
// the page loads, e.g. right after a redirect), the exact case a naively-seeded tracking value
// would silently miss (see the DatabaseHeading.jsx lesson earlier in this cleanup pass).

const putSpy = vi.fn();
let mockFlash = {};

vi.mock('@inertiajs/react', () => ({
    router: { put: (url, data, options) => putSpy(url, data, options), post: vi.fn() },
    useForm: (initial) => ({ data: initial, setData: vi.fn(), put: vi.fn(), processing: false, errors: {} }),
    usePage: () => ({ props: { flash: mockFlash } }),
}));

vi.mock('../../Components/ActivityLog', () => ({
    default: ({ activityId, header }) => (
        <div data-testid="activity-log">
            {header} - {activityId}
        </div>
    ),
}));

vi.mock('../../Components/DomainConflictModal', () => ({
    default: ({ conflicts, onCancel }) =>
        conflicts?.length ? (
            <div data-testid="domain-conflict-modal">
                <button type="button" onClick={onCancel}>
                    Cancel
                </button>
            </div>
        ) : null,
}));

function baseProps(overrides = {}) {
    return {
        settings: { instance_timezone: 'UTC' },
        timezones: ['UTC', 'America/New_York'],
        isDev: false,
        hasServer: true,
        defaultHelperVersion: '1.0.0',
        updateUrl: '/settings',
        buildHelperImageUrl: '/settings/build-helper-image',
        ...overrides,
    };
}

describe('Settings/Index', () => {
    beforeEach(() => {
        mockFlash = {};
        putSpy.mockClear();
    });

    it('shows neither the domain-conflict modal nor the activity log when flash is empty', () => {
        render(<Index {...baseProps()} />);
        expect(screen.queryByTestId('domain-conflict-modal')).not.toBeInTheDocument();
        expect(screen.queryByTestId('activity-log')).not.toBeInTheDocument();
    });

    it('opens the domain-conflict modal when a conflicts flash is already present on the very first render', () => {
        mockFlash = { domainConflicts: ['coolify.example.com'] };
        render(<Index {...baseProps()} />);
        expect(screen.getByTestId('domain-conflict-modal')).toBeInTheDocument();
    });

    it('opens the domain-conflict modal on a later re-render when a new conflicts flash arrives', () => {
        const { rerender } = render(<Index {...baseProps()} />);
        expect(screen.queryByTestId('domain-conflict-modal')).not.toBeInTheDocument();

        mockFlash = { domainConflicts: ['coolify.example.com'] };
        rerender(<Index {...baseProps()} />);
        expect(screen.getByTestId('domain-conflict-modal')).toBeInTheDocument();
    });

    it('closing the modal does not reopen it on a re-render with the same conflicts reference', () => {
        mockFlash = { domainConflicts: ['coolify.example.com'] };
        const { rerender } = render(<Index {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Cancel' }).click());

        rerender(<Index {...baseProps()} />);
        expect(screen.queryByTestId('domain-conflict-modal')).not.toBeInTheDocument();
    });

    it('shows the helper-image activity log when its flash is already present on the very first render', () => {
        mockFlash = { activityContext: 'settings-helper-image', activityId: 'act-1' };
        render(<Index {...baseProps()} />);
        expect(screen.getByTestId('activity-log')).toHaveTextContent('Building Helper Image - act-1');
    });

    it('ignores an activityId flash meant for a different context', () => {
        mockFlash = { activityContext: 'patches-update', activityId: 'act-1' };
        render(<Index {...baseProps()} />);
        expect(screen.queryByTestId('activity-log')).not.toBeInTheDocument();
    });

    it('updates the activity log when a new helper-image activity flash arrives on re-render', () => {
        mockFlash = { activityContext: 'settings-helper-image', activityId: 'act-1' };
        const { rerender } = render(<Index {...baseProps()} />);
        expect(screen.getByTestId('activity-log')).toHaveTextContent('act-1');

        mockFlash = { activityContext: 'settings-helper-image', activityId: 'act-2' };
        rerender(<Index {...baseProps()} />);
        expect(screen.getByTestId('activity-log')).toHaveTextContent('act-2');
    });
});
