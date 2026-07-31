import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Configuration from './Configuration';

// The 12-tab database configuration router page, the natural companion to
// Project/Application/Configuration.test.jsx added earlier today - every individual tab
// component (DatabaseGeneralTab, EnvironmentVariablesTab, etc.) already has its own dedicated
// suite, so this one targets only the page's own routing/highlighting logic. Genuinely distinct
// from its Application sibling rather than a near-duplicate: the active-tab class here is
// URL-only (no `link.key === tab` fallback), so this suite covers that on its own terms.

function mockTab(name) {
    return {
        default: (props) => (
            <div data-testid={name} data-props={JSON.stringify(props)}>
                {name}
            </div>
        ),
    };
}

vi.mock('../../../Components/ConfigurationChecker', () => mockTab('ConfigurationChecker'));
vi.mock('../../../Components/DatabaseGeneralTab', () => mockTab('DatabaseGeneralTab'));
vi.mock('../../../Components/DatabaseHealthcheckTab', () => mockTab('DatabaseHealthcheckTab'));
vi.mock('../../../Components/DatabaseHeading', () => mockTab('DatabaseHeading'));
vi.mock('../../../Components/DatabaseImportTab', () => mockTab('DatabaseImportTab'));
vi.mock('../../../Components/EnvironmentVariablesTab', () => mockTab('EnvironmentVariablesTab'));
vi.mock('../../../Components/StoragesTab', () => mockTab('StoragesTab'));
vi.mock('../../../Components/ResourceTabs', () => ({
    DangerTab: mockTab('DangerTab').default,
    ResourceLimitsTab: mockTab('ResourceLimitsTab').default,
    ResourceOperationsTab: mockTab('ResourceOperationsTab').default,
    ServersTab: mockTab('ServersTab').default,
    TagsTab: mockTab('TagsTab').default,
    WebhooksTab: mockTab('WebhooksTab').default,
}));

const TABS = [
    { label: 'General', href: '/db/1/configuration' },
    { label: 'Environment Variables', href: '/db/1/environment-variables' },
    { label: 'Servers', href: '/db/1/servers' },
];

function baseProps(overrides = {}) {
    return {
        tab: 'configuration',
        tabs: TABS,
        heading: {},
        configurationChecker: {},
        urls: {},
        ...overrides,
    };
}

describe('Project/Database/Configuration', () => {
    afterEach(() => {
        window.history.pushState({}, '', '/');
    });

    it('renders the heading, ConfigurationChecker, and every sidebar tab link', () => {
        render(<Configuration {...baseProps()} />);

        expect(screen.getByTestId('DatabaseHeading')).toBeInTheDocument();
        expect(screen.getByTestId('ConfigurationChecker')).toBeInTheDocument();
        TABS.forEach((tab) => {
            const link = screen.getByRole('link', { name: tab.label });
            expect(link).toHaveAttribute('href', tab.href);
        });
    });

    it('renders only the tab body matching the current tab prop', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables' })} />);

        expect(screen.getByTestId('EnvironmentVariablesTab')).toBeInTheDocument();
        expect(screen.queryByTestId('DatabaseGeneralTab')).not.toBeInTheDocument();
        expect(screen.queryByTestId('ServersTab')).not.toBeInTheDocument();
    });

    it('threads resourceType="database" through to EnvironmentVariablesTab', () => {
        render(<Configuration {...baseProps({ tab: 'environment-variables', envs: [{ id: 1 }] })} />);

        const props = JSON.parse(screen.getByTestId('EnvironmentVariablesTab').dataset.props);
        expect(props.resourceType).toBe('database');
        expect(props.envs).toEqual([{ id: 1 }]);
    });

    it('threads generalForm/generalUrls/resourceDetails through to DatabaseGeneralTab', () => {
        render(
            <Configuration
                {...baseProps({
                    generalForm: { name: 'my-postgres' },
                    generalUrls: { update: '/db/1/update' },
                    resourceDetails: { type: 'postgresql' },
                })}
            />,
        );

        const props = JSON.parse(screen.getByTestId('DatabaseGeneralTab').dataset.props);
        expect(props.generalForm).toEqual({ name: 'my-postgres' });
        expect(props.generalUrls).toEqual({ update: '/db/1/update' });
        expect(props.resourceDetails).toEqual({ type: 'postgresql' });
    });

    it('has no sidebar link active when the current URL matches none of the tab hrefs', () => {
        render(<Configuration {...baseProps()} />);

        TABS.forEach((tab) => {
            expect(screen.getByRole('link', { name: tab.label })).not.toHaveClass('menu-item-active');
        });
    });

    it('highlights the sidebar link whose href matches the current URL', () => {
        // Unlike Project/Application/Configuration, this page has no `link.key === tab`
        // fallback - active state here is driven entirely by the current URL.
        const serversUrl = `${window.location.origin}/db/1/servers`;
        window.history.pushState({}, '', serversUrl);

        render(<Configuration {...baseProps({ tabs: [...TABS.filter((t) => t.label !== 'Servers'), { label: 'Servers', href: serversUrl }] })} />);

        expect(screen.getByRole('link', { name: 'Servers' })).toHaveClass('menu-item-active');
        expect(screen.getByRole('link', { name: 'General' })).not.toHaveClass('menu-item-active');
    });
});
