import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SwarmTab from './SwarmTab';

// Real logic: a mixed instant-save/deferred-save form sharing one endpoint - the "Only Start on
// Worker nodes" checkbox submits the whole form immediately on change, while Replicas and Custom
// Placement Constraints wait for the Save button. Because the checkbox's immediate submit merges
// in whatever's currently in local form state, toggling it also submits any not-yet-saved edits
// to the other two fields - easy to get subtly wrong either direction (submitting too little, or
// not realizing pending edits ride along). Previously untested.

const patchSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, options) => patchSpy(url, data, options),
    },
}));

function baseProps(overrides = {}) {
    return {
        swarm: {
            swarmReplicas: 1,
            swarmPlacementConstraints: '',
            isSwarmOnlyWorkerNodes: false,
        },
        swarmUpdateUrl: '/application/swarm/update',
        canUpdate: true,
        ...overrides,
    };
}

afterEach(() => {
    patchSpy.mockClear();
    vi.restoreAllMocks();
});

describe('SwarmTab - pre-fill and defaults', () => {
    it('pre-fills fields from the swarm prop', () => {
        render(
            <SwarmTab
                {...baseProps({ swarm: { swarmReplicas: 3, swarmPlacementConstraints: 'node.role == worker', isSwarmOnlyWorkerNodes: true } })}
            />,
        );

        expect(screen.getByLabelText('Replicas')).toHaveValue(3);
        expect(screen.getByLabelText('Custom Placement Constraints')).toHaveValue('node.role == worker');
        expect(screen.getByLabelText('Only Start on Worker nodes')).toBeChecked();
    });

    it('falls back to defaults when the swarm prop omits fields', () => {
        render(<SwarmTab {...baseProps({ swarm: {} })} />);

        expect(screen.getByLabelText('Replicas')).toHaveValue(1);
        expect(screen.getByLabelText('Custom Placement Constraints')).toHaveValue('');
        expect(screen.getByLabelText('Only Start on Worker nodes')).not.toBeChecked();
    });

    it('always renders the Deprecated badge and warning banner', () => {
        render(<SwarmTab {...baseProps()} />);

        expect(screen.getByText('Deprecated')).toBeInTheDocument();
        expect(screen.getByText(/Docker Swarm is deprecated/)).toBeInTheDocument();
    });
});

describe('SwarmTab - instant-save checkbox vs deferred Save', () => {
    it('submits immediately when the worker-nodes checkbox is toggled, with no Save click', () => {
        render(<SwarmTab {...baseProps()} />);

        fireEvent.click(screen.getByLabelText('Only Start on Worker nodes'));

        expect(patchSpy).toHaveBeenCalledTimes(1);
        expect(patchSpy).toHaveBeenCalledWith(
            '/application/swarm/update',
            { swarmReplicas: 1, swarmPlacementConstraints: '', isSwarmOnlyWorkerNodes: true },
            { preserveScroll: true },
        );
    });

    it('does not submit when Replicas or Placement Constraints are edited, until Save is clicked', () => {
        render(<SwarmTab {...baseProps()} />);

        fireEvent.change(screen.getByLabelText('Replicas'), { target: { value: '5' } });
        fireEvent.change(screen.getByLabelText('Custom Placement Constraints'), { target: { value: 'node.role == manager' } });
        expect(patchSpy).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(patchSpy).toHaveBeenCalledWith(
            '/application/swarm/update',
            { swarmReplicas: '5', swarmPlacementConstraints: 'node.role == manager', isSwarmOnlyWorkerNodes: false },
            { preserveScroll: true },
        );
    });

    it('carries pending unsaved edits along when the checkbox triggers its own immediate submit', () => {
        render(<SwarmTab {...baseProps()} />);

        fireEvent.change(screen.getByLabelText('Replicas'), { target: { value: '7' } });
        fireEvent.click(screen.getByLabelText('Only Start on Worker nodes'));

        expect(patchSpy).toHaveBeenCalledWith(
            '/application/swarm/update',
            { swarmReplicas: '7', swarmPlacementConstraints: '', isSwarmOnlyWorkerNodes: true },
            { preserveScroll: true },
        );
    });
});

describe('SwarmTab - canUpdate gating', () => {
    it('disables every field and the Save button, with an explanatory tooltip, when canUpdate is false', () => {
        render(<SwarmTab {...baseProps({ canUpdate: false })} />);

        const saveButton = screen.getByRole('button', { name: 'Save' });
        expect(saveButton).toBeDisabled();
        expect(saveButton).toHaveAttribute(
            'title',
            "You don't have permission to update this application. Contact your team administrator for access.",
        );
        expect(screen.getByLabelText('Replicas')).toBeDisabled();
        expect(screen.getByLabelText('Custom Placement Constraints')).toBeDisabled();
        expect(screen.getByLabelText('Only Start on Worker nodes')).toBeDisabled();
    });

    it('enables every field and the Save button, with no tooltip, when canUpdate is true', () => {
        render(<SwarmTab {...baseProps({ canUpdate: true })} />);

        const saveButton = screen.getByRole('button', { name: 'Save' });
        expect(saveButton).not.toBeDisabled();
        expect(saveButton).not.toHaveAttribute('title');
        expect(screen.getByLabelText('Replicas')).not.toBeDisabled();
    });
});
