import ConfigurationChecker from '../../../Components/ConfigurationChecker';
import DatabaseGeneralTab from '../../../Components/DatabaseGeneralTab';
import DatabaseHealthcheckTab from '../../../Components/DatabaseHealthcheckTab';
import DatabaseHeading from '../../../Components/DatabaseHeading';
import DatabaseImportTab from '../../../Components/DatabaseImportTab';
import EnvironmentVariablesTab from '../../../Components/EnvironmentVariablesTab';
import StoragesTab from '../../../Components/StoragesTab';
import {
    DangerTab,
    ResourceLimitsTab,
    ResourceOperationsTab,
    ServersTab,
    TagsTab,
    WebhooksTab,
} from '../../../Components/ResourceTabs';

/**
 * React port of App\Livewire\Project\Database\Configuration — all 12 tabs (General, Tags,
 * Danger Zone, Webhooks, Resource Limits, Resource Operations, Servers, Environment
 * Variables, Persistent Storage, Healthcheck, Import Backup, Metrics) — see
 * ProjectDatabaseConfigurationController and ProjectMetricsController. The Livewire shell
 * (`Database\Configuration`) is fully retired as of Phase 62 — the last per-engine General
 * form converted, driven by DatabaseGeneralTab.jsx's engine-agnostic field list rather than
 * 8 separate components.
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
                    {tab === 'configuration' && (
                        <DatabaseGeneralTab generalForm={props.generalForm} generalUrls={props.generalUrls} resourceDetails={props.resourceDetails} />
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
                            resourceType="database"
                        />
                    )}
                    {tab === 'persistent-storage' && (
                        <StoragesTab
                            sections={props.sections}
                            isService={props.isService}
                            canAddMounts={props.canAddMounts}
                            canUpdate={props.canUpdate}
                            storageUrls={props.storageUrls}
                            sourceDirPlaceholder={props.sourceDirPlaceholder}
                        />
                    )}
                    {tab === 'healthcheck' && (
                        <DatabaseHealthcheckTab healthcheck={props.healthcheck} healthcheckUrls={props.healthcheckUrls} canUpdate={props.canUpdate} />
                    )}
                    {tab === 'import-backup' && <DatabaseImportTab importTab={props.importTab} flash={props.flash} />}
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
