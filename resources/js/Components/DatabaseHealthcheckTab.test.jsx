import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DatabaseHealthcheckTab from './DatabaseHealthcheckTab';

// Real logic: an asymmetric enable/disable gate - disabling an active healthcheck fires
// immediately, but enabling one requires a typed-free (just click-through) confirm modal warning
// the resource may be marked unhealthy - plus a disabled-state warning banner and canUpdate
// disabling every field. Previously untested.

const patchSpy = vi.fn();
const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, options) => patchSpy(url, data, options),
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

function baseHealthcheck(overrides = {}) {
    return {
        enabled: true,
        interval: 15,
        timeout: 5,
        retries: 5,
        startPeriod: 5,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        healthcheck: baseHealthcheck(overrides.healthcheck),
        healthcheckUrls: { update: '/db/healthcheck/update', toggle: '/db/healthcheck/toggle' },
        canUpdate: true,
        ...overrides,
    };
}

afterEach(() => {
    patchSpy.mockClear();
    postSpy.mockClear();
    vi.restoreAllMocks();
});

describe('DatabaseHealthcheckTab - canUpdate gating', () => {
    it('hides Save/toggle buttons and disables every field when canUpdate is false', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ canUpdate: false })} />);

        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Disable Healthcheck' })).not.toBeInTheDocument();
        expect(screen.getByLabelText('Interval (s)')).toBeDisabled();
        expect(screen.getByLabelText('Timeout (s)')).toBeDisabled();
        expect(screen.getByLabelText('Retries')).toBeDisabled();
        expect(screen.getByLabelText('Start Period (s)')).toBeDisabled();
    });

    it('shows Save and the toggle button, with fields enabled, when canUpdate is true', () => {
        render(<DatabaseHealthcheckTab {...baseProps()} />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
        expect(screen.getByLabelText('Interval (s)')).not.toBeDisabled();
    });
});

describe('DatabaseHealthcheckTab - enabled/disabled state', () => {
    it('shows the disabled-state warning banner and an Enable button when healthcheck is off', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: false } })} />);

        expect(screen.getByText('Healthcheck disabled.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enable Healthcheck' })).toBeInTheDocument();
    });

    it('hides the warning banner and shows a Disable button when healthcheck is on', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: true } })} />);

        expect(screen.queryByText('Healthcheck disabled.')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Disable Healthcheck' })).toBeInTheDocument();
    });

    it('pre-fills the number fields from the healthcheck prop', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: true, interval: 30, timeout: 10, retries: 3, startPeriod: 20 } })} />);

        expect(screen.getByLabelText('Interval (s)')).toHaveValue(30);
        expect(screen.getByLabelText('Timeout (s)')).toHaveValue(10);
        expect(screen.getByLabelText('Retries')).toHaveValue(3);
        expect(screen.getByLabelText('Start Period (s)')).toHaveValue(20);
    });

    it('falls back to Docker-default values when the healthcheck prop omits them', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: true } })} />);

        expect(screen.getByLabelText('Interval (s)')).toHaveValue(15);
        expect(screen.getByLabelText('Timeout (s)')).toHaveValue(5);
        expect(screen.getByLabelText('Retries')).toHaveValue(5);
        expect(screen.getByLabelText('Start Period (s)')).toHaveValue(5);
    });
});

describe('DatabaseHealthcheckTab - disable is immediate, enable requires confirmation', () => {
    it('disabling an active healthcheck posts to toggleUrl immediately, no modal', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: true } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Disable Healthcheck' }));

        expect(postSpy).toHaveBeenCalledWith('/db/healthcheck/toggle', {}, { preserveScroll: true });
        expect(screen.queryByText('Confirm Healthcheck Enable?')).not.toBeInTheDocument();
    });

    it('clicking Enable on a disabled healthcheck opens the confirm modal without posting yet', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: false } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Enable Healthcheck' }));

        expect(screen.getByText('Confirm Healthcheck Enable?')).toBeInTheDocument();
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('Cancel closes the confirm modal without posting', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: false } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Enable Healthcheck' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByText('Confirm Healthcheck Enable?')).not.toBeInTheDocument();
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('confirming inside the modal posts to toggleUrl and closes it', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: false } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Enable Healthcheck' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Enable Healthcheck' })[1]);

        expect(postSpy).toHaveBeenCalledWith('/db/healthcheck/toggle', {}, { preserveScroll: true });
        expect(screen.queryByText('Confirm Healthcheck Enable?')).not.toBeInTheDocument();
    });
});

describe('DatabaseHealthcheckTab - submit', () => {
    it('submits the probe numbers plus the current enabled state to updateUrl', () => {
        render(<DatabaseHealthcheckTab {...baseProps({ healthcheck: { enabled: true, interval: 30, timeout: 10, retries: 3, startPeriod: 20 } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(patchSpy).toHaveBeenCalledWith(
            '/db/healthcheck/update',
            { interval: 30, timeout: 10, retries: 3, startPeriod: 20, enabled: true },
            { preserveScroll: true },
        );
    });
});
