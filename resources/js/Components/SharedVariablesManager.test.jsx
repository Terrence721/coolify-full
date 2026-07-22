import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SharedVariablesManager from './SharedVariablesManager';

// Covers the logic bugs are actually likely to hide in here: the delete-confirmation gating
// (typed text must exactly match the variable's key before the destructive action is enabled -
// same class of bug as DomainConflictModal/RollbackTab's confirmation guards), the isShownOnce
// variant (a locked, write-once secret that only ever exposes its comment for editing, never its
// key/value), the normal-vs-dev view toggle, and canUpdate permission gating hiding every
// mutating control when the viewer lacks write access.

const putSpy = vi.fn();
const postSpy = vi.fn();
const deleteSpy = vi.fn();
const formPostSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        put: (url, data, options) => putSpy(url, data, options),
        post: (url, data, options) => postSpy(url, data, options),
        delete: (url, options) => deleteSpy(url, options),
    },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        const [errors] = useState({});
        const [processing] = useState(false);

        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => {
                formPostSpy(url, data, options);
                options?.onSuccess?.();
            },
            processing,
            errors,
            reset: () => setDataState(initial),
            clearErrors: () => {},
        };
    },
}));

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function variable(overrides = {}) {
    return {
        id: 1,
        key: 'API_KEY',
        value: 'secret-value',
        comment: '',
        isMultiline: false,
        isLiteral: false,
        isShownOnce: false,
        updateUrl: '/variables/1',
        lockUrl: '/variables/1/lock',
        deleteUrl: '/variables/1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        label: 'production',
        scope: 'environment',
        canUpdate: true,
        variables: [],
        devViewText: '',
        storeUrl: '/variables',
        bulkUpdateUrl: '/variables/bulk',
        ...overrides,
    };
}

beforeEach(() => {
    putSpy.mockClear();
    postSpy.mockClear();
    deleteSpy.mockClear();
    formPostSpy.mockClear();
});

describe('SharedVariablesManager', () => {
    it('shows the team heading when scope is team', () => {
        render(<SharedVariablesManager {...baseProps({ scope: 'team', label: 'ignored' })} />);

        expect(screen.getByText('Team Shared Variables')).toBeInTheDocument();
    });

    it('shows a label-specific heading for non-team scopes', () => {
        render(<SharedVariablesManager {...baseProps({ scope: 'environment', label: 'staging' })} />);

        expect(screen.getByText('Shared Variables for staging')).toBeInTheDocument();
    });

    it('shows an empty state when there are no variables', () => {
        render(<SharedVariablesManager {...baseProps({ variables: [] })} />);

        expect(screen.getByText('No environment variables found.')).toBeInTheDocument();
    });

    it('renders one row per variable', () => {
        render(<SharedVariablesManager {...baseProps({ variables: [variable({ id: 1, key: 'ONE' }), variable({ id: 2, key: 'TWO' })] })} />);

        expect(screen.getByDisplayValue('ONE')).toBeInTheDocument();
        expect(screen.getByDisplayValue('TWO')).toBeInTheDocument();
    });

    it('hides the Add and Developer view buttons when canUpdate is false', () => {
        render(<SharedVariablesManager {...baseProps({ canUpdate: false })} />);

        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Developer view' })).not.toBeInTheDocument();
    });

    it('switches to the developer textarea view and back', () => {
        render(<SharedVariablesManager {...baseProps({ variables: [variable()], devViewText: 'API_KEY=secret-value' })} />);

        act(() => screen.getByRole('button', { name: 'Developer view' }).click());

        expect(screen.getByDisplayValue('API_KEY=secret-value')).toBeInTheDocument();
        expect(screen.queryByText('No environment variables found.')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Normal view' }).click());

        expect(screen.getByDisplayValue('API_KEY')).toBeInTheDocument();
    });

    it('renders an isShownOnce variable as a locked key with only its comment editable', () => {
        render(<SharedVariablesManager {...baseProps({ variables: [variable({ isShownOnce: true, comment: 'a note' })] })} />);

        const keyInput = screen.getByDisplayValue('API_KEY');
        expect(keyInput).toBeDisabled();
        expect(screen.queryByDisplayValue('secret-value')).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('a note')).toBeInTheDocument();
    });

    it('disables every input and hides mutating buttons for a normal row when canUpdate is false', () => {
        render(<SharedVariablesManager {...baseProps({ canUpdate: false, variables: [variable()] })} />);

        expect(screen.getByDisplayValue('API_KEY')).toBeDisabled();
        expect(screen.getByDisplayValue('secret-value')).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Update' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Lock' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('toggling multiline immediately persists via router.put, independent of the Update submit button', () => {
        render(<SharedVariablesManager {...baseProps({ variables: [variable()] })} />);

        act(() => screen.getByRole('checkbox', { name: 'Is Multiline?' }).click());

        expect(putSpy).toHaveBeenCalledWith(
            '/variables/1',
            expect.objectContaining({ is_multiline: true }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('keeps Permanently Delete disabled until the typed confirmation exactly matches the variable key', () => {
        render(<SharedVariablesManager {...baseProps({ variables: [variable({ key: 'API_KEY' })] })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        const deleteButton = screen.getByRole('button', { name: 'Permanently Delete' });
        const confirmInput = screen.getByPlaceholderText('API_KEY');
        expect(deleteButton).toBeDisabled();

        act(() => typeInto(confirmInput, 'API_KE'));
        expect(deleteButton).toBeDisabled();

        act(() => typeInto(confirmInput, 'API_KEY'));
        expect(deleteButton).not.toBeDisabled();

        act(() => deleteButton.click());
        expect(deleteSpy).toHaveBeenCalledWith('/variables/1', expect.objectContaining({ preserveScroll: true }));
    });

    it('keeps the isShownOnce delete confirmation gated the same way', () => {
        render(<SharedVariablesManager {...baseProps({ variables: [variable({ isShownOnce: true, key: 'ONE_TIME_TOKEN' })] })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        const deleteButton = screen.getByRole('button', { name: 'Permanently Delete' });
        const confirmInput = screen.getByPlaceholderText('ONE_TIME_TOKEN');

        act(() => typeInto(confirmInput, 'ONE_TIME_TOKEN'));
        expect(deleteButton).not.toBeDisabled();
    });

    it('opens the Add Variable modal and submits via useForm', () => {
        render(<SharedVariablesManager {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        act(() => typeInto(screen.getByPlaceholderText('NODE_ENV'), 'NEW_VAR'));
        act(() => typeInto(screen.getByPlaceholderText('production'), 'value'));
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(formPostSpy).toHaveBeenCalledWith('/variables', expect.objectContaining({ key: 'NEW_VAR', value: 'value' }), expect.anything());
    });
});
