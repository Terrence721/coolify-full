import { useMemo, useState } from 'react';
import DeleteEnvironmentModal from '../../../Components/DeleteEnvironmentModal';

function statusBadgeClass(status) {
    if (status?.startsWith('running')) return { title: 'running', className: 'bg-success badge-dashboard' };
    if (status?.startsWith('exited')) return { title: 'exited', className: 'bg-error badge-dashboard' };
    if (status?.startsWith('starting')) return { title: 'starting', className: 'bg-warning badge-dashboard' };
    if (status?.startsWith('restarting')) return { title: 'restarting', className: 'bg-warning badge-dashboard' };
    if (status?.startsWith('degraded')) return { title: 'degraded', className: 'bg-warning badge-dashboard' };
    return null;
}

function filterAndSort(items, search) {
    const term = search.toLowerCase();
    const filtered = search
        ? items.filter(
              (item) =>
                  item.name?.toLowerCase().includes(term) ||
                  item.fqdn?.toLowerCase().includes(term) ||
                  item.description?.toLowerCase().includes(term) ||
                  item.tags?.some((tag) => tag.name.toLowerCase().includes(term)),
          )
        : items;

    return [...filtered].sort((a, b) => a.name.localeCompare(b.name));
}

function ResourceCard({ item }) {
    const badge = statusBadgeClass(item.status);

    return (
        <span>
            <a className="h-24 coolbox group" href={item.hrefLink}>
                <div className="flex flex-col w-full">
                    <div className="flex gap-2 px-4">
                        <div className="pb-2 truncate box-title">{item.name}</div>
                        <div className="flex-1"></div>
                        {badge && <div title={badge.title} className={badge.className}></div>}
                    </div>
                    <div className="max-w-full px-4 truncate box-description">{item.description}</div>
                    <div className="max-w-full px-4 truncate box-description">{item.fqdn}</div>
                    <div className="max-w-full px-4 pt-1 truncate box-description">
                        Server: <span>{item.destination?.server?.name || 'Unknown'}</span>
                    </div>
                    {item.server_status === false && (
                        <div className="px-4 text-xs font-bold text-error">Server is unreachable or misconfigured</div>
                    )}
                </div>
            </a>
            <div className="flex flex-wrap gap-1 pt-1 dark:group-hover:text-white group-hover:text-black group min-h-6">
                {item.tags.map((tag) => (
                    <a key={tag.id} href={`/tags/${tag.name}`} className="tag">
                        {tag.name}
                    </a>
                ))}
                <a href={`${item.hrefLink}/tags`} className="add-tag">
                    Add tag
                </a>
            </div>
        </span>
    );
}

function EnvironmentRow({ env, isCurrent }) {
    const [showResources, setShowResources] = useState(false);

    return (
        <div className="relative" onMouseEnter={() => setShowResources(true)} onMouseLeave={() => setShowResources(false)}>
            <a
                href={env.resourceIndexUrl}
                className={`flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200 ${isCurrent ? 'dark:text-warning font-semibold' : ''}`}
                title={env.name}
            >
                <span className="truncate">{env.name}</span>
                {env.resources.length > 0 && (
                    <svg className="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="4" d="M9 5l7 7-7 7"></path>
                    </svg>
                )}
            </a>
            {showResources && env.resources.length > 0 && (
                <div className="absolute z-30 left-full top-0 w-56 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                    {env.resources.map((resource) => (
                        <a
                            key={resource.uuid}
                            href={resource.url}
                            className="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200"
                            title={resource.name}
                        >
                            {resource.name}
                        </a>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Index({
    project,
    environment,
    allProjects,
    allEnvironments,
    applications,
    databases,
    services,
    canCreate,
    canDelete,
    projectShowUrl,
    createUrl,
    cloneUrl,
    deleteUrl,
}) {
    const [search, setSearch] = useState('');
    const [showProjectDropdown, setShowProjectDropdown] = useState(false);
    const [showEnvDropdown, setShowEnvDropdown] = useState(false);
    const [showDeleteModal, setShowDeleteModal] = useState(false);

    const filteredApplications = useMemo(() => filterAndSort(applications, search), [applications, search]);
    const filteredDatabases = useMemo(() => filterAndSort(databases, search), [databases, search]);
    const filteredServices = useMemo(() => filterAndSort(services, search), [services, search]);
    const hasAnyResults = filteredApplications.length > 0 || filteredDatabases.length > 0 || filteredServices.length > 0;

    return (
        <div>
            <div className="flex flex-col">
                <div className="flex min-w-0 flex-nowrap items-center gap-1">
                    <h1>Resources</h1>
                    {canCreate && (
                        <a href={createUrl} className="button">
                            + New
                        </a>
                    )}
                    {canCreate && (
                        <a href={cloneUrl} className="button">
                            Clone
                        </a>
                    )}
                    {canDelete && (
                        <button type="button" onClick={() => setShowDeleteModal(true)}>
                            Delete Environment
                        </button>
                    )}
                </div>
                <nav className="flex pt-2 pb-6">
                    <ol className="flex items-center">
                        <li
                            className="inline-flex items-center relative"
                            onMouseEnter={() => setShowProjectDropdown(true)}
                            onMouseLeave={() => setShowProjectDropdown(false)}
                        >
                            <div className="flex items-center relative">
                                <a className="text-xs truncate lg:text-sm hover:text-warning" href={projectShowUrl}>
                                    {project.name}
                                </a>
                            </div>
                            {showProjectDropdown && (
                                <div className="absolute z-20 top-full mt-1 w-56 -ml-2 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                                    {allProjects.map((proj) => (
                                        <a
                                            key={proj.uuid}
                                            href={proj.showUrl}
                                            className={`block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200 ${proj.uuid === project.uuid ? 'dark:text-warning font-semibold' : ''}`}
                                            title={proj.name}
                                        >
                                            {proj.name}
                                        </a>
                                    ))}
                                </div>
                            )}
                        </li>
                        <li
                            className="inline-flex items-center relative"
                            onMouseEnter={() => setShowEnvDropdown(true)}
                            onMouseLeave={() => setShowEnvDropdown(false)}
                        >
                            <div className="flex items-center relative">
                                <a className="text-xs truncate lg:text-sm hover:text-warning" href={environment.resourceIndexUrl}>
                                    {environment.name}
                                </a>
                            </div>
                            {showEnvDropdown && (
                                <div className="absolute z-20 top-full mt-1 left-0 w-48">
                                    <div className="relative w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                                        {allEnvironments.map((env) => (
                                            <EnvironmentRow key={env.uuid} env={env} isCurrent={env.uuid === environment.uuid} />
                                        ))}
                                        <div className="border-t border-neutral-200 dark:border-coolgray-200 mt-1 pt-1">
                                            <a
                                                href={projectShowUrl}
                                                className="flex items-center gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200"
                                            >
                                                Create / Edit
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </li>
                    </ol>
                </nav>
            </div>

            {environment.isEmpty ? (
                canCreate ? (
                    <a href={createUrl} className="items-center justify-center coolbox">
                        + Add Resource
                    </a>
                ) : (
                    <div className="flex flex-col items-center justify-center p-8 text-center border border-dashed border-neutral-300 dark:border-coolgray-300 rounded-lg">
                        <h3 className="mb-2 text-lg font-semibold text-neutral-600 dark:text-neutral-400">No Resources Found</h3>
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">
                            This environment doesn't have any resources yet.
                            <br />
                            Contact your team administrator to add resources.
                        </p>
                    </div>
                )
            ) : (
                <div>
                    <input placeholder="Search for name, fqdn..." value={search} onChange={(e) => setSearch(e.target.value)} />

                    {!hasAnyResults && (
                        <div className="flex flex-col items-center justify-center p-8 text-center">
                            {search.length > 0 ? (
                                <div>
                                    <p className="text-neutral-600 dark:text-neutral-400">
                                        No resource found with the search term "<span className="font-semibold">{search}</span>".
                                    </p>
                                    <p className="text-sm text-neutral-500 dark:text-neutral-500 mt-1">
                                        Try adjusting your search criteria.
                                    </p>
                                </div>
                            ) : (
                                <div>
                                    <p className="text-neutral-600 dark:text-neutral-400">No resources found in this environment.</p>
                                    {!canCreate && (
                                        <p className="text-sm text-neutral-500 dark:text-neutral-500 mt-1">
                                            Contact your team administrator to add resources.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {filteredApplications.length > 0 && (
                        <>
                            <h2 className="pt-4">Applications</h2>
                            <div className="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                                {filteredApplications.map((item) => (
                                    <ResourceCard key={item.uuid} item={item} />
                                ))}
                            </div>
                        </>
                    )}

                    {filteredDatabases.length > 0 && (
                        <>
                            <h2 className="pt-4">Databases</h2>
                            <div className="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                                {filteredDatabases.map((item) => (
                                    <ResourceCard key={item.uuid} item={item} />
                                ))}
                            </div>
                        </>
                    )}

                    {filteredServices.length > 0 && (
                        <>
                            <h2 className="pt-4">Services</h2>
                            <div className="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                                {filteredServices.map((item) => (
                                    <ResourceCard key={item.uuid} item={item} />
                                ))}
                            </div>
                        </>
                    )}
                </div>
            )}

            {showDeleteModal && (
                <DeleteEnvironmentModal
                    environment={environment}
                    deleteUrl={deleteUrl}
                    onClose={() => setShowDeleteModal(false)}
                />
            )}
        </div>
    );
}
