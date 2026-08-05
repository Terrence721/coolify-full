import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Resources from './Resources';

// Real logic: a client-side search filter joining type/name/project/environment into one string
// (filter(Boolean) so a missing field like environment doesn't produce "null" in the joined
// text), case-insensitive, empty search shows everything. The search input itself is only
// rendered when there's at least one resource - not shown alongside the empty state. Each row's
// name renders as a link only when row.url is present, and type is capitalized for display.

function baseDestination(overrides = {}) {
    return { uuid: 'dest-1', name: 'production-net', ...overrides };
}

function resourceRow(overrides = {}) {
    return {
        uuid: 'res-1',
        type: 'application',
        name: 'my-app',
        project: 'my-project',
        environment: 'production',
        url: '/project/my-project/production/application/res-1',
        ...overrides,
    };
}

describe('Destination/Resources', () => {
    it('shows the empty state when there are no resources', () => {
        render(<Resources destination={baseDestination()} resources={[]} showUrl="/destination/dest-1" />);
        expect(screen.getByText('No resources are using this destination.')).toBeInTheDocument();
        expect(screen.queryByPlaceholderText('Search resources...')).not.toBeInTheDocument();
    });

    it('renders every resource with the right columns and a capitalized type', () => {
        render(<Resources destination={baseDestination()} resources={[resourceRow()]} showUrl="/destination/dest-1" />);
        expect(screen.getByText('my-app')).toBeInTheDocument();
        expect(screen.getByText('my-project')).toBeInTheDocument();
        expect(screen.getByText('production')).toBeInTheDocument();
        expect(screen.getByText('Application')).toBeInTheDocument();
    });

    it('renders the name as a link when the row has a url', () => {
        render(<Resources destination={baseDestination()} resources={[resourceRow({ url: '/some/real/path' })]} showUrl="/destination/dest-1" />);
        expect(screen.getByRole('link', { name: 'my-app' })).toHaveAttribute('href', '/some/real/path');
    });

    it('renders the name as plain text, not a link, when the row has no url', () => {
        render(<Resources destination={baseDestination()} resources={[resourceRow({ url: null })]} showUrl="/destination/dest-1" />);
        expect(screen.queryByRole('link', { name: 'my-app' })).not.toBeInTheDocument();
        expect(screen.getByText('my-app')).toBeInTheDocument();
    });

    it('filters resources by a case-insensitive match across type/name/project/environment', () => {
        render(
            <Resources
                destination={baseDestination()}
                resources={[
                    resourceRow({ uuid: 'res-1', name: 'first-app', project: 'alpha' }),
                    resourceRow({ uuid: 'res-2', name: 'second-app', project: 'beta' }),
                ]}
                showUrl="/destination/dest-1"
            />,
        );

        const search = screen.getByPlaceholderText('Search resources...');
        fireEvent.change(search, { target: { value: 'ALPHA' } });

        expect(screen.getByText('first-app')).toBeInTheDocument();
        expect(screen.queryByText('second-app')).not.toBeInTheDocument();
    });

    it('does not break on a resource missing project/environment (filter(Boolean) guard)', () => {
        render(
            <Resources
                destination={baseDestination()}
                resources={[resourceRow({ project: null, environment: null, name: 'standalone-app' })]}
                showUrl="/destination/dest-1"
            />,
        );

        const search = screen.getByPlaceholderText('Search resources...');
        fireEvent.change(search, { target: { value: 'standalone' } });

        expect(screen.getByText('standalone-app')).toBeInTheDocument();
    });
});
