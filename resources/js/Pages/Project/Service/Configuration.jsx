import EnvironmentVariablesTab from '../../../Components/EnvironmentVariablesTab';
import ScheduledTasksTab from '../../../Components/ScheduledTasksTab';
import ServiceStackTab from '../../../Components/ServiceStackTab';
import StoragesTab from '../../../Components/StoragesTab';
import ServiceHeading from '../../../Components/ServiceHeading';
import { DangerTab, ResourceOperationsTab, TagsTab, WebhooksTab } from '../../../Components/ResourceTabs';

/**
 * React port of App\Livewire\Project\Service\Configuration — all 8 tabs (General/Service
 * Stack, Tags, Danger Zone, Webhooks, Resource Operations, Environment Variables,
 * Persistent Storages, Scheduled Tasks) — see ProjectServiceConfigurationController.
 * The Livewire shell is fully retired as of Phase 59. Sidebar links carry a `key` so the
 * task detail page (/tasks/{task_uuid}) still highlights Scheduled Tasks despite its
 * different URL (the Livewire sidebar's startsWith() behavior).
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
                            className={`sub-menu-item${link.key === tab || window.location.href.split('#')[0] === link.href ? ' menu-item-active' : ''}`}
                            href={link.href}
                        >
                            <span className="menu-item-label">{link.label}</span>
                        </a>
                    ))}
                </div>
                <div className="w-full">
                    {tab === 'configuration' && (
                        <ServiceStackTab
                            stackForm={props.stackForm}
                            resources={props.resources}
                            resourceDetails={props.resourceDetails}
                            generalUrls={props.generalUrls}
                            canUpdate={props.canUpdate}
                        />
                    )}
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
                    {tab === 'storages' && (
                        <StoragesTab
                            sections={props.sections}
                            isService={props.isService}
                            canAddMounts={props.canAddMounts}
                            canUpdate={props.canUpdate}
                            storageUrls={props.storageUrls}
                            sourceDirPlaceholder={props.sourceDirPlaceholder}
                        />
                    )}
                    {tab === 'scheduled-tasks' && (
                        <ScheduledTasksTab
                            task={props.task}
                            tasks={props.tasks}
                            executions={props.executions}
                            containerNames={props.containerNames}
                            isResourceRunning={props.isResourceRunning}
                            taskUrls={props.taskUrls}
                            canUpdate={props.canUpdate}
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
