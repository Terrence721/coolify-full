import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Logs from './Logs';

// The application/database/service logs page - a 3-way resource-type branch (each type renders
// a different heading component, and only application/database get the top-level "Logs" <h1>,
// service doesn't) layered with its own nested empty-state logic: the "not running" message only
// applies to database/service (never application), then empty containerGroups vs. a group with
// zero containers are two different messages. Real risk: crossing these branches - e.g. showing
// "not running" for an application, or the wrong empty-state message - would be easy to get wrong
// and easy to miss visually since both render as plain text. Previously entirely untested.

vi.mock('../../../Components/ApplicationHeading', () => ({
    default: ({ application }) => <div data-testid="application-heading">{application?.name}</div>,
}));
vi.mock('../../../Components/DatabaseHeading', () => ({
    default: ({ heading }) => <div data-testid="database-heading">{heading?.name}</div>,
}));
vi.mock('../../../Components/ServiceHeading', () => ({
    default: ({ service }) => <div data-testid="service-heading">{service?.name}</div>,
}));
vi.mock('../../../Components/ConfigurationChecker', () => ({ default: () => <div data-testid="configuration-checker" /> }));
vi.mock('../../../Components/ContainerLogs', () => ({
    default: ({ queryPrefix }) => <div data-testid="container-logs">{queryPrefix}</div>,
}));

function baseProps(overrides = {}) {
    return {
        type: 'application',
        application: { name: 'my-app' },
        heading: {},
        headingUrls: {},
        isExited: false,
        service: null,
        databaseHeading: null,
        configurationChecker: {},
        containerGroups: [],
        noServerMessage: 'No server assigned.',
        parameters: {},
        ...overrides,
    };
}

function group(overrides = {}) {
    return {
        serverName: 'server-1',
        containers: [{ key: 'c1', displayName: 'my-app-c1', logLines: [], numberOfLines: 100, showTimestamps: false, urls: {}, queryPrefix: 'c1' }],
        ...overrides,
    };
}

describe('Project/Shared/Logs', () => {
    it('renders the ApplicationHeading and top-level Logs heading for type application', () => {
        render(<Logs {...baseProps({ type: 'application' })} />);

        expect(screen.getByRole('heading', { name: 'Logs', level: 1 })).toBeInTheDocument();
        expect(screen.getByTestId('application-heading')).toBeInTheDocument();
        expect(screen.queryByTestId('database-heading')).not.toBeInTheDocument();
        expect(screen.queryByTestId('service-heading')).not.toBeInTheDocument();
    });

    it('renders the DatabaseHeading and top-level Logs heading for type database', () => {
        render(<Logs {...baseProps({ type: 'database', databaseHeading: { name: 'my-db' } })} />);

        expect(screen.getByRole('heading', { name: 'Logs', level: 1 })).toBeInTheDocument();
        expect(screen.getByTestId('database-heading')).toBeInTheDocument();
        expect(screen.queryByTestId('application-heading')).not.toBeInTheDocument();
    });

    it('renders the ServiceHeading without a top-level Logs heading for type service', () => {
        render(<Logs {...baseProps({ type: 'service', service: { name: 'my-service' } })} />);

        expect(screen.queryByRole('heading', { name: 'Logs', level: 1 })).not.toBeInTheDocument();
        expect(screen.getByTestId('service-heading')).toBeInTheDocument();
    });

    it('shows the "not running" message for an exited database', () => {
        render(<Logs {...baseProps({ type: 'database', isExited: true })} />);

        expect(screen.getByText('The resource is not running.')).toBeInTheDocument();
    });

    it('shows the "not running" message for an exited service', () => {
        render(<Logs {...baseProps({ type: 'service', isExited: true })} />);

        expect(screen.getByText('The resource is not running.')).toBeInTheDocument();
    });

    it('never shows the "not running" message for an application, even when isExited is true', () => {
        render(<Logs {...baseProps({ type: 'application', isExited: true, containerGroups: [group()] })} />);

        expect(screen.queryByText('The resource is not running.')).not.toBeInTheDocument();
        expect(screen.getByTestId('container-logs')).toBeInTheDocument();
    });

    it('shows the noServerMessage when there are no container groups', () => {
        render(<Logs {...baseProps({ containerGroups: [], noServerMessage: 'No server assigned to this resource.' })} />);

        expect(screen.getByText('No server assigned to this resource.')).toBeInTheDocument();
    });

    it('shows a per-server empty message when a group has zero containers', () => {
        render(<Logs {...baseProps({ containerGroups: [group({ serverName: 'prod-1', containers: [] })] })} />);

        expect(screen.getByText('Server: prod-1')).toBeInTheDocument();
        expect(screen.getByText('No containers are running on server: prod-1')).toBeInTheDocument();
    });

    it('renders ContainerLogs for each container across multiple server groups', () => {
        render(
            <Logs
                {...baseProps({
                    containerGroups: [
                        group({ serverName: 'server-1', containers: [{ key: 'a', displayName: 'app-a' }] }),
                        group({ serverName: 'server-2', containers: [{ key: 'b', displayName: 'app-b' }] }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('Server: server-1')).toBeInTheDocument();
        expect(screen.getByText('Server: server-2')).toBeInTheDocument();
        expect(screen.getAllByTestId('container-logs')).toHaveLength(2);
    });

    it('shows the pull-request badge only when a container has one', () => {
        render(
            <Logs
                {...baseProps({
                    containerGroups: [
                        group({
                            containers: [
                                { key: 'a', displayName: 'app-a', pullRequest: 'PR #42' },
                                { key: 'b', displayName: 'app-b' },
                            ],
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('(PR #42)')).toBeInTheDocument();
    });
});
