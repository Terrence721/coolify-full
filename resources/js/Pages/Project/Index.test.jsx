import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Index from './Index';

// Manually verified live end-to-end during a 2026-07-29 Playwright session (docs/smoketest.md's
// /projects item): a 1-environment project's card correctly landed on the Resources page while a
// 2-environment or 0-environment one landed on the project Show page, matching Project::navigateTo()
// exactly; "+ Add Resource" showed only when a project had at least one environment, matching
// addResourceUrl's null-on-empty logic; and the "+ Add" modal created a real project and redirected
// into its auto-created environment. This suite locks that in as automated coverage - the page was
// previously entirely untested. AddProjectModal is mocked out so this suite stays focused on Index's
// own conditional rendering.

const addProjectModalSpy = vi.fn();

vi.mock('../../Components/AddProjectModal', () => ({
    default: (props) => {
        addProjectModalSpy(props);
        return (
            <div data-testid="add-project-modal">
                <button type="button" onClick={props.onClose}>
                    Close Modal
                </button>
            </div>
        );
    },
}));

function baseProject(overrides = {}) {
    return {
        uuid: 'project-uuid-1',
        name: 'E-Commerce Platform',
        description: 'Storefront and checkout',
        canUpdate: true,
        navigateUrl: '/project/project-uuid-1/environment/env-uuid-1',
        editUrl: '/project/project-uuid-1/edit',
        addResourceUrl: '/project/project-uuid-1/environment/env-uuid-1/new',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        projects: [baseProject()],
        canCreate: true,
        createUrl: '/projects',
        ...overrides,
    };
}

describe('Project/Index', () => {
    it("renders each project's name and description", () => {
        render(<Index {...baseProps()} />);
        expect(screen.getByText('E-Commerce Platform')).toBeInTheDocument();
        expect(screen.getByText('Storefront and checkout')).toBeInTheDocument();
    });

    it('links the whole card to navigateUrl, whatever page it resolves to', () => {
        render(<Index {...baseProps({ projects: [baseProject({ navigateUrl: '/project/project-uuid-1' })] })} />);
        const cardLink = document.querySelector('a.absolute');
        expect(cardLink).toHaveAttribute('href', '/project/project-uuid-1');
    });

    it('shows "+ Add Resource" when addResourceUrl is present, hides it when null', () => {
        const { unmount } = render(<Index {...baseProps({ projects: [baseProject({ addResourceUrl: null })] })} />);
        expect(screen.queryByText('+ Add Resource')).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps()} />);
        expect(screen.getByRole('link', { name: '+ Add Resource' })).toHaveAttribute('href', '/project/project-uuid-1/environment/env-uuid-1/new');
    });

    it('shows "Settings" only when canUpdate is true', () => {
        const { unmount } = render(<Index {...baseProps({ projects: [baseProject({ canUpdate: false })] })} />);
        expect(screen.queryByText('Settings')).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/project/project-uuid-1/edit');
    });

    it('only shows the "+ Add" button when canCreate is true', () => {
        const { unmount } = render(<Index {...baseProps({ canCreate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ canCreate: true })} />);
        expect(screen.getByRole('button', { name: '+ Add' })).toBeInTheDocument();
    });

    it('opens AddProjectModal with createUrl on "+ Add", and closes it via onClose', () => {
        addProjectModalSpy.mockClear();
        render(<Index {...baseProps()} />);

        expect(screen.queryByTestId('add-project-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByTestId('add-project-modal')).toBeInTheDocument();
        expect(addProjectModalSpy).toHaveBeenCalledWith(expect.objectContaining({ createUrl: '/projects' }));

        act(() => screen.getByRole('button', { name: 'Close Modal' }).click());
        expect(screen.queryByTestId('add-project-modal')).not.toBeInTheDocument();
    });

    it('renders multiple projects independently, each with their own gates', () => {
        render(
            <Index
                {...baseProps({
                    projects: [
                        baseProject({ uuid: 'p1', name: 'E-Commerce Platform', addResourceUrl: '/new-1' }),
                        baseProject({ uuid: 'p2', name: 'Internal Tools', addResourceUrl: null, canUpdate: false }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('E-Commerce Platform')).toBeInTheDocument();
        expect(screen.getByText('Internal Tools')).toBeInTheDocument();
        expect(screen.getAllByText('+ Add Resource')).toHaveLength(1);
        expect(screen.getAllByText('Settings')).toHaveLength(1);
    });
});
