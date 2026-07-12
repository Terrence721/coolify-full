import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * React port of App\Livewire\Project\New\Select + App\Livewire\Project\Resource\Create — the
 * "+ New" resource wizard. Every step is a fresh GET to the same URL (`project.resource.create`)
 * with the accumulated choices added to the query string; the controller (not this component)
 * decides what the next step is, mirroring the original's own `setType`/`setServer`/
 * `setDestination`/`whatToDoNext()` redirect chain.
 */
function currentQuery() {
    return new URLSearchParams(window.location.search);
}

function goTo(params) {
    const query = currentQuery();
    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') {
            query.delete(key);
        } else {
            query.set(key, value);
        }
    });
    router.get(`${window.location.pathname}?${query.toString()}`);
}

function Tile({ title, description, logo, onClick }) {
    return (
        <div className="w-full cursor-pointer coolbox group" onClick={onClick}>
            <div className="flex items-center gap-4 mx-6">
                {logo && (
                    <img
                        className="object-contain w-12 h-12 p-1 transition-all duration-200 bg-black/10 dark:bg-white/10"
                        src={logo}
                        alt=""
                    />
                )}
                <div className="flex flex-col">
                    <div className="box-title">{title}</div>
                    {description && <div className="box-description">{description}</div>}
                </div>
            </div>
        </div>
    );
}

function TypeStep({ project, environment, environments, services, categories, gitBasedApplications, dockerBasedApplications, databases }) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('');

    const term = search.toLowerCase();
    const filteredServices = useMemo(
        () =>
            services.filter((service) => {
                if (category && !(service.category ?? '').includes(category)) return false;
                if (!term) return true;

                return service.name.toLowerCase().includes(term) || (service.description ?? '').toLowerCase().includes(term);
            }),
        [services, term, category],
    );

    function selectType(type) {
        goTo({ type, server_id: null, destination: null, database_image: null });
    }

    function switchEnvironment(environmentUuid) {
        router.get(route('project.resource.create', { project_uuid: project.uuid, environment_uuid: environmentUuid }));
    }

    return (
        <div>
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
                <h1>New Resource</h1>
                <div className="w-full lg:w-96">
                    <select value={environment.uuid} onChange={(e) => switchEnvironment(e.target.value)}>
                        {environments.map((env) => (
                            <option key={env.uuid} value={env.uuid}>
                                Environment: {env.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <div className="mb-4">Deploy resources, like Applications, Databases, Services...</div>
            <div className="flex flex-col gap-2 pb-4 sm:flex-row">
                <input
                    id="resource-search"
                    name="resource-search"
                    autoComplete="off"
                    className="flex-1"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search services..."
                />
                {categories.length > 0 && (
                    <select id="resource-category" name="resource-category" value={category} onChange={(e) => setCategory(e.target.value)}>
                        <option value="">All Categories</option>
                        {categories.map((cat) => (
                            <option key={cat} value={cat}>
                                {cat}
                            </option>
                        ))}
                    </select>
                )}
            </div>

            <div className="flex flex-col gap-8 py-4">
                <div>
                    <h4>Applications</h4>
                    <div className="grid grid-cols-1 gap-8 pt-2 lg:grid-cols-2">
                        <div className="space-y-4">
                            <h5>Git Based</h5>
                            <div className="grid grid-cols-1 gap-4">
                                {gitBasedApplications.map((app) => (
                                    <Tile key={app.id} title={app.name} description={app.description} logo={app.logo} onClick={() => selectType(app.id)} />
                                ))}
                            </div>
                        </div>
                        <div className="space-y-4">
                            <h5>Docker Based</h5>
                            <div className="grid grid-cols-1 gap-4">
                                {dockerBasedApplications.map((app) => (
                                    <Tile key={app.id} title={app.name} description={app.description} logo={app.logo} onClick={() => selectType(app.id)} />
                                ))}
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <h4>Databases</h4>
                    <div className="grid grid-cols-1 gap-4 pt-2 sm:grid-cols-2 lg:grid-cols-3">
                        {databases.map((db) => (
                            <Tile key={db.id} title={db.name} onClick={() => selectType(db.id)} />
                        ))}
                    </div>
                </div>

                {filteredServices.length > 0 && (
                    <div>
                        <h4>Services</h4>
                        <div className="grid grid-cols-1 gap-4 pt-2 sm:grid-cols-2 lg:grid-cols-3">
                            {filteredServices.map((service) => (
                                <Tile
                                    key={service.id}
                                    title={service.name}
                                    description={service.description}
                                    logo={service.logo}
                                    onClick={() => selectType(`one-click-service-${service.id}`)}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function ServersStep({ servers, onlyBuildServerAvailable }) {
    if (onlyBuildServerAvailable) {
        return (
            <div>
                <h2>Select a server</h2>
                <div className="pt-4">
                    Only build servers are available, you need at least one server that is not set as build server.{' '}
                    <a className="underline dark:text-white" href="/servers">
                        Go to servers page
                    </a>
                </div>
            </div>
        );
    }

    return (
        <div>
            <h2>Select a server</h2>
            <div className="flex flex-col justify-center gap-4 pt-5 text-left xl:flex-row xl:flex-wrap">
                {servers.length === 0 && (
                    <div>
                        No validated &amp; reachable servers found.{' '}
                        <a className="underline dark:text-white" href="/servers">
                            Go to servers page
                        </a>
                    </div>
                )}
                {servers.map((server) => (
                    <Tile key={server.id} title={server.name} description={server.description} onClick={() => goTo({ server_id: server.id })} />
                ))}
            </div>
        </div>
    );
}

function DestinationsStep({ isSwarm, destinations }) {
    return (
        <div>
            <h2>Select a destination</h2>
            <div className="pb-4">
                Destinations are used to segregate resources by network. If you are unsure, select the default Standalone Docker (coolify).
            </div>
            <div className="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
                {destinations.map((destination) => (
                    <Tile
                        key={destination.uuid}
                        title={isSwarm ? `Swarm Docker (${destination.name})` : `Standalone Docker (${destination.name})`}
                        description={isSwarm ? undefined : `Network: ${destination.network}`}
                        onClick={() => goTo({ destination: destination.uuid })}
                    />
                ))}
            </div>
        </div>
    );
}

function PostgresqlVersionStep({ postgresqlVersions }) {
    return (
        <div>
            <h2>Select a Postgresql type</h2>
            <div>If you need extra extensions, you can select Supabase PostgreSQL (or others), otherwise select PostgreSQL 18 (default).</div>
            <div className="grid grid-cols-1 gap-6 pt-8 lg:grid-cols-2">
                {postgresqlVersions.map((version) => (
                    <div key={version.image} className="flex relative gap-2 cursor-pointer coolbox group" onClick={() => goTo({ database_image: version.image })}>
                        <div className="flex flex-col">
                            <div className="box-title">{version.name}</div>
                            <div className="box-description">{version.description}</div>
                        </div>
                        <a
                            href={version.url}
                            target="_blank"
                            rel="noreferrer"
                            onClick={(e) => e.stopPropagation()}
                            className="absolute top-2 right-2 p-1.5 rounded hover:bg-neutral-200 dark:hover:bg-coolgray-300 transition-colors"
                            title="View documentation"
                        >
                            ↗
                        </a>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Create(props) {
    switch (props.step) {
        case 'servers':
            return <ServersStep servers={props.servers} onlyBuildServerAvailable={props.onlyBuildServerAvailable} />;
        case 'destinations':
            return <DestinationsStep isSwarm={props.isSwarm} destinations={props.destinations} />;
        case 'select-postgresql-type':
            return <PostgresqlVersionStep postgresqlVersions={props.postgresqlVersions} />;
        case 'type':
        default:
            return <TypeStep {...props} />;
    }
}
