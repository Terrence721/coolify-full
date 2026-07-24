import { render, screen, within } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The environment resources page - untested despite being one of the most-visited pages in the
// app (the landing page for any environment with resources). Covers the status-badge
// classification, the search/filter/sort logic shared across all three resource types, the two
// distinct empty-state variants (genuinely-empty environment vs. a filtered-to-zero search), the
// breadcrumb hover dropdowns, and the Delete Environment modal wiring - previously only ever
// manually verified.

// React 19 patches the native <input> value setter to track controlled-component state -
// directly assigning `.value` then dispatching a bare event doesn't notify it. Using the real
// native setter first (bypassing React's patched one) is the standard workaround absent
// @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

const deleteEnvironmentModalSpy = vi.fn();

vi.mock('../../../Components/DeleteEnvironmentModal', () => ({
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

function app(overrides = {}) {
    return {
        uuid: 'app-1',
        name: 'storefront-web',
        description: 'Main storefront',
        fqdn: 'storefront.example.com',
        status: 'running:healthy',
        server_status: true,
        destination: { server: { name: 'production-01' } },
        tags: [],
        hrefLink: '/project/proj/environment/env/application/app-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        project: { uuid: 'proj-uuid', name: 'E-Commerce Platform' },
        environment: { uuid: 'env-uuid', name: 'production', isEmpty: false, resourceIndexUrl: '/project/proj/environment/env' },
        allProjects: [
            { uuid: 'proj-uuid', name: 'E-Commerce Platform', showUrl: '/project/proj-uuid' },
            { uuid: 'other-proj', name: 'Internal Tools', showUrl: '/project/other-proj' },
        ],
        allEnvironments: [
            { uuid: 'env-uuid', name: 'production', resources: [], resourceIndexUrl: '/project/proj/environment/env' },
            {
                uuid: 'env-uuid-2',
                name: 'staging',
                resources: [{ uuid: 'r1', name: 'staging-app', url: '/project/proj/environment/env2/application/r1' }],
                resourceIndexUrl: '/project/proj/environment/env2',
            },
        ],
        applications: [],
        databases: [],
        services: [],
        canCreate: true,
        canDelete: true,
        projectShowUrl: '/project/proj-uuid',
        createUrl: '/project/proj/environment/env/new',
        cloneUrl: '/project/proj/environment/env/clone',
        deleteUrl: '/project/proj/environment/env',
        ...overrides,
    };
}

describe('Project/Resource/Index', () => {
    beforeEach(() => {
        deleteEnvironmentModalSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('status badges', () => {
        it.each([
            ['running:healthy', 'running', 'bg-success'],
            ['exited', 'exited', 'bg-error'],
            ['starting', 'starting', 'bg-warning'],
            ['restarting', 'restarting', 'bg-warning'],
            ['degraded', 'degraded', 'bg-warning'],
        ])('renders a %s badge for status %s', (status, title, klass) => {
            render(<Index {...baseProps({ applications: [app({ status })] })} />);
            const badge = document.querySelector(`[title="${title}"]`);
            expect(badge).toBeInTheDocument();
            expect(badge.className).toContain(klass);
        });

        it('renders no badge for an unrecognized or missing status', () => {
            render(<Index {...baseProps({ applications: [app({ status: null })] })} />);
            expect(document.querySelector('[title]')).not.toBeInTheDocument();
        });
    });

    it('shows the server name, or "Unknown" when the destination has no server', () => {
        const { unmount } = render(<Index {...baseProps({ applications: [app()] })} />);
        expect(screen.getByText('production-01')).toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ applications: [app({ destination: null })] })} />);
        expect(screen.getByText('Unknown')).toBeInTheDocument();
    });

    it('shows the unreachable-server warning only when server_status is false', () => {
        const { unmount } = render(<Index {...baseProps({ applications: [app({ server_status: true })] })} />);
        expect(screen.queryByText('Server is unreachable or misconfigured')).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ applications: [app({ server_status: false })] })} />);
        expect(screen.getByText('Server is unreachable or misconfigured')).toBeInTheDocument();
    });

    it('renders each tag as a link plus an Add tag link', () => {
        render(<Index {...baseProps({ applications: [app({ tags: [{ id: 1, name: 'prod' }] })] })} />);
        expect(screen.getByRole('link', { name: 'prod' })).toHaveAttribute('href', '/tags/prod');
        expect(screen.getByRole('link', { name: 'Add tag' })).toHaveAttribute('href', '/project/proj/environment/env/application/app-1/tags');
    });

    describe('search/filter/sort', () => {
        it('filters applications by name, fqdn, description, and tag, case-insensitively', () => {
            const apps = [
                app({ uuid: 'a1', name: 'zeta', fqdn: 'zeta.example.com' }),
                app({ uuid: 'a2', name: 'beta', description: 'matches by DESCRIPTION' }),
                app({ uuid: 'a3', name: 'gamma', tags: [{ id: 1, name: 'matchable' }] }),
                app({ uuid: 'a4', name: 'delta' }),
            ];
            render(<Index {...baseProps({ applications: apps })} />);

            act(() => typeInto(document.getElementById('resource-index-search'), 'match'));

            expect(screen.getByText('beta')).toBeInTheDocument();
            expect(screen.getByText('gamma')).toBeInTheDocument();
            expect(screen.queryByText('zeta')).not.toBeInTheDocument();
            expect(screen.queryByText('delta')).not.toBeInTheDocument();
        });

        it('sorts filtered results alphabetically by name', () => {
            const apps = [app({ uuid: 'a1', name: 'zeta' }), app({ uuid: 'a2', name: 'alpha' }), app({ uuid: 'a3', name: 'mid' })];
            render(<Index {...baseProps({ applications: apps })} />);

            const names = screen.getAllByText(/^(zeta|alpha|mid)$/).map((el) => el.textContent);
            expect(names).toEqual(['alpha', 'mid', 'zeta']);
        });
    });

    describe('resource-type sections', () => {
        it('only shows a section heading when that type has results', () => {
            render(<Index {...baseProps({ applications: [app()], databases: [], services: [] })} />);
            expect(screen.getByText('Applications')).toBeInTheDocument();
            expect(screen.queryByText('Databases')).not.toBeInTheDocument();
            expect(screen.queryByText('Services')).not.toBeInTheDocument();
        });
    });

    describe('empty states', () => {
        it('shows "+ Add Resource" for a genuinely empty environment when canCreate', () => {
            render(<Index {...baseProps({ environment: { ...baseProps().environment, isEmpty: true }, canCreate: true })} />);
            expect(screen.getByRole('link', { name: '+ Add Resource' })).toBeInTheDocument();
            expect(screen.queryByText('No Resources Found')).not.toBeInTheDocument();
        });

        it('shows "No Resources Found" for a genuinely empty environment when not canCreate', () => {
            render(<Index {...baseProps({ environment: { ...baseProps().environment, isEmpty: true }, canCreate: false })} />);
            expect(screen.getByText('No Resources Found')).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: '+ Add Resource' })).not.toBeInTheDocument();
        });

        it('shows the search-specific empty message when a search matches nothing', () => {
            render(<Index {...baseProps({ applications: [app({ name: 'storefront-web' })] })} />);
            act(() => typeInto(document.getElementById('resource-index-search'), 'nonexistent'));
            expect(screen.getByText(/No resource found with the search term/)).toBeInTheDocument();
            expect(screen.getByText('nonexistent')).toBeInTheDocument();
        });

        it('shows the plain empty message (with admin-contact hint) when not empty but zero resources and not canCreate', () => {
            render(<Index {...baseProps({ applications: [], databases: [], services: [], canCreate: false })} />);
            expect(screen.getByText('No resources found in this environment.')).toBeInTheDocument();
            expect(screen.getByText('Contact your team administrator to add resources.')).toBeInTheDocument();
        });
    });

    describe('breadcrumb dropdowns', () => {
        it('lists every project and highlights the current one', () => {
            render(<Index {...baseProps()} />);
            const projectLink = screen.getByRole('link', { name: 'E-Commerce Platform' });
            act(() => projectLink.closest('li').dispatchEvent(new MouseEvent('mouseover', { bubbles: true })));

            const otherProjectLink = screen.getByRole('link', { name: 'Internal Tools' });
            expect(otherProjectLink).toBeInTheDocument();
            expect(within(projectLink.closest('li')).getAllByRole('link', { name: 'E-Commerce Platform' })[1].className).toContain('font-semibold');
        });

        it('shows a chevron only for a sibling environment with resources, and reveals them on hover', () => {
            render(<Index {...baseProps()} />);
            const envLink = screen.getByRole('link', { name: 'production' });
            act(() => envLink.closest('li').dispatchEvent(new MouseEvent('mouseover', { bubbles: true })));

            const stagingRow = screen.getByText('staging').closest('div');
            expect(stagingRow.querySelector('svg')).toBeInTheDocument();

            const prodRow = screen.getAllByText('production')[1]?.closest('div') ?? screen.getByText('production').closest('div');
            expect(prodRow.querySelector('svg')).not.toBeInTheDocument();

            act(() => stagingRow.dispatchEvent(new MouseEvent('mouseover', { bubbles: true })));
            expect(screen.getByRole('link', { name: 'staging-app' })).toBeInTheDocument();
        });
    });

    describe('actions', () => {
        it('only shows + New and Clone when canCreate is true', () => {
            const { unmount } = render(<Index {...baseProps({ canCreate: false })} />);
            expect(screen.queryByRole('link', { name: '+ New' })).not.toBeInTheDocument();
            expect(screen.queryByRole('link', { name: 'Clone' })).not.toBeInTheDocument();
            unmount();

            render(<Index {...baseProps({ canCreate: true })} />);
            expect(screen.getByRole('link', { name: '+ New' })).toHaveAttribute('href', '/project/proj/environment/env/new');
            expect(screen.getByRole('link', { name: 'Clone' })).toHaveAttribute('href', '/project/proj/environment/env/clone');
        });

        it('only shows Delete Environment when canDelete is true, and opens/closes the modal', () => {
            const { unmount } = render(<Index {...baseProps({ canDelete: false })} />);
            expect(screen.queryByRole('button', { name: 'Delete Environment' })).not.toBeInTheDocument();
            unmount();

            render(<Index {...baseProps({ canDelete: true })} />);
            expect(screen.queryByTestId('delete-environment-modal')).not.toBeInTheDocument();

            act(() => screen.getByRole('button', { name: 'Delete Environment' }).click());
            expect(screen.getByTestId('delete-environment-modal')).toBeInTheDocument();
            expect(deleteEnvironmentModalSpy).toHaveBeenCalledWith(expect.objectContaining({ deleteUrl: '/project/proj/environment/env' }));

            act(() => screen.getByRole('button', { name: 'Close Modal' }).click());
            expect(screen.queryByTestId('delete-environment-modal')).not.toBeInTheDocument();
        });
    });
});
