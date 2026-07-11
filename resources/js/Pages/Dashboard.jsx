import { useState } from 'react';
import AddProjectModal from '../Components/AddProjectModal';
import AddServerModal from '../Components/AddServerModal';
import PrivateKeyCreateModal from '../Components/PrivateKeyCreateModal';

export default function Dashboard({
    projects,
    servers,
    privateKeys,
    canCreateProject,
    canCreateServer,
    limitReached,
    defaultServerName,
    defaultPrivateKeyId,
    createProjectUrl,
    createServerUrl,
    createKeyUrl,
    generateKeyUrl,
    onboardingUrl,
}) {
    const [showAddProjectModal, setShowAddProjectModal] = useState(false);
    const [showAddServerModal, setShowAddServerModal] = useState(false);
    const [showAddKeyModal, setShowAddKeyModal] = useState(false);

    return (
        <div>
            <h1>Dashboard</h1>
            <div className="subtitle">Your self-hosted infrastructure.</div>

            <section className="-mt-2">
                <div className="flex items-center gap-2 pb-2">
                    <h3>Projects</h3>
                    {projects.length > 0 && canCreateProject && (
                        <button type="button" onClick={() => setShowAddProjectModal(true)}>
                            Add
                        </button>
                    )}
                </div>
                {projects.length > 0 ? (
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        {projects.map((project) => (
                            <div key={project.uuid} className="relative gap-2 cursor-pointer coolbox group">
                                <a href={project.navigateUrl} className="absolute inset-0" />
                                <div className="flex flex-1 mx-6">
                                    <div className="flex flex-col justify-center flex-1">
                                        <div className="box-title">{project.name}</div>
                                        <div className="box-description">{project.description}</div>
                                    </div>
                                    <div className="relative z-10 flex items-center justify-center gap-4 text-xs font-bold">
                                        {project.addResourceUrl && (
                                            <a className="hover:underline" href={project.addResourceUrl}>
                                                + Add Resource
                                            </a>
                                        )}
                                        {project.canUpdate && (
                                            <a className="hover:underline" href={project.editUrl}>
                                                Settings
                                            </a>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="flex flex-col gap-1">
                        <div className="font-bold dark:text-warning">No projects found.</div>
                        <div className="flex items-center gap-1">
                            <button type="button" onClick={() => setShowAddProjectModal(true)}>
                                Add
                            </button>
                            your first project or go to the{' '}
                            <a className="underline dark:text-white" href={onboardingUrl}>
                                onboarding
                            </a>{' '}
                            page.
                        </div>
                    </div>
                )}
            </section>

            <section>
                <div className="flex items-center gap-2 pb-2">
                    <h3>Servers</h3>
                    {servers.length > 0 && privateKeys.length > 0 && canCreateServer && (
                        <button type="button" onClick={() => setShowAddServerModal(true)}>
                            Add
                        </button>
                    )}
                </div>
                {servers.length > 0 ? (
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        {servers.map((server) => (
                            <a
                                key={server.uuid}
                                href={server.showUrl}
                                className={`gap-2 border cursor-pointer coolbox group ${
                                    !server.isReachable || server.forceDisabled ? 'border-red-500' : ''
                                }`}
                            >
                                <div className="flex flex-col justify-center mx-6">
                                    <div className="box-title">{server.name}</div>
                                    <div className="box-description">{server.description}</div>
                                    <div className="flex gap-1 text-xs text-error">
                                        {!server.isReachable && <span>Not reachable</span>}
                                        {!server.isReachable && !server.isUsable && <span>&amp;</span>}
                                        {!server.isUsable && <span>Not usable by Coolify</span>}
                                    </div>
                                </div>
                            </a>
                        ))}
                    </div>
                ) : privateKeys.length === 0 ? (
                    <div className="flex flex-col gap-1">
                        <div className="font-bold dark:text-warning">No private keys found.</div>
                        <div className="flex items-center gap-1">
                            Before you can add your server, first{' '}
                            <button type="button" onClick={() => setShowAddKeyModal(true)}>
                                add
                            </button>{' '}
                            a private key or go to the{' '}
                            <a className="underline dark:text-white" href={onboardingUrl}>
                                onboarding
                            </a>{' '}
                            page.
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col gap-1">
                        <div className="font-bold dark:text-warning">No servers found.</div>
                        <div className="flex items-center gap-1">
                            <button type="button" onClick={() => setShowAddServerModal(true)}>
                                Add
                            </button>
                            your first server or go to the{' '}
                            <a className="underline dark:text-white" href={onboardingUrl}>
                                onboarding
                            </a>{' '}
                            page.
                        </div>
                    </div>
                )}
            </section>

            {showAddProjectModal && (
                <AddProjectModal createUrl={createProjectUrl} onClose={() => setShowAddProjectModal(false)} />
            )}
            {showAddServerModal && (
                <AddServerModal
                    privateKeys={privateKeys}
                    defaultPrivateKeyId={defaultPrivateKeyId}
                    defaultName={defaultServerName}
                    limitReached={limitReached}
                    storeUrl={createServerUrl}
                    onClose={() => setShowAddServerModal(false)}
                />
            )}
            <PrivateKeyCreateModal
                open={showAddKeyModal}
                onClose={() => setShowAddKeyModal(false)}
                createKeyUrl={createKeyUrl}
                generateKeyUrl={generateKeyUrl}
                onCreated={() => setShowAddKeyModal(false)}
            />
        </div>
    );
}
