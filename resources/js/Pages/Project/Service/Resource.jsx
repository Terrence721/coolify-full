import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DomainConflictModal from '../../../Components/DomainConflictModal';
import PasswordConfirmModal from '../../../Components/PasswordConfirmModal';
import DatabaseImportTab from '../../../Components/DatabaseImportTab';
import ServiceHeading from '../../../Components/ServiceHeading';

const SERVICE_DOMAIN_CONFLICT_CONSEQUENCES = [
    'Only one service will be accessible at this domain',
    'The routing behavior will be unpredictable',
    'You may experience service disruptions',
    'SSL certificates might not work correctly',
];

function ResourceSidebar({ parameters, serviceParameters, resourceType, database }) {
    const base = `/project/${serviceParameters.project_uuid}/environment/${serviceParameters.environment_uuid}/service/${serviceParameters.service_uuid}`;
    const stackBase = `${base}/${parameters.stack_service_uuid}`;

    return (
        <div className="sub-menu-wrapper">
            <a className="sub-menu-item" href={`${base}`}>
                Back
            </a>
            <a className="sub-menu-item" href={stackBase}>
                General
            </a>
            <a className="sub-menu-item" href={`${stackBase}/advanced`}>
                Advanced
            </a>
            {resourceType === 'database' && (database?.isBackupSolutionAvailable || database?.isMigrated) && (
                <a className="sub-menu-item" href={`${stackBase}/backups`}>
                    Backups
                </a>
            )}
            {resourceType === 'database' && database?.isImportSupported && (
                <a className="sub-menu-item" href={`${stackBase}/import`}>
                    Import Backup
                </a>
            )}
        </div>
    );
}

function ApplicationGeneral({ application, urls }) {
    const { data, setData, post, processing, errors } = useForm({
        human_name: application.humanName ?? '',
        description: application.description ?? '',
        fqdn: application.fqdn ?? '',
        image: application.image ?? '',
    });
    const { props } = usePage();
    const [confirmingConvert, setConfirmingConvert] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [confirmingPort, setConfirmingPort] = useState(false);

    useEffect(() => {
        if (props.flash?.showPortWarningModal) {
            setConfirmingPort(true);
        }
    }, [props.flash?.showPortWarningModal]);

    function submit(e) {
        e.preventDefault();
        post(urls.update, { preserveScroll: true });
    }

    function confirmDomainUsage() {
        post(urls.update, { preserveScroll: true, data: { ...data, force_save_domains: true } });
    }

    function confirmPortRemoval() {
        setConfirmingPort(false);
        post(urls.update, { preserveScroll: true, data: { ...data, force_remove_port: true } });
    }

    const showDomainField = !application.isKnownServiceType;

    return (
        <form onSubmit={submit}>
            <div className="flex items-center gap-2 pb-4">
                <h2>{application.humanName || application.name}</h2>
                <button type="submit" disabled={processing}>
                    Save
                </button>
                <button type="button" onClick={() => setConfirmingConvert(true)}>
                    Convert to Database
                </button>
                <button type="button" className="text-error" onClick={() => setConfirmingDelete(true)}>
                    Delete
                </button>
            </div>

            <div className="flex flex-col gap-2">
                {application.requiredPort && !application.isKnownServiceType && (
                    <div className="text-sm">
                        This service requires port <strong>{application.requiredPort}</strong> to function correctly. All domains must include this
                        port number (or any other port if you know what you&apos;re doing).
                    </div>
                )}

                <div className="flex gap-2">
                    <label className="flex flex-col gap-1">
                        Name
                        <input
                            id="human_name"
                            name="human_name"
                            value={data.human_name}
                            onChange={(e) => setData('human_name', e.target.value)}
                            placeholder="Human readable name"
                        />
                    </label>
                    <label className="flex flex-col gap-1">
                        Description
                        <input
                            id="description"
                            name="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </label>
                </div>
                <div className="flex gap-2">
                    {showDomainField && (
                        <label className="flex flex-col gap-1">
                            Domains
                            <input
                                id="fqdn"
                                name="fqdn"
                                required={application.requiredFqdn}
                                value={data.fqdn}
                                onChange={(e) => setData('fqdn', e.target.value)}
                                placeholder="https://app.coolify.io"
                            />
                        </label>
                    )}
                    <label className="flex flex-col gap-1">
                        Image
                        <input id="image" name="image" value={data.image} onChange={(e) => setData('image', e.target.value)} />
                    </label>
                </div>
                {errors.image && <div className="text-error">{errors.image}</div>}
            </div>

            {props.flash?.domainConflicts?.length > 0 && (
                <DomainConflictModal
                    conflicts={props.flash.domainConflicts}
                    onCancel={() => router.reload({ only: ['flash'] })}
                    onConfirm={confirmDomainUsage}
                    consequences={SERVICE_DOMAIN_CONFLICT_CONSEQUENCES}
                />
            )}

            {confirmingPort && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setConfirmingPort(false)} />
                    <div className="relative w-full max-w-lg rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base">
                        <h3 className="text-lg font-bold pb-2">Remove Required Port?</h3>
                        <p className="text-sm pb-4">
                            This service requires port <strong>{props.flash?.requiredPort}</strong> to function correctly. One or more of your domains
                            are missing a port number. The service may become unreachable or fail to start if you continue.
                        </p>
                        <div className="flex justify-between gap-2">
                            <button type="button" onClick={() => setConfirmingPort(false)}>
                                Cancel - Keep Port
                            </button>
                            <button type="button" className="text-error" onClick={confirmPortRemoval}>
                                I understand, remove port anyway
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {confirmingConvert && (
                <PasswordConfirmModal
                    title="Convert to Database"
                    action={{ method: 'post', url: urls.convert }}
                    actions={['The selected resource will be converted to a service database.']}
                    confirmationText={application.humanName || application.name}
                    confirmationLabel="Please confirm by entering the Service Application Name below"
                    onClose={() => setConfirmingConvert(false)}
                    onDone={() => setConfirmingConvert(false)}
                />
            )}
            {confirmingDelete && (
                <PasswordConfirmModal
                    title="Confirm Service Application Deletion?"
                    action={{ method: 'delete', url: urls.delete }}
                    actions={['The selected service application container will be stopped and permanently deleted.']}
                    confirmationText={application.humanName || application.name}
                    confirmationLabel="Please confirm by entering the Service Application Name below"
                    onClose={() => setConfirmingDelete(false)}
                    onDone={() => setConfirmingDelete(false)}
                />
            )}
        </form>
    );
}

function ApplicationAdvanced({ application, urls }) {
    const { data, setData, post, processing } = useForm({
        is_gzip_enabled: application.isGzipEnabled,
        is_stripprefix_enabled: application.isStripprefixEnabled,
        exclude_from_status: application.excludeFromStatus,
        is_log_drain_enabled: application.isLogDrainEnabled,
    });

    function toggle(field) {
        const next = { ...data, [field]: !data[field] };
        setData(next);
        post(urls.updateAdvanced, { data: next, preserveScroll: true });
    }

    return (
        <div>
            <h2>Advanced</h2>
            <div className="w-full sm:w-96 flex flex-col gap-1 pt-4">
                <label className="flex items-center gap-2">
                    <input
                        id="is_gzip_enabled"
                        name="is_gzip_enabled"
                        type="checkbox"
                        checked={data.is_gzip_enabled}
                        disabled={application.isGzipToggleDisabled || processing}
                        onChange={() => toggle('is_gzip_enabled')}
                    />
                    Enable Gzip Compression
                </label>
                <label className="flex items-center gap-2">
                    <input
                        id="is_stripprefix_enabled"
                        name="is_stripprefix_enabled"
                        type="checkbox"
                        checked={data.is_stripprefix_enabled}
                        disabled={processing}
                        onChange={() => toggle('is_stripprefix_enabled')}
                    />
                    Strip Prefixes
                </label>
                <label className="flex items-center gap-2">
                    <input
                        id="exclude_from_status"
                        name="exclude_from_status"
                        type="checkbox"
                        checked={data.exclude_from_status}
                        disabled={processing}
                        onChange={() => toggle('exclude_from_status')}
                    />
                    Exclude from service status
                </label>
                <label className="flex items-center gap-2">
                    <input
                        id="is_log_drain_enabled"
                        name="is_log_drain_enabled"
                        type="checkbox"
                        checked={data.is_log_drain_enabled}
                        disabled={processing}
                        onChange={() => toggle('is_log_drain_enabled')}
                    />
                    Drain Logs
                </label>
            </div>
        </div>
    );
}

function DatabaseGeneral({ database, urls }) {
    const { data, setData, post, processing, errors } = useForm({
        human_name: database.humanName ?? '',
        description: database.description ?? '',
        image: database.image ?? '',
    });
    const [confirmingConvert, setConfirmingConvert] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [showLogs, setShowLogs] = useState(false);
    const [publicData, setPublicData] = useState({
        is_public: database.isPublic,
        public_port: database.publicPort ?? '',
        public_port_timeout: database.publicPortTimeout ?? 3600,
    });
    const [publicProcessing, setPublicProcessing] = useState(false);

    function submit(e) {
        e.preventDefault();
        post(urls.update, { preserveScroll: true });
    }

    function togglePublic() {
        const next = { ...publicData, is_public: !publicData.is_public };
        setPublicData(next);
        setPublicProcessing(true);
        router.post(urls.updatePublic, next, { preserveScroll: true, onFinish: () => setPublicProcessing(false) });
    }

    return (
        <form onSubmit={submit}>
            <div className="flex items-center gap-2 pb-4">
                <h2>{database.humanName || database.name}</h2>
                <button type="submit" disabled={processing}>
                    Save
                </button>
                <button type="button" onClick={() => setConfirmingConvert(true)}>
                    Convert to Application
                </button>
                <button type="button" className="text-error" onClick={() => setConfirmingDelete(true)}>
                    Delete
                </button>
            </div>
            <div className="flex flex-col gap-2">
                <div className="flex gap-2">
                    <label className="flex flex-col gap-1">
                        Name
                        <input
                            id="database_human_name"
                            name="human_name"
                            value={data.human_name}
                            onChange={(e) => setData('human_name', e.target.value)}
                            placeholder="Name"
                        />
                    </label>
                    <label className="flex flex-col gap-1">
                        Description
                        <input
                            id="database_description"
                            name="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </label>
                    <label className="flex flex-col gap-1">
                        Image
                        <input id="database_image" name="image" value={data.image} onChange={(e) => setData('image', e.target.value)} />
                    </label>
                </div>
                {errors.image && <div className="text-error">{errors.image}</div>}

                <div className="flex flex-col gap-2 pt-2">
                    <div className="flex items-center gap-2 py-2">
                        <h3>Proxy</h3>
                        {database.isPublic && (
                            <button type="button" onClick={() => setShowLogs(true)}>
                                Logs
                            </button>
                        )}
                    </div>
                    <label className="flex items-center gap-2">
                        <input
                            id="is_public"
                            name="is_public"
                            type="checkbox"
                            checked={publicData.is_public}
                            disabled={publicProcessing}
                            onChange={togglePublic}
                        />
                        Make it publicly available
                    </label>
                    <label className="flex flex-col gap-1 w-64">
                        Public Port
                        <input
                            id="public_port"
                            name="public_port"
                            type="number"
                            placeholder="5432"
                            disabled={publicData.is_public}
                            value={publicData.public_port}
                            onChange={(e) => setPublicData({ ...publicData, public_port: e.target.value })}
                        />
                    </label>
                    {database.dbUrlPublic && (
                        <label className="flex flex-col gap-1">
                            Database IP:PORT (public)
                            <input id="db_url_public" name="db_url_public" type="password" readOnly value={database.dbUrlPublic} />
                        </label>
                    )}
                </div>
            </div>

            {showLogs && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowLogs(false)} />
                    <div className="relative flex h-[80vh] w-full flex-col rounded-sm border border-neutral-200 bg-white p-4 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                        <div className="flex items-center justify-between pb-2">
                            <h3 className="text-xl font-bold">Proxy Logs</h3>
                            <button type="button" onClick={() => setShowLogs(false)}>
                                ✕
                            </button>
                        </div>
                        <ProxyLogs url={urls.proxyLogs} />
                    </div>
                </div>
            )}

            {confirmingConvert && (
                <PasswordConfirmModal
                    title="Convert to Application"
                    action={{ method: 'post', url: urls.convert }}
                    actions={['The selected resource will be converted to an application.']}
                    confirmationText={database.humanName || database.name}
                    confirmationLabel="Please confirm by entering the Service Database Name below"
                    onClose={() => setConfirmingConvert(false)}
                    onDone={() => setConfirmingConvert(false)}
                />
            )}
            {confirmingDelete && (
                <PasswordConfirmModal
                    title="Confirm Service Database Deletion?"
                    action={{ method: 'delete', url: urls.delete }}
                    actions={['The selected service database container will be stopped and permanently deleted.']}
                    confirmationText={database.humanName || database.name}
                    confirmationLabel="Please confirm by entering the Service Database Name below"
                    onClose={() => setConfirmingDelete(false)}
                    onDone={() => setConfirmingDelete(false)}
                />
            )}
        </form>
    );
}

/**
 * Lightweight fetch-on-open log viewer for the database proxy slide-over. Fetches directly
 * (not via an Inertia page prop) since this modal isn't tied to the page's own reload cycle.
 * Deliberately simpler than ContainerLogs.jsx's full feature set (search/filter/stream/
 * fullscreen/lines-count controls) — ContainerLogs.jsx's controls reload the current page's
 * `logLines` prop via `router.reload()`, which this page doesn't have (logs are fetched into
 * local state instead), so reusing it here would render controls that silently do nothing.
 */
function ProxyLogs({ url }) {
    const [logLines, setLogLines] = useState(null);

    useEffect(() => {
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data) => setLogLines(data.logLines ?? []));
    }, [url]);

    if (logLines === null) {
        return <div className="pt-2">Loading...</div>;
    }

    if (logLines.length === 0) {
        return <div className="pt-2">No logs yet.</div>;
    }

    return (
        <pre className="flex-1 overflow-auto rounded-sm bg-black p-2 text-xs text-white">
            {logLines.map((entry, i) => (
                <div key={i}>
                    {entry.timestamp && <span className="text-neutral-500">{entry.timestamp} </span>}
                    {entry.line}
                </div>
            ))}
        </pre>
    );
}

function DatabaseAdvanced({ database, urls }) {
    const { data, setData, post, processing } = useForm({
        exclude_from_status: database.excludeFromStatus,
        is_log_drain_enabled: database.isLogDrainEnabled,
    });

    function toggle(field) {
        const next = { ...data, [field]: !data[field] };
        setData(next);
        post(urls.updateAdvanced, { data: next, preserveScroll: true });
    }

    return (
        <div>
            <h2>Advanced</h2>
            <div className="w-full sm:w-96 flex flex-col gap-1 pt-4">
                <label className="flex items-center gap-2">
                    <input
                        id="database_exclude_from_status"
                        name="exclude_from_status"
                        type="checkbox"
                        checked={data.exclude_from_status}
                        disabled={processing}
                        onChange={() => toggle('exclude_from_status')}
                    />
                    Exclude from service status
                </label>
                <label className="flex items-center gap-2">
                    <input
                        id="database_is_log_drain_enabled"
                        name="is_log_drain_enabled"
                        type="checkbox"
                        checked={data.is_log_drain_enabled}
                        disabled={processing}
                        onChange={() => toggle('is_log_drain_enabled')}
                    />
                    Drain Logs
                </label>
            </div>
        </div>
    );
}

/**
 * Port of livewire/project/service/index.blade.php's `project.service.index` and
 * `project.service.index.advanced` routes. `project.service.database.import` deliberately
 * stays on the original Livewire page — see ProjectServiceResourceController's docblock.
 */
export default function Resource({
    resourceType,
    tab,
    service,
    serviceHeadingUrls,
    parameters,
    serviceParameters,
    application,
    database,
    urls,
    importTab,
}) {
    const { props: pageProps } = usePage();
    return (
        <div>
            <Head title={`${service.name} > Commands`} />
            <ServiceHeading service={service} parameters={serviceParameters} urls={serviceHeadingUrls} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ResourceSidebar parameters={parameters} serviceParameters={serviceParameters} resourceType={resourceType} database={database} />
                <div className="w-full">
                    {resourceType === 'application' && tab === 'general' && <ApplicationGeneral application={application} urls={urls} />}
                    {resourceType === 'application' && tab === 'advanced' && <ApplicationAdvanced application={application} urls={urls} />}
                    {resourceType === 'database' && tab === 'general' && <DatabaseGeneral database={database} urls={urls} />}
                    {resourceType === 'database' && tab === 'advanced' && <DatabaseAdvanced database={database} urls={urls} />}
                    {resourceType === 'database' && tab === 'import' && importTab && (
                        <DatabaseImportTab importTab={importTab} flash={pageProps.flash} />
                    )}
                </div>
            </div>
        </div>
    );
}
