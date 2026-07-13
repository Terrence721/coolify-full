import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfigurationChecker from '../../../Components/ConfigurationChecker';
import DatabaseHeading from '../../../Components/DatabaseHeading';
import PasswordConfirmModal from '../../../Components/PasswordConfirmModal';

/**
 * React port of App\Livewire\Project\Database\Configuration's shell plus 6 of its 12 tabs
 * (Tags, Danger Zone, Webhooks, Resource Limits, Resource Operations, Servers) — see
 * ProjectDatabaseConfigurationController. The sidebar links all 12 tabs; the unconverted
 * ones are plain full-page links to the still-Livewire routes, exactly as the original's
 * per-tab full navigations behaved.
 */
function TagsTab({ tags, availableTags, tagsStoreUrl, canUpdate }) {
    const { data, setData, post, processing, reset } = useForm({ tags: '' });

    function submit(e) {
        e.preventDefault();
        post(tagsStoreUrl, { preserveScroll: true, onSuccess: () => reset() });
    }

    function quickAdd(tagId) {
        router.post(tagsStoreUrl, { tag_id: tagId }, { preserveScroll: true });
    }

    function deleteTag(tag) {
        router.delete(tag.destroyUrl, { preserveScroll: true });
    }

    return (
        <div>
            <h2>Tags</h2>
            {canUpdate ? (
                <form onSubmit={submit} className="flex items-end gap-2">
                    <label className="flex flex-col gap-1 w-64">
                        Create new or assign existing tags
                        <input
                            placeholder="example: prod app1 user"
                            value={data.tags}
                            onChange={(e) => setData('tags', e.target.value)}
                            title="You can add more at once with a space separated list. If the tag does not exist, it will be created."
                        />
                    </label>
                    <button type="submit" disabled={processing}>
                        Add
                    </button>
                </form>
            ) : (
                <div className="mt-4 dark:text-warning">You don't have permission to manage tags.</div>
            )}
            {tags.length > 0 && (
                <>
                    <h3 className="pt-4">Assigned Tags</h3>
                    <div className="flex flex-wrap gap-2 pt-4">
                        {tags.map((tag) => (
                            <div key={tag.id} className="button">
                                {tag.name}
                                {canUpdate && (
                                    <span className="inline-block w-4 cursor-pointer hover:text-red-500" onClick={() => deleteTag(tag)}>
                                        ✕
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                </>
            )}
            {canUpdate && availableTags.length > 0 && (
                <>
                    <h3 className="pt-4">Existing Tags</h3>
                    <div>Click to add quickly</div>
                    <div className="flex flex-wrap gap-2 pt-4">
                        {availableTags.map((tag) => (
                            <button key={tag.id} type="button" onClick={() => quickAdd(tag.id)}>
                                {tag.name}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

function DangerTab({ resourceName, canDelete, destroyUrl }) {
    const [modalOpen, setModalOpen] = useState(false);

    return (
        <div>
            <h2>Danger Zone</h2>
            <div>Woah. I hope you know what are you doing.</div>
            <h4 className="pt-4">Delete Resource</h4>
            <div className="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming back!</div>
            {canDelete ? (
                <button type="button" className="button-error" onClick={() => setModalOpen(true)}>
                    Delete
                </button>
            ) : (
                <div className="dark:text-warning">You don't have permission to delete this resource.</div>
            )}
            {modalOpen && (
                <PasswordConfirmModal
                    title="Confirm Resource Deletion?"
                    action={{ method: 'delete', url: destroyUrl }}
                    actions={['Permanently delete all containers of this resource.']}
                    checkboxes={[
                        { id: 'delete_volumes', label: 'All associated volumes will be permanently deleted.', default: true },
                        { id: 'delete_connected_networks', label: 'All connected networks will be deleted (predefined networks are not).', default: true },
                        { id: 'delete_configurations', label: 'All configuration files will be permanently deleted from the server.', default: true },
                        { id: 'docker_cleanup', label: 'Run Docker Cleanup (remove unused images and builder cache).', default: true },
                    ]}
                    confirmationText={resourceName}
                    confirmationLabel="Please confirm the execution of the actions by entering the Resource Name below"
                    onClose={() => setModalOpen(false)}
                />
            )}
        </div>
    );
}

function WebhooksTab({ deployWebhook }) {
    return (
        <div className="flex flex-col gap-2">
            <h2>Webhooks</h2>
            <label className="flex flex-col gap-1">
                Deploy Webhook (auth required)
                <input readOnly value={deployWebhook} />
            </label>
        </div>
    );
}

function ResourceLimitsTab({ limits, limitsUpdateUrl, canUpdate }) {
    const { data, setData, patch, processing, errors } = useForm({
        limitsCpus: limits.limitsCpus ?? '',
        limitsCpuset: limits.limitsCpuset ?? '',
        limitsCpuShares: limits.limitsCpuShares ?? '',
        limitsMemory: limits.limitsMemory ?? '0',
        limitsMemorySwap: limits.limitsMemorySwap ?? '0',
        limitsMemorySwappiness: limits.limitsMemorySwappiness ?? 60,
        limitsMemoryReservation: limits.limitsMemoryReservation ?? '0',
    });

    function submit(e) {
        e.preventDefault();
        patch(limitsUpdateUrl, { preserveScroll: true });
    }

    const field = (key, label, extra = {}) => (
        <label className="flex flex-col flex-1 gap-1">
            {label}
            <input
                disabled={!canUpdate}
                value={data[key] ?? ''}
                onChange={(e) => setData(key, e.target.value)}
                {...extra}
            />
            {errors[key] && <span className="text-error">{errors[key]}</span>}
        </label>
    );

    return (
        <form onSubmit={submit} className="flex flex-col">
            <div className="flex items-center gap-2">
                <h2>Resource Limits</h2>
                {canUpdate && (
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                )}
            </div>
            <div>Limit your container resources by CPU & memory.</div>
            <h3 className="pt-4">Limit CPUs</h3>
            <div className="flex gap-2">
                {field('limitsCpus', 'Number of CPUs', { placeholder: '1.5' })}
                {field('limitsCpuset', 'CPU sets to use', { placeholder: '0-2' })}
                {field('limitsCpuShares', 'CPU Weight', { placeholder: '1024' })}
            </div>
            <h3 className="pt-4">Limit Memory</h3>
            <div className="flex gap-2">
                {field('limitsMemoryReservation', 'Soft Memory Limit')}
                {field('limitsMemorySwappiness', 'Swappiness', { type: 'number', min: 0, max: 100 })}
                {field('limitsMemory', 'Maximum Memory Limit')}
                {field('limitsMemorySwap', 'Maximum Swap Limit')}
            </div>
        </form>
    );
}

function ResourceOperationsTab({ servers, projects, currentProjectId, currentEnvironmentId, operationUrls, canUpdate }) {
    const [cloneServer, setCloneServer] = useState('');
    const [cloneDestination, setCloneDestination] = useState('');
    const [moveProject, setMoveProject] = useState('');
    const [moveEnvironment, setMoveEnvironment] = useState('');

    const availableDestinations = servers.find((server) => String(server.id) === String(cloneServer))?.destinations ?? [];
    const selectedProject = projects.find((project) => String(project.id) === String(moveProject));
    const availableEnvironments = (selectedProject?.environments ?? []).filter((environment) =>
        selectedProject?.id === currentProjectId ? environment.id !== currentEnvironmentId : true,
    );

    function clone() {
        router.post(operationUrls.clone, { destination_id: cloneDestination, clone_volume_data: false }, { preserveScroll: true });
    }

    function move() {
        router.post(operationUrls.move, { environment_id: moveEnvironment }, { preserveScroll: true });
    }

    if (!canUpdate) {
        return (
            <div>
                <h2>Resource Operations</h2>
                <div className="pt-4 dark:text-warning">You don't have permission to clone or move resources.</div>
            </div>
        );
    }

    return (
        <div id="resource-operations">
            <h2>Resource Operations</h2>
            <div>You can easily make different kind of operations on this resource.</div>

            <h3 className="pt-4">Clone Resource</h3>
            <div className="pb-2">Duplicate this resource to another server or network destination.</div>
            <div className="pb-4 text-sm dark:text-neutral-400">
                Cloning only duplicates resource configuration (such as environment variables, build settings etc..). It does not include
                any resource data, such as databases or stored files.
            </div>
            <div className="flex flex-col gap-4 pb-8 lg:flex-row">
                <label className="flex flex-col flex-1 gap-1">
                    Select Server
                    <select
                        value={cloneServer}
                        onChange={(e) => {
                            setCloneServer(e.target.value);
                            setCloneDestination('');
                        }}
                    >
                        <option value="">Choose a server...</option>
                        {servers.map((server) => (
                            <option key={server.id} value={server.id}>
                                {server.name} ({server.ip})
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col flex-1 gap-1">
                    Select Network Destination
                    <select value={cloneDestination} disabled={!cloneServer} onChange={(e) => setCloneDestination(e.target.value)}>
                        <option value="">Choose a destination...</option>
                        {availableDestinations.map((destination) => (
                            <option key={destination.id} value={destination.id}>
                                {destination.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {cloneDestination && (
                <button type="button" className="mb-8" onClick={clone}>
                    Clone Resource
                </button>
            )}

            <h3 className="pt-4">Move Resource</h3>
            <div className="pb-4">Transfer this resource between projects and environments.</div>
            <div className="flex flex-col gap-4 lg:flex-row">
                <label className="flex flex-col flex-1 gap-1">
                    Select Target Project
                    <select
                        value={moveProject}
                        onChange={(e) => {
                            setMoveProject(e.target.value);
                            setMoveEnvironment('');
                        }}
                    >
                        <option value="">Choose a project...</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                                {project.id === currentProjectId ? ' (current)' : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col flex-1 gap-1">
                    Select Target Environment (current is excluded)
                    <select value={moveEnvironment} disabled={!moveProject || availableEnvironments.length === 0} onChange={(e) => setMoveEnvironment(e.target.value)}>
                        <option value="">
                            {availableEnvironments.length === 0 && String(currentProjectId) === String(moveProject)
                                ? 'No other environments available'
                                : 'Choose an environment...'}
                        </option>
                        {availableEnvironments.map((environment) => (
                            <option key={environment.id} value={environment.id}>
                                {environment.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {moveEnvironment && (
                <button type="button" className="mt-4" onClick={move}>
                    Move Resource
                </button>
            )}
        </div>
    );
}

function ServersTab({ primaryServer }) {
    const running = (primaryServer.status ?? '').startsWith('running');
    const exited = (primaryServer.status ?? '').startsWith('exited');

    return (
        <div>
            <h2>Servers</h2>
            <div>Server related configurations.</div>
            <div className="grid grid-cols-1 gap-4 py-4">
                <div className="flex flex-col gap-2">
                    <h3>Primary Server</h3>
                    <div className="relative flex flex-col bg-white border cursor-default dark:text-white box-without-bg dark:bg-coolgray-100 dark:border-coolgray-300">
                        {running && <div title={primaryServer.status} className="absolute bg-success -top-1 -left-1 badge" />}
                        {exited && <div title={primaryServer.status} className="absolute bg-error -top-1 -left-1 badge" />}
                        <div className="box-title">Server: {primaryServer.name}</div>
                        <div className="box-description">Network: {primaryServer.network}</div>
                    </div>
                </div>
            </div>
        </div>
    );
}

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
