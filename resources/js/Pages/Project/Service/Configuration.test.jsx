import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Configuration from './Configuration';

// The 8-tab service configuration router page, completing the trio alongside
// Project/Application/Configuration.test.jsx and Project/Database/Configuration.test.jsx added
// earlier today. Every individual tab component already has its own dedicated suite, so this one
// targets only the page's own logic. Distinct from both siblings: it's the only one of the three
// with an external "Documentation" link in the sidebar, and its active-highlight logic combines
// both the key-based (Application's) and URL-only (Database's) approaches.

function mockTab(name) {
    return {
        default: (props) => (
            <div data-testid={name} data-props={JSON.stringify(props)}>
                {name}
            </div>
        ),
    };
}

vi.mock('../../../Components/EnvironmentVariablesTab', () => mockTab('EnvironmentVariablesTab'));
vi.mock('../../../Components/ScheduledTasksTab', () => mockTab('ScheduledTasksTab'));
vi.mock('../../../Components/ServiceStackTab', () => mockTab('ServiceStackTab'));
vi.mock('../../../Components/StoragesTab', () => mockTab('StoragesTab'));
vi.mock('../../../Components/ServiceHeading', () => mockTab('ServiceHeading'));
vi.mock('../../../Components/ResourceTabs', () => ({
    DangerTab: mockTab('DangerTab').default,
    ResourceOperationsTab: mockTab('ResourceOperationsTab').default,
    TagsTab: mockTab('TagsTab').default,
    WebhooksTab: mockTab('WebhooksTab').default,
}));

const TABS = [
    { key: 'configuration', label: 'General', href: '/service/1/configuration' },
    { key: 'environment-variables', label: 'Environment Variables', href: '/service/1/environment-variables' },
    { key: 'scheduled-tasks', label: 'Scheduled Tasks', href: '/service/1/scheduled-tasks' },
];

function baseProps(overrides = {}) {
    return {
        tab: 'configuration',
        tabs: TABS,
        documentationUrl: 'https://coolify.io/docs/services/example',
        service: { id: 1, name: 'gitea' },
        parameters: {},
        urls: {},
        canUpdate: true,
        ...overrides,
    };
}

describe('Project/Service/Configuration', () => {
    afterEach(() => {
        window.history.pushState({}, '', '/');
    });

    it('renders the heading, documentation link, and every sidebar tab link', () => {
        render(<Configuration {...baseProps()} />);

        expect(screen.getByTestId('ServiceHeading')).toBeInTheDocument();
        const docLink = screen.getByRole('link', { name: 'Documentation ↗' });
        expect(docLink).toHaveAttribute('href', 'https://coolify.io/docs/services/example');
        expect(docLink).toHaveAttribute('target', '_blank');

        TABS.forEach((tab) => {
            const link = screen.getByRole('link', { name: tab.label });
            expect(link).toHaveAttribute('href', tab.href);
        });
    });

    it('renders only the tab body matching the current tab prop', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables' })} />);

        expect(screen.getByTestId('EnvironmentVariablesTab')).toBeInTheDocument();
        expect(screen.queryByTestId('ServiceStackTab')).not.toBeInTheDocument();
        expect(screen.queryByTestId('ScheduledTasksTab')).not.toBeInTheDocument();
    });

    it('threads resourceType="service" through to EnvironmentVariablesTab', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables', envs: [{ id: 1 }] })} />);

        const props = JSON.parse(screen.getByTestId('EnvironmentVariablesTab').dataset.props);
        expect(props.resourceType).toBe('service');
        expect(props.envs).toEqual([{ id: 1 }]);
    });

    it('threads stackForm/resources/resourceDetails through to ServiceStackTab', () => {
        render(
            <Configuration
                {...baseProps({
                    stackForm: { name: 'gitea' },
                    resources: [{ id: 1, name: 'gitea-app' }],
                    resourceDetails: { type: 'gitea' },
                })}
            />,
        );

        const props = JSON.parse(screen.getByTestId('ServiceStackTab').dataset.props);
        expect(props.stackForm).toEqual({ name: 'gitea' });
        expect(props.resources).toEqual([{ id: 1, name: 'gitea-app' }]);
        expect(props.resourceDetails).toEqual({ type: 'gitea' });
    });

    it('highlights the sidebar link whose key matches the current tab', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables' })} />);

        expect(screen.getByRole('link', { name: 'Environment Variables' })).toHaveClass('menu-item-active');
        expect(screen.getByRole('link', { name: 'General' })).not.toHaveClass('menu-item-active');
    });

    it('also highlights a sidebar link whose href matches the current URL, even when its key does not match the active tab', () => {
        // Matches the docblock's documented case: the task detail page (/tasks/{uuid}) has its
        // own distinct tab value, but Scheduled Tasks should still show as active there.
        const scheduledTasksUrl = `${window.location.origin}/service/1/scheduled-tasks`;
        window.history.pushState({}, '', scheduledTasksUrl);

        render(
            <Configuration
                {...baseProps({
                    tab: 'task-detail',
                    tabs: [
                        ...TABS.filter((t) => t.key !== 'scheduled-tasks'),
                        { key: 'scheduled-tasks', label: 'Scheduled Tasks', href: scheduledTasksUrl },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'Scheduled Tasks' })).toHaveClass('menu-item-active');
    });

    it('does not treat the documentation link as one of the tab links', () => {
        render(<Configuration {...baseProps()} />);

        const docLink = screen.getByRole('link', { name: 'Documentation ↗' });
        expect(docLink).not.toHaveClass('menu-item-active');
        expect(docLink.className).toBe('sub-menu-item');
    });
});
