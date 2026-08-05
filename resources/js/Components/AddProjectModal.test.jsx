import { act, useState } from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AddProjectModal from './AddProjectModal';

// Untested since the Livewire port. Mocked out (not exercised) everywhere it's actually opened
// from - Dashboard.jsx (the exact page a real canCreateKey permission-gating bug was found and
// fixed in earlier this session) and Project/Index.jsx's own "+ Add" button - so its own submit/
// close/error-rendering logic has never had dedicated coverage. Real behavior worth locking in:
// unlike its sibling AddStorageModal.jsx, submit() has no onSuccess callback at all - it doesn't
// close itself on a successful create, relying on the resulting redirect to a new project's page
// instead. Getting that wrong (auto-closing before the redirect lands, or never redirecting and
// leaving the modal stuck open) would be a real, silent regression.

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

const postSpy = vi.fn();
let mockProcessing = false;
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, options),
            processing: mockProcessing,
            errors: mockErrors,
        };
    },
}));

function baseProps(overrides = {}) {
    return {
        createUrl: '/project/store',
        onClose: vi.fn(),
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
    mockProcessing = false;
    mockErrors = {};
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('AddProjectModal', () => {
    it('renders the Name and Description fields, with Name required', () => {
        render(<AddProjectModal {...baseProps()} />);

        expect(screen.getByLabelText('Name')).toBeRequired();
        expect(screen.getByLabelText('Description')).not.toBeRequired();
    });

    it('lets the user type into the Name and Description fields', () => {
        render(<AddProjectModal {...baseProps()} />);

        act(() => typeInto(screen.getByLabelText('Name'), 'My New Project'));
        act(() => typeInto(screen.getByLabelText('Description'), 'a description'));

        expect(screen.getByLabelText('Name')).toHaveValue('My New Project');
        expect(screen.getByLabelText('Description')).toHaveValue('a description');
    });

    it('submits via post(createUrl, { preserveScroll: true }) with no onSuccess callback', () => {
        render(<AddProjectModal {...baseProps()} />);

        const form = screen.getByRole('button', { name: 'Save' }).closest('form');
        act(() => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

        expect(postSpy).toHaveBeenCalledWith('/project/store', { preserveScroll: true });
        const options = postSpy.mock.calls[0][1];
        expect(options.onSuccess).toBeUndefined();
    });

    it('shows validation errors for both fields when present', () => {
        mockErrors = { name: 'The name field is required.', description: 'The description is invalid.' };
        render(<AddProjectModal {...baseProps()} />);

        expect(screen.getByText('The name field is required.')).toBeInTheDocument();
        expect(screen.getByText('The description is invalid.')).toBeInTheDocument();
    });

    it('disables the Save button while processing', () => {
        mockProcessing = true;
        render(<AddProjectModal {...baseProps()} />);
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('calls onClose via the X button and the backdrop click, without submitting', () => {
        const onClose = vi.fn();
        render(<AddProjectModal {...baseProps({ onClose })} />);

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(onClose).toHaveBeenCalledTimes(1);

        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(onClose).toHaveBeenCalledTimes(2);

        expect(postSpy).not.toHaveBeenCalled();
    });

    it('does not call onClose on submit', () => {
        const onClose = vi.fn();
        render(<AddProjectModal {...baseProps({ onClose })} />);

        const form = screen.getByRole('button', { name: 'Save' }).closest('form');
        act(() => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

        expect(onClose).not.toHaveBeenCalled();
    });
});
