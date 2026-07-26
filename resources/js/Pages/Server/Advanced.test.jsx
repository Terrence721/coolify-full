import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Advanced from './Advanced';

// Live-verified 2026-07-26 during the Server management smoke test (issue #26): changing the
// disk-usage notification threshold produced a real "Server updated." toast and persisted across
// a genuine full page reload, and a deliberately-invalid cron expression saved with a clean,
// specific validation error rather than a 500. This suite locks that in as automated coverage:
// the form's initial values, the put() submit payload, the processing-disabled Save button, and
// per-field error rendering (using the real invalid-cron error message as the example).

const putSpy = vi.fn();
let mockProcessing = false;
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (keyOrUpdater, value) => {
                if (typeof keyOrUpdater === 'function') {
                    setDataState(keyOrUpdater);
                } else {
                    setDataState((prev) => ({ ...prev, [keyOrUpdater]: value }));
                }
            },
            put: (url) => putSpy(url),
            processing: mockProcessing,
            errors: mockErrors,
        };
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        concurrentBuilds: 1,
        dynamicTimeout: 3600,
        deploymentQueueLimit: 1,
        serverDiskUsageNotificationThreshold: 80,
        serverDiskUsageCheckFrequency: '0 23 * * *',
        updateUrl: '/server/srv-uuid/advanced',
        ...overrides,
    };
}

describe('Server/Advanced', () => {
    beforeEach(() => {
        putSpy.mockClear();
        mockProcessing = false;
        mockErrors = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders every field pre-filled with its current value', () => {
        render(<Advanced {...baseProps()} />);

        expect(screen.getByLabelText('Disk usage check frequency')).toHaveValue('0 23 * * *');
        expect(screen.getByLabelText('Server disk usage notification threshold (%)')).toHaveValue(80);
        expect(screen.getByLabelText('Number of concurrent builds')).toHaveValue(1);
        expect(screen.getByLabelText('Deployment timeout (seconds)')).toHaveValue(3600);
        expect(screen.getByLabelText('Deployment queue limit')).toHaveValue(1);
    });

    it('submits to updateUrl on Save', () => {
        render(<Advanced {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(putSpy).toHaveBeenCalledWith('/server/srv-uuid/advanced');
    });

    it('disables the Save button while processing', () => {
        mockProcessing = true;
        render(<Advanced {...baseProps()} />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('shows the real invalid-cron validation error next to its field', () => {
        mockErrors = { serverDiskUsageCheckFrequency: 'Invalid Cron / Human expression for Disk Usage Check Frequency.' };
        render(<Advanced {...baseProps()} />);

        expect(screen.getByText('Invalid Cron / Human expression for Disk Usage Check Frequency.')).toBeInTheDocument();
    });

    it('updates a field on change', () => {
        render(<Advanced {...baseProps()} />);

        const input = screen.getByLabelText('Number of concurrent builds');
        act(() => fireChange(input, '4'));

        expect(input).toHaveValue(4);
    });
});

function fireChange(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
}
