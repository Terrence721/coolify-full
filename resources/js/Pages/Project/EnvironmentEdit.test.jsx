import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import EnvironmentEdit from './EnvironmentEdit';

// Manually verified live end-to-end during the 2026-07-23/24 Non-realtime Hard-bucket smoke test
// (issue #25): the breadcrumb's project-name link correctly navigated back to /project/{uuid},
// and "Delete Environment" worked with typed-name confirmation on a genuinely-empty environment.
// This suite locks that in as automated coverage - the page was previously entirely untested.
// DeleteEnvironmentModal is mocked out (already has its own dedicated suite) so this stays
// focused on EnvironmentEdit's own form logic and the canUpdate/canDelete gates.

const putSpy = vi.fn();
const deleteEnvironmentModalSpy = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');
    return {
        useForm: (initial) => {
            const [data, setDataState] = useState(initial);
            return {
                data,
                setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
                put: (url) => putSpy(url),
                processing: false,
                errors: {},
            };
        },
    };
});

vi.mock('../../Components/DeleteEnvironmentModal', () => ({
    default: (props) => {
        deleteEnvironmentModalSpy(props);
        return (
            <div data-testid="delete-environment-modal">
                <button type="button" onClick={props.onClose}>
                    Close Modal
                </button>
            </div>
        );
    },
}));

function baseProps(overrides = {}) {
    return {
        project: { name: 'E-Commerce Platform' },
        environment: { name: 'production', description: 'Live environment' },
        canUpdate: true,
        canDelete: true,
        projectShowUrl: '/project/project-uuid-1',
        resourceIndexUrl: '/project/project-uuid-1/environment/env-uuid-1',
        updateUrl: '/project/project-uuid-1/environment/env-uuid-1/edit',
        deleteUrl: '/project/project-uuid-1/environment/env-uuid-1',
        ...overrides,
    };
}

describe('Project/EnvironmentEdit', () => {
    it("renders the environment's current name and description in the form", () => {
        render(<EnvironmentEdit {...baseProps()} />);
        expect(screen.getByDisplayValue('production')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Live environment')).toBeInTheDocument();
    });

    it('links the breadcrumb to the project Show page and the environment resource index', () => {
        render(<EnvironmentEdit {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'E-Commerce Platform' })).toHaveAttribute('href', '/project/project-uuid-1');
        expect(screen.getByRole('link', { name: 'production' })).toHaveAttribute('href', '/project/project-uuid-1/environment/env-uuid-1');
    });

    it('submits the edited name and description via put(updateUrl)', () => {
        putSpy.mockClear();
        render(<EnvironmentEdit {...baseProps()} />);

        const nameInput = screen.getByDisplayValue('production');
        act(() => {
            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(nameInput, 'staging');
            nameInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(screen.getByDisplayValue('staging')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Save' }).click());
        expect(putSpy).toHaveBeenCalledWith('/project/project-uuid-1/environment/env-uuid-1/edit');
    });

    it('only shows "Save" when canUpdate is true, and disables the fields when false', () => {
        const { unmount } = render(<EnvironmentEdit {...baseProps({ canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('production')).toBeDisabled();
        expect(screen.getByDisplayValue('Live environment')).toBeDisabled();
        unmount();

        render(<EnvironmentEdit {...baseProps({ canUpdate: true })} />);
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
        expect(screen.getByDisplayValue('production')).not.toBeDisabled();
    });

    it('only shows "Delete Environment" when canDelete is true', () => {
        const { unmount } = render(<EnvironmentEdit {...baseProps({ canDelete: false })} />);
        expect(screen.queryByRole('button', { name: 'Delete Environment' })).not.toBeInTheDocument();
        unmount();

        render(<EnvironmentEdit {...baseProps({ canDelete: true })} />);
        expect(screen.getByRole('button', { name: 'Delete Environment' })).toBeInTheDocument();
    });

    it('opens DeleteEnvironmentModal with the environment and deleteUrl, closes via onClose', () => {
        deleteEnvironmentModalSpy.mockClear();
        render(<EnvironmentEdit {...baseProps()} />);

        expect(screen.queryByTestId('delete-environment-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Delete Environment' }).click());

        expect(screen.getByTestId('delete-environment-modal')).toBeInTheDocument();
        expect(deleteEnvironmentModalSpy).toHaveBeenLastCalledWith(
            expect.objectContaining({
                environment: { name: 'production', description: 'Live environment' },
                deleteUrl: '/project/project-uuid-1/environment/env-uuid-1',
            }),
        );

        act(() => screen.getByRole('button', { name: 'Close Modal' }).click());
        expect(screen.queryByTestId('delete-environment-modal')).not.toBeInTheDocument();
    });
});
