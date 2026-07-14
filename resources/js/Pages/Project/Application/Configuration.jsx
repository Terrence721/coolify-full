import ApplicationHeading from '../../../Components/ApplicationHeading';
import ScheduledTasksTab from '../../../Components/ScheduledTasksTab';
import { DangerTab, ResourceLimitsTab, ResourceOperationsTab, TagsTab } from '../../../Components/ResourceTabs';

/**
 * React port of the first cut into App\Livewire\Project\Application\Configuration (Phase 63,
 * heading added in Phase 64) — the shell plus 5 tabs it shares with the already-fully-converted
 * Database/Service routers (Tags, Danger Zone, Resource Limits, Resource Operations, Scheduled
 * Tasks), all now backed by shared concerns on their third consumer. See
 * ProjectApplicationConfigurationController. The remaining 11 tabs (General, Advanced, Swarm,
 * Environment Variables, Persistent Storage, Git Source, Servers, Webhooks, Preview
 * Deployments, Healthcheck, Rollback) stay on the Livewire shell for now — plain full-page
 * links here, matching the established split-by-route-name pattern.
 *
 * Sidebar links carry a `key` so the task detail page (/tasks/{task_uuid}) still highlights
 * Scheduled Tasks despite its different URL.
 */
export default function Configuration(props) {
    const { tab, tabs, application, heading, parameters, headingUrls, canUpdate } = props;

    return (
        <div>
            <h1>Configuration</h1>
            <ApplicationHeading application={application} heading={heading} parameters={parameters} urls={headingUrls} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <div className="sub-menu-wrapper">
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
                    {tab === 'scheduled-tasks' && (
                        <ScheduledTasksTab
                            task={props.task}
                            tasks={props.tasks}
                            executions={props.executions}
                            containerNames={props.containerNames}
                            isResourceRunning={props.isResourceRunning}
                            taskUrls={props.taskUrls}
                            canUpdate={canUpdate}
                        />
                    )}
                    {tab === 'tags' && <TagsTab tags={props.tags} availableTags={props.availableTags} tagsStoreUrl={props.tagsStoreUrl} canUpdate={canUpdate} />}
                    {tab === 'danger' && <DangerTab resourceName={props.resourceName} canDelete={props.canDelete} destroyUrl={props.destroyUrl} />}
                    {tab === 'resource-limits' && <ResourceLimitsTab limits={props.limits} limitsUpdateUrl={props.limitsUpdateUrl} canUpdate={canUpdate} />}
                    {tab === 'resource-operations' && (
                        <ResourceOperationsTab
                            servers={props.servers}
                            projects={props.projects}
                            currentProjectId={props.currentProjectId}
                            currentEnvironmentId={props.currentEnvironmentId}
                            operationUrls={props.operationUrls}
                            canUpdate={canUpdate}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}
