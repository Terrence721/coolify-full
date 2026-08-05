import { act, useState } from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Updates from './Updates';

// The instance-wide Updates settings page - real branching risk: the "Auto Update" Frequency
// field swaps between an editable, data-bound input (auto_update_frequency, required) and an
// entirely separate disabled placeholder input (auto_update_frequency_disabled, no value/onChange
// at all) depending on is_auto_update_enabled. Both share the same "Frequency (cron expression)"
// label text and sit in the same ternary slot with no key, so a regression here (e.g. always
// rendering the disabled variant, or leaking the bound value into the disabled one) wouldn't be
// visually obvious. Also covers the separate Check Manually action, which posts to its own URL
// rather than going through the form's put().

const putSpy = vi.fn();
const postSpy = vi.fn();
let mockProcessing = false;
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url) => putSpy(url),
            processing: mockProcessing,
            errors: mockErrors,
        };
    },
    router: { post: (url) => postSpy(url) },
}));

function baseProps(overrides = {}) {
    return {
        updateCheckFrequency: '0 * * * *',
        autoUpdateFrequency: '0 0 * * *',
        isAutoUpdateEnabled: false,
        updateUrl: '/settings/updates',
        checkManuallyUrl: '/settings/updates/check',
        ...overrides,
    };
}

beforeEach(() => {
    putSpy.mockClear();
    postSpy.mockClear();
    mockProcessing = false;
    mockErrors = {};
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Settings/Updates', () => {
    it('renders the update check frequency with its initial value', () => {
        render(<Updates {...baseProps({ updateCheckFrequency: '*/30 * * * *' })} />);
        expect(screen.getByLabelText('Update Check Frequency')).toHaveValue('*/30 * * * *');
    });

    it('shows a disabled, unbound placeholder field when auto update is off', () => {
        render(<Updates {...baseProps({ isAutoUpdateEnabled: false, autoUpdateFrequency: '0 0 * * *' })} />);

        const frequencyField = screen.getByLabelText('Frequency (cron expression)');
        expect(frequencyField).toBeDisabled();
        expect(frequencyField).toHaveAttribute('placeholder', 'disabled');
        expect(frequencyField).toHaveAttribute('id', 'auto_update_frequency_disabled');
        // The disabled variant has no value prop at all - the bound frequency must not leak into it.
        expect(frequencyField).not.toHaveValue('0 0 * * *');
    });

    it('shows the editable, data-bound field with its value when auto update is on', () => {
        render(<Updates {...baseProps({ isAutoUpdateEnabled: true, autoUpdateFrequency: '0 3 * * *' })} />);

        const frequencyField = screen.getByLabelText('Frequency (cron expression)');
        expect(frequencyField).toBeEnabled();
        expect(frequencyField).toHaveAttribute('id', 'auto_update_frequency');
        expect(frequencyField).toHaveValue('0 3 * * *');
    });

    it('swaps from the disabled placeholder to the editable field when Enabled is checked', () => {
        render(<Updates {...baseProps({ isAutoUpdateEnabled: false, autoUpdateFrequency: '0 5 * * *' })} />);

        expect(screen.getByLabelText('Frequency (cron expression)')).toBeDisabled();

        act(() => screen.getByLabelText('Enabled').click());

        const frequencyField = screen.getByLabelText('Frequency (cron expression)');
        expect(frequencyField).toBeEnabled();
        expect(frequencyField).toHaveValue('0 5 * * *');
    });

    it('submits via put to updateUrl on Save', () => {
        render(<Updates {...baseProps()} />);

        const form = screen.getByRole('button', { name: 'Save' }).closest('form');
        act(() => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

        expect(putSpy).toHaveBeenCalledWith('/settings/updates');
    });

    it('calls router.post with checkManuallyUrl when Check Manually is clicked, separately from Save', () => {
        render(<Updates {...baseProps()} />);

        screen.getByRole('button', { name: 'Check Manually' }).click();

        expect(postSpy).toHaveBeenCalledWith('/settings/updates/check');
        expect(putSpy).not.toHaveBeenCalled();
    });

    it('shows validation errors for both frequency fields when present', () => {
        mockErrors = {
            update_check_frequency: 'The update check frequency is invalid.',
            auto_update_frequency: 'The auto update frequency is invalid.',
        };
        render(<Updates {...baseProps({ isAutoUpdateEnabled: true })} />);

        expect(screen.getByText('The update check frequency is invalid.')).toBeInTheDocument();
        expect(screen.getByText('The auto update frequency is invalid.')).toBeInTheDocument();
    });

    it('disables the Save button while processing', () => {
        mockProcessing = true;
        render(<Updates {...baseProps()} />);
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('marks Updates as the active sub-menu item', () => {
        render(<Updates {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'Updates' })).toHaveClass('menu-item-active');
    });
});
