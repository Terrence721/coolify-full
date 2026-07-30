import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Edit from './Edit';

// Manually verified live end-to-end during the 2026-07-23 Non-realtime Hard-bucket smoke test
// (issue #25): rename + description both saved and persisted correctly, and "Delete Project"
// present and matching the Show page's behavior. This suite locks that in as automated coverage -
// the page was previously entirely untested. DeleteProjectModal is mocked out (it already has its
// own dedicated suite) so this stays focused on Edit's own form logic and the canDelete gate.

const putSpy = vi.fn();
const deleteProjectModalSpy = vi.fn();

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

vi.mock('../../Components/DeleteProjectModal', () => ({
    default: (props) => {
        deleteProjectModalSpy(props);
        return props.open ? <div data-testid="delete-project-modal" /> : null;
    },
}));

function baseProps(overrides = {}) {
    return {
        project: { uuid: 'project-uuid-1', name: 'E-Commerce Platform', description: 'Storefront and checkout', isEmpty: true },
        canDelete: true,
        updateUrl: '/project/project-uuid-1/edit',
        deleteUrl: '/project/project-uuid-1',
        ...overrides,
    };
}

describe('Project/Edit', () => {
    it("renders the project's current name and description in the form", () => {
        render(<Edit {...baseProps()} />);
        expect(screen.getByDisplayValue('E-Commerce Platform')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Storefront and checkout')).toBeInTheDocument();
    });

    it('falls back to an empty string when description is null', () => {
        render(<Edit {...baseProps({ project: { uuid: 'p1', name: 'Internal Tools', description: null, isEmpty: true } })} />);
        expect(screen.getByLabelText('Description')).toHaveValue('');
    });

    it('submits the edited name and description via put(updateUrl)', () => {
        putSpy.mockClear();
        render(<Edit {...baseProps()} />);

        const nameInput = screen.getByDisplayValue('E-Commerce Platform');
        act(() => {
            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(nameInput, 'Renamed Platform');
            nameInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(screen.getByDisplayValue('Renamed Platform')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Save' }).click());
        expect(putSpy).toHaveBeenCalledWith('/project/project-uuid-1/edit');
    });

    it('only shows the "Delete Project" button when canDelete is true', () => {
        const { unmount } = render(<Edit {...baseProps({ canDelete: false })} />);
        expect(screen.queryByRole('button', { name: 'Delete Project' })).not.toBeInTheDocument();
        unmount();

        render(<Edit {...baseProps({ canDelete: true })} />);
        expect(screen.getByRole('button', { name: 'Delete Project' })).toBeInTheDocument();
    });

    it('opens DeleteProjectModal with the project name and disabled state, closes via onClose', () => {
        deleteProjectModalSpy.mockClear();
        render(<Edit {...baseProps({ project: { uuid: 'p1', name: 'E-Commerce Platform', description: '', isEmpty: false } })} />);

        expect(screen.queryByTestId('delete-project-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Delete Project' }).click());

        expect(screen.getByTestId('delete-project-modal')).toBeInTheDocument();
        expect(deleteProjectModalSpy).toHaveBeenLastCalledWith(
            expect.objectContaining({
                open: true,
                projectName: 'E-Commerce Platform',
                disabled: true,
                deleteUrl: '/project/project-uuid-1',
            }),
        );

        const onClose = deleteProjectModalSpy.mock.calls.at(-1)[0].onClose;
        act(() => onClose());
        expect(screen.queryByTestId('delete-project-modal')).not.toBeInTheDocument();
    });

    it('passes disabled: false to DeleteProjectModal when the project is genuinely empty', () => {
        deleteProjectModalSpy.mockClear();
        render(<Edit {...baseProps({ project: { uuid: 'p1', name: 'Empty Project', description: '', isEmpty: true } })} />);

        act(() => screen.getByRole('button', { name: 'Delete Project' }).click());

        expect(deleteProjectModalSpy).toHaveBeenLastCalledWith(expect.objectContaining({ disabled: false }));
    });
});
