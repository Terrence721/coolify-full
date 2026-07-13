import ConfigurationChecker from '../../../Components/ConfigurationChecker';
import DatabaseHeading from '../../../Components/DatabaseHeading';
import EnvironmentVariablesTab from '../../../Components/EnvironmentVariablesTab';
import {
    DangerTab,
    ResourceLimitsTab,
    ResourceOperationsTab,
    ServersTab,
    TagsTab,
    WebhooksTab,
} from '../../../Components/ResourceTabs';

/**
 * React port of App\Livewire\Project\Database\Configuration's shell plus 6 of its 12 tabs
 * (Tags, Danger Zone, Webhooks, Resource Limits, Resource Operations, Servers) — see
 * ProjectDatabaseConfigurationController. The sidebar links all 12 tabs; the unconverted
 * ones are plain full-page links to the still-Livewire routes, exactly as the original's
 * per-tab full navigations behaved.
 */
export default function Configuration(props) {
    const { tab, tabs, heading, configurationChecker, urls } = props;

    return (
        <div>
            <h1>Configuration</h1>
            <ConfigurationChecker configurationChecker={configurationChecker} />
            <DatabaseHeading heading={heading} urls={urls} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <div className="sub-menu-wrapper">
                    {tabs.map((link) => (
                        <a key={link.href} className={`sub-menu-item${window.location.href.split('#')[0] === link.href ? ' menu-item-active' : ''}`} href={link.href}>
                            <span className="menu-item-label">{link.label}</span>
                        </a>
                    ))}
                </div>
                <div className="w-full">
                    {tab === 'environment-variables' && (
                        <EnvironmentVariablesTab
                            envs={props.envs}
                            hardcodedEnvs={props.hardcodedEnvs}
                            devEnvs={props.devEnvs}
                            canManageEnvironment={props.canManageEnvironment}
                            problematicVariables={props.problematicVariables}
                            availableSharedVariables={props.availableSharedVariables}
                            envUrls={props.envUrls}
                            resourceType="database"
                        />
                    )}
                    {tab === 'tags' && <TagsTab tags={props.tags} availableTags={props.availableTags} tagsStoreUrl={props.tagsStoreUrl} canUpdate={props.canUpdate} />}
                    {tab === 'danger' && <DangerTab resourceName={props.resourceName} canDelete={props.canDelete} destroyUrl={props.destroyUrl} />}
                    {tab === 'webhooks' && <WebhooksTab deployWebhook={props.deployWebhook} />}
                    {tab === 'resource-limits' && <ResourceLimitsTab limits={props.limits} limitsUpdateUrl={props.limitsUpdateUrl} canUpdate={props.canUpdate} />}
                    {tab === 'resource-operations' && (
                        <ResourceOperationsTab
                            servers={props.servers}
                            projects={props.projects}
                            currentProjectId={props.currentProjectId}
                            currentEnvironmentId={props.currentEnvironmentId}
                            operationUrls={props.operationUrls}
                            canUpdate={props.canUpdate}
                        />
                    )}
                    {tab === 'servers' && <ServersTab primaryServer={props.primaryServer} />}
                </div>
            </div>
        </div>
    );
}
