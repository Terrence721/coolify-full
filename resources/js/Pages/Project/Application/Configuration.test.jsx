import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Configuration from './Configuration';

// This router page was part of the issue #28 sign-off smoke test: a real Playwright session
// against the real storefront-web application confirmed all 16 tabs return real 200s on direct
// navigation, plus click-based navigation and active-tab highlighting for two of them. This suite
// locks in the page's own routing/highlighting logic as automated coverage - every individual tab
// component (ApplicationGeneralTab, EnvironmentVariablesTab, etc.) already has its own dedicated
// suite, so those are mocked out here rather than re-asserted.

function mockTab(name) {
    return {
        default: (props) => (
            <div data-testid={name} data-props={JSON.stringify(props)}>
                {name}
            </div>
        ),
    };
}

vi.mock('../../../Components/ApplicationHeading', () => mockTab('ApplicationHeading'));
vi.mock('../../../Components/AdvancedTab', () => mockTab('AdvancedTab'));
vi.mock('../../../Components/ApplicationGeneralTab', () => mockTab('ApplicationGeneralTab'));
vi.mock('../../../Components/ApplicationHealthcheckTab', () => mockTab('ApplicationHealthcheckTab'));
vi.mock('../../../Components/ApplicationServersTab', () => mockTab('ApplicationServersTab'));
vi.mock('../../../Components/EnvironmentVariablesTab', () => mockTab('EnvironmentVariablesTab'));
vi.mock('../../../Components/GitSourceTab', () => mockTab('GitSourceTab'));
vi.mock('../../../Components/PreviewDeploymentsTab', () => mockTab('PreviewDeploymentsTab'));
vi.mock('../../../Components/RollbackTab', () => mockTab('RollbackTab'));
vi.mock('../../../Components/ScheduledTasksTab', () => mockTab('ScheduledTasksTab'));
vi.mock('../../../Components/StoragesTab', () => mockTab('StoragesTab'));
vi.mock('../../../Components/SwarmTab', () => mockTab('SwarmTab'));
vi.mock('../../../Components/ResourceTabs', () => ({
    DangerTab: mockTab('DangerTab').default,
    ResourceLimitsTab: mockTab('ResourceLimitsTab').default,
    ResourceOperationsTab: mockTab('ResourceOperationsTab').default,
    TagsTab: mockTab('TagsTab').default,
    WebhooksTab: mockTab('WebhooksTab').default,
}));

const TABS = [
    { key: 'configuration', label: 'General', href: '/app/1/configuration' },
    { key: 'environment-variables', label: 'Environment Variables', href: '/app/1/environment-variables' },
    { key: 'scheduled-tasks', label: 'Scheduled Tasks', href: '/app/1/scheduled-tasks' },
];

function baseProps(overrides = {}) {
    return {
        tab: 'configuration',
        tabs: TABS,
        application: { id: 1, name: 'storefront-web' },
        heading: {},
        parameters: {},
        headingUrls: {},
        canUpdate: true,
        ...overrides,
    };
}

describe('Project/Application/Configuration', () => {
    afterEach(() => {
        window.history.pushState({}, '', '/');
    });

    it('renders the heading and every sidebar tab link', () => {
        render(<Configuration {...baseProps()} />);

        expect(screen.getByTestId('ApplicationHeading')).toBeInTheDocument();
        TABS.forEach((tab) => {
            const link = screen.getByRole('link', { name: tab.label });
            expect(link).toHaveAttribute('href', tab.href);
        });
    });

    it('renders only the tab body matching the current tab prop', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables' })} />);

        expect(screen.getByTestId('EnvironmentVariablesTab')).toBeInTheDocument();
        expect(screen.queryByTestId('ApplicationGeneralTab')).not.toBeInTheDocument();
        expect(screen.queryByTestId('ScheduledTasksTab')).not.toBeInTheDocument();
    });

    it('threads resourceType="application" through to EnvironmentVariablesTab', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables', envs: [{ id: 1 }] })} />);

        const props = JSON.parse(screen.getByTestId('EnvironmentVariablesTab').dataset.props);
        expect(props.resourceType).toBe('application');
        expect(props.envs).toEqual([{ id: 1 }]);
    });

    it('threads the scheduled-tasks-specific props through to ScheduledTasksTab', () => {
        render(
            <Configuration
                {...baseProps({
                    tab: 'scheduled-tasks',
                    task: { uuid: 'task-1' },
                    tasks: [{ uuid: 'task-1' }],
                    executions: [],
                    containerNames: ['app-1'],
                    isResourceRunning: true,
                })}
            />,
        );

        const props = JSON.parse(screen.getByTestId('ScheduledTasksTab').dataset.props);
        expect(props.task).toEqual({ uuid: 'task-1' });
        expect(props.containerNames).toEqual(['app-1']);
        expect(props.isResourceRunning).toBe(true);
    });

    it('highlights the sidebar link whose key matches the current tab', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables' })} />);

        expect(screen.getByRole('link', { name: 'Environment Variables' })).toHaveClass('menu-item-active');
        expect(screen.getByRole('link', { name: 'General' })).not.toHaveClass('menu-item-active');
    });

    it('also highlights a sidebar link whose href matches the current URL, even when its key does not match the active tab', () => {
        // Matches the docblock's documented case: the task detail page (/tasks/{uuid}) has its
        // own distinct tab value, but Scheduled Tasks should still show as active there. The
        // component compares against window.location.href, a full absolute URL, so the tab's
        // href must be absolute too for this comparison to ever match.
        const scheduledTasksUrl = `${window.location.origin}/app/1/scheduled-tasks`;
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

    it('does not highlight any sidebar link when neither the tab key nor the URL matches', () => {
        render(<Configuration {...baseProps({ tab: 'configuration' })} />);

        expect(screen.getByRole('link', { name: 'Scheduled Tasks' })).not.toHaveClass('menu-item-active');
        expect(screen.getByRole('link', { name: 'Environment Variables' })).not.toHaveClass('menu-item-active');
    });
});
