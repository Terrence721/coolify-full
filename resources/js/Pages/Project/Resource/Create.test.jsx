import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Create from './Create';

// Regression coverage for a real bug found in Phase 76: this page's environment switcher used
// to call a `route()` global that doesn't exist anywhere in this codebase (no Ziggy installed) -
// a live ReferenceError on every environment switch. Fixed by building the URL manually. This
// suite locks that fix in place, plus the search/category filter logic (the other real,
// stateful behavior in this file, as opposed to the other steps, which are static views over
// server-provided data).

const getSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { get: (url) => getSpy(url) },
}));

// React 19 patches the native <input>/<select> value setter to track controlled-component
// state - directly assigning `.value` then dispatching a bare event doesn't notify it. Using
// the real native setter first (bypassing React's patched one) is the standard workaround
// absent @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function baseProps(overrides = {}) {
    return {
        step: 'type',
        project: { uuid: 'proj-uuid' },
        environment: { uuid: 'env-uuid-1', name: 'production' },
        environments: [
            { uuid: 'env-uuid-1', name: 'production' },
            { uuid: 'env-uuid-2', name: 'staging' },
        ],
        services: [
            { id: 1, name: 'Grafana', description: 'Dashboards and monitoring', category: 'monitoring' },
            { id: 2, name: 'Umami', description: 'Privacy-friendly analytics', category: 'analytics' },
        ],
        categories: ['monitoring', 'analytics'],
        gitBasedApplications: [{ id: 'public', name: 'Public Repository', description: 'A public git repo' }],
        dockerBasedApplications: [{ id: 'dockerfile', name: 'Dockerfile', description: 'Build from a Dockerfile' }],
        databases: [{ id: 'postgresql', name: 'PostgreSQL' }],
        ...overrides,
    };
}

describe('Project/Resource/Create (TypeStep)', () => {
    beforeEach(() => {
        getSpy.mockClear();
        window.history.pushState({}, '', '/project/proj-uuid/environment/env-uuid-1/new');
    });

    it('switches environment by building the URL manually, not via a route() global', () => {
        render(<Create {...baseProps()} />);

        const select = document.getElementById('resource-create-environment');
        act(() => {
            select.value = 'env-uuid-2';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });

        expect(getSpy).toHaveBeenCalledWith('/project/proj-uuid/environment/env-uuid-2/new');
    });

    it('renders both services by default, with no search or category filter applied', () => {
        render(<Create {...baseProps()} />);

        expect(screen.getByText('Grafana')).toBeInTheDocument();
        expect(screen.getByText('Umami')).toBeInTheDocument();
    });

    it('filters services by name as the user types in the search box', () => {
        render(<Create {...baseProps()} />);

        act(() => typeInto(screen.getByPlaceholderText('Search services...'), 'graf'));

        expect(screen.getByText('Grafana')).toBeInTheDocument();
        expect(screen.queryByText('Umami')).not.toBeInTheDocument();
    });

    it('filters services by description text too, not just name', () => {
        render(<Create {...baseProps()} />);

        act(() => typeInto(screen.getByPlaceholderText('Search services...'), 'analytics'));

        expect(screen.getByText('Umami')).toBeInTheDocument();
        expect(screen.queryByText('Grafana')).not.toBeInTheDocument();
    });

    it('narrows services by the selected category', () => {
        render(<Create {...baseProps()} />);

        const select = screen.getByDisplayValue('All Categories');
        act(() => {
            select.value = 'analytics';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });

        expect(screen.getByText('Umami')).toBeInTheDocument();
        expect(screen.queryByText('Grafana')).not.toBeInTheDocument();
    });

    it('hides the category dropdown entirely when there are no categories', () => {
        render(<Create {...baseProps({ categories: [] })} />);

        expect(screen.queryByDisplayValue('All Categories')).not.toBeInTheDocument();
    });

    it('navigates with the resource type and clears prior step params when a tile is clicked', () => {
        render(<Create {...baseProps()} />);

        act(() => screen.getByText('PostgreSQL').click());

        expect(getSpy).toHaveBeenCalledWith('/project/proj-uuid/environment/env-uuid-1/new?type=postgresql');
    });
});
