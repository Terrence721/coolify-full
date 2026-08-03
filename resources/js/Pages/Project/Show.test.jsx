import { fireEvent, render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// The project-level environment list, a high-traffic navigation surface (every project page load
// goes through here). Real logic: the "+ Add" modal's openAddModal() must reset()/clearErrors()
// before showing, or a name typed into a previous open (then cancelled) leaks into the next one -
// a subtle regression easy to miss since the modal *looks* blank until you check the submitted
// value. Also wires DeleteProjectModal's `disabled` prop to `!project.isEmpty`, a real business
// rule (can't delete a project with resources still in it). Previously entirely untested.

const postSpy = vi.fn();
const deleteSpy = vi.fn();
let formErrors = {};

vi.mock('@inertiajs/react', () => ({
    router: { delete: (url) => deleteSpy(url) },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => {
                postSpy(url, options);
                options?.onSuccess?.();
            },
            processing: false,
            errors: formErrors,
            reset: () => setDataState(initial),
            clearErrors: () => {
                formErrors = {};
            },
        };
    },
}));

function baseEnvironment(overrides = {}) {
    return {
        uuid: 'env-1',
        name: 'production',
        description: 'Production environment',
        showUrl: '/project/foo/production',
        editUrl: '/project/foo/production/edit',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        project: { name: 'My Project', isEmpty: true },
        environments: [],
        canUpdate: true,
        canDelete: true,
        createEnvironmentUrl: '/project/foo/environments',
        deleteUrl: '/project/foo',
        ...overrides,
    };
}

describe('Project/Show', () => {
    beforeEach(() => {
        postSpy.mockClear();
        deleteSpy.mockClear();
        formErrors = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the empty state when there are no environments', () => {
        render(<Show {...baseProps({ environments: [] })} />);
        expect(screen.getByText('No environments found.')).toBeInTheDocument();
    });

    it("renders each environment's name and description", () => {
        render(<Show {...baseProps({ environments: [baseEnvironment()] })} />);
        expect(screen.getByText('production')).toBeInTheDocument();
        expect(screen.getByText('Production environment')).toBeInTheDocument();
    });

    it('hides the "+ Add" button and per-environment Settings links when canUpdate is false', () => {
        render(<Show {...baseProps({ canUpdate: false, environments: [baseEnvironment()] })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
    });

    it('hides the "Delete Project" button when canDelete is false', () => {
        render(<Show {...baseProps({ canDelete: false })} />);
        expect(screen.queryByRole('button', { name: 'Delete Project' })).not.toBeInTheDocument();
    });

    it('opens the add-environment modal with a blank form', () => {
        render(<Show {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByText('New Environment')).toBeInTheDocument();
        expect(screen.getByLabelText('Name')).toHaveValue('');
    });

    it('does not leak a typed name from a cancelled open into the next one', () => {
        render(<Show {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'staging' } });
        act(() => screen.getByRole('button', { name: '✕' }).click());
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByLabelText('Name')).toHaveValue('');
    });

    it('submits via post(createEnvironmentUrl, { preserveScroll: true }) and closes the modal on success', () => {
        render(<Show {...baseProps({ createEnvironmentUrl: '/project/foo/environments' })} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'staging' } });
        act(() => fireEvent.submit(screen.getByLabelText('Name').closest('form')));

        expect(postSpy).toHaveBeenCalledWith('/project/foo/environments', expect.objectContaining({ preserveScroll: true }));
        expect(screen.queryByText('New Environment')).not.toBeInTheDocument();
    });

    it('disables project deletion via DeleteProjectModal when the project is not empty', () => {
        render(<Show {...baseProps({ project: { name: 'My Project', isEmpty: false } })} />);

        act(() => screen.getByRole('button', { name: 'Delete Project' }).click());

        expect(screen.getByText('This project has resources defined, please delete them first.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Permanently Delete' })).not.toBeInTheDocument();
    });

    it('allows project deletion via DeleteProjectModal when the project is empty', () => {
        render(<Show {...baseProps({ project: { name: 'My Project', isEmpty: true } })} />);

        act(() => screen.getByRole('button', { name: 'Delete Project' }).click());

        expect(screen.queryByText('This project has resources defined, please delete them first.')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Permanently Delete' })).toBeInTheDocument();
    });
});
