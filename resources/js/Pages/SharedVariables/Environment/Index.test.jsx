import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Index from './Index';

// The "pick a project/environment" landing page for environment-scoped shared variables -
// downstream of the 2026-07-24 shared-variables smoke test (issue #25), which live-verified the
// SharedVariablesManager-driven Show pages this links into, but not this list page itself.
// Distinct from its Project/Server siblings (which are flat lists) in that it's two levels deep:
// projects, each with their own nested environments and their own independent empty state.

function project(overrides = {}) {
    return {
        name: 'E-Commerce Platform',
        description: 'Customer-facing storefront',
        environments: [
            {
                name: 'production',
                description: 'Live environment',
                href: '/shared-variables/environments/project/proj-1/environment/env-1',
            },
        ],
        ...overrides,
    };
}

describe('SharedVariables/Environment/Index', () => {
    it('shows "No project found." when there are no projects at all', () => {
        render(<Index projects={[]} />);
        expect(screen.getByText('No project found.')).toBeInTheDocument();
    });

    it('renders each project as a heading with its description', () => {
        render(<Index projects={[project()]} />);
        expect(screen.getByRole('heading', { name: 'Project: E-Commerce Platform' })).toBeInTheDocument();
        expect(screen.getByText('Customer-facing storefront')).toBeInTheDocument();
    });

    it('shows "No environments found." for a project with none, without affecting other projects', () => {
        render(<Index projects={[project({ name: 'Empty Project', environments: [] }), project({ name: 'E-Commerce Platform' })]} />);
        expect(screen.getByText('No environments found.')).toBeInTheDocument();
        expect(screen.getByText('production')).toBeInTheDocument();
        expect(document.querySelector('a[href="/shared-variables/environments/project/proj-1/environment/env-1"]')).toBeInTheDocument();
    });

    it('renders every environment under its own project as a link to its href', () => {
        render(
            <Index
                projects={[
                    project({
                        name: 'E-Commerce Platform',
                        environments: [
                            { name: 'production', description: 'Live environment', href: '/env/prod' },
                            { name: 'staging', description: 'Pre-release environment', href: '/env/staging' },
                        ],
                    }),
                ]}
            />,
        );
        expect(document.querySelector('a[href="/env/prod"]')).toBeInTheDocument();
        expect(document.querySelector('a[href="/env/staging"]')).toBeInTheDocument();
        expect(screen.getByText('staging')).toBeInTheDocument();
        expect(screen.getByText('Pre-release environment')).toBeInTheDocument();
    });

    it('does not show the no-projects empty state once at least one project is present', () => {
        render(<Index projects={[project()]} />);
        expect(screen.queryByText('No project found.')).not.toBeInTheDocument();
    });
});
