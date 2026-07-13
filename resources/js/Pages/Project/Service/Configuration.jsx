import EnvironmentVariablesTab from '../../../Components/EnvironmentVariablesTab';
import ServiceHeading from '../../../Components/ServiceHeading';
import { DangerTab, ResourceOperationsTab, TagsTab, WebhooksTab } from '../../../Components/ResourceTabs';

/**
 * React port of App\Livewire\Project\Service\Configuration's shell plus 4 of its 8 tabs
 * (Tags, Danger Zone, Webhooks, Resource Operations) — see
 * ProjectServiceConfigurationController. General (StackForm + resource cards),
 * Environment Variables, Persistent Storages, and Scheduled Tasks stay on the Livewire
 * shell; the sidebar links everything, unconverted tabs as plain full-page links.
 */
export default function Configuration(props) {
    const { tab, tabs, documentationUrl, service, parameters, urls } = props;

    return (
        <div>
            <ServiceHeading service={service} parameters={parameters} urls={urls} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <div className="sub-menu-wrapper">
                    <a className="sub-menu-item" target="_blank" rel="noreferrer" href={documentationUrl}>
                        <span className="menu-item-label">Documentation ↗</span>
                    </a>
                    {tabs.map((link) => (
                        <a
                            key={link.href}
                            className={`sub-menu-item${window.location.href.split('#')[0] === link.href ? ' menu-item-active' : ''}`}
                            href={link.href}
                        >
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
                            resourceType="service"
                        />
                    )}
                    {tab === 'tags' && <TagsTab tags={props.tags} availableTags={props.availableTags} tagsStoreUrl={props.tagsStoreUrl} canUpdate={props.canUpdate} />}
                    {tab === 'danger' && <DangerTab resourceName={props.resourceName} canDelete={props.canDelete} destroyUrl={props.destroyUrl} />}
                    {tab === 'webhooks' && <WebhooksTab deployWebhook={props.deployWebhook} />}
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
                </div>
            </div>
        </div>
    );
}
