import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ActivityLog from '../../Components/ActivityLog';
import PasswordConfirmModal from '../../Components/PasswordConfirmModal';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';
import { useTeamChannel } from '../../hooks/useTeamChannel';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify(body ?? {}),
    });
    const data = await response.json().catch(() => ({}));

    return { ok: response.ok, data };
}

const HETZNER_POLLING_STATUSES = ['starting', 'initializing'];

/**
 * React port of App\Livewire\Server\Show — the "General" tab, and the last full-page Livewire
 * component in the whole migration. See ServerShowController's docblock for the two findings
 * that shrank this port's scope (dead Sentinel/metrics UI on this page; swarm toggles live on
 * the separate /server/{uuid}/swarm page, not here) and why the validate/install flow reuses
 * ServerValidationService instead of a third implementation.
 */
export default function Show({ serverNavbar, sidebar, server, timezones, availableHetznerTokens, isCloud, urls }) {
    const { data, setData, patch, processing, errors } = useForm({
        name: server.name,
        description: server.description ?? '',
        ip: server.ip,
        user: server.user,
        port: server.port,
        connectionTimeout: server.connectionTimeout,
        wildcardDomain: server.wildcardDomain ?? '',
        serverTimezone: server.serverTimezone ?? '',
    });

    const [showLocalhostConfirm, setShowLocalhostConfirm] = useState(false);
    const [isBuildServer, setIsBuildServer] = useState(server.isBuildServer);
    const [hetznerStatus, setHetznerStatus] = useState(server.hetznerServerStatus);
    const [refreshingHetzner, setRefreshingHetzner] = useState(false);

    const [selectedTokenId, setSelectedTokenId] = useState('');
    const [manualHetznerServerId, setManualHetznerServerId] = useState('');
    const [matchedServer, setMatchedServer] = useState(null);
    const [hetznerSearchError, setHetznerSearchError] = useState(null);
    const [hetznerNoMatchFound, setHetznerNoMatchFound] = useState(false);
    const [searching, setSearching] = useState(false);

    const [isValidating, setIsValidating] = useState(server.isValidating);
    const [installActivity, setInstallActivity] = useState(null);
    const [validateError, setValidateError] = useState(null);
    const [attempt, setAttempt] = useState(0);
    const [validating, setValidating] = useState(false);

    const initialHetznerCheckDone = useRef(false);

    useEffect(() => {
        if (!initialHetznerCheckDone.current && server.hetznerServerId && server.hasCloudProviderToken && !hetznerStatus) {
            initialHetznerCheckDone.current = true;
            checkHetznerStatus();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!HETZNER_POLLING_STATUSES.includes(hetznerStatus)) return undefined;
        const interval = setInterval(() => checkHetznerStatus(), 5000);

        return () => clearInterval(interval);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [hetznerStatus]);

    useTeamChannel(['.ServerValidated'], (eventName, payload) => {
        if (payload?.serverUuid && payload.serverUuid !== server.uuid) return;
        router.reload({ only: ['server'] });
    });

    async function checkHetznerStatus() {
        setRefreshingHetzner(true);
        const { ok, data: result } = await postJson(urls.hetznerStatus);
        setRefreshingHetzner(false);
        if (ok) {
            setHetznerStatus(result.status);
            if (result.reachabilityNote) window.toast?.('Info', { type: 'info', description: result.reachabilityNote });
        }
    }

    function submitGeneral(e) {
        e.preventDefault();
        if (server.id === 0) {
            setShowLocalhostConfirm(true);

            return;
        }
        patch(urls.update);
    }

    async function toggleBuildServer(value) {
        setIsBuildServer(value);
        await postJson(urls.instantSaveBuildServer, { isBuildServer: value });
        router.reload({ only: [] });
    }

    async function checkLocalhostConnection() {
        router.post(urls.checkLocalhost);
    }

    async function refreshMetadata() {
        router.post(urls.refreshMetadata);
    }

    async function runValidate(installFlag, attemptNumber) {
        setValidating(true);
        setValidateError(null);
        const { ok, data: result } = await postJson(urls.validate, { install: installFlag, attempt: attemptNumber });
        setValidating(false);
        if (!ok) {
            setValidateError(result.message ?? 'Validation failed.');
            setIsValidating(false);

            return;
        }
        if (result.status === 'installing') {
            setIsValidating(true);
            setInstallActivity({ id: result.activityId, step: result.step });
            setAttempt(result.attempt);

            return;
        }
        setInstallActivity(null);
        setIsValidating(false);
        if (result.status === 'validated') {
            window.toast?.('Success', { type: 'success', description: 'Server validated, proxy is starting in a moment.' });
            router.reload({ only: ['server'] });

            return;
        }
        setValidateError(
            result.status === 'unreachable'
                ? `Server is not reachable. Please validate your configuration and connection.\n${result.error ?? ''}`
                : result.status === 'unsupported_os'
                  ? 'Server OS type is not supported. Please install Docker manually before continuing.'
                  : (result.error ?? 'Validation failed.'),
        );
    }

    function startValidate() {
        setAttempt(0);
        setInstallActivity(null);
        setValidateError(null);
        // Save the general form first — Validate operates on the persisted server record, not
        // on unsaved input, so an unsaved IP/user/port edit would otherwise silently validate
        // the old value. See docs/livewire-to-react-migration.md's Phase 78 follow-up.
        patch(urls.update, {
            preserveScroll: true,
            onSuccess: () => runValidate(true, 0),
        });
    }

    function onInstallFinished() {
        setInstallActivity(null);
        runValidate(true, attempt);
    }

    async function searchByIp() {
        if (!selectedTokenId) {
            setHetznerSearchError('Please select a Hetzner token.');

            return;
        }
        setSearching(true);
        setHetznerSearchError(null);
        setHetznerNoMatchFound(false);
        setMatchedServer(null);
        const { ok, data: result } = await postJson(urls.hetznerSearchByIp, { token_id: selectedTokenId });
        setSearching(false);
        if (!ok) {
            setHetznerSearchError(result.message ?? 'Search failed.');

            return;
        }
        if (result.match) setMatchedServer(result.match);
        else setHetznerNoMatchFound(true);
    }

    async function searchById() {
        if (!selectedTokenId) {
            setHetznerSearchError('Please select a Hetzner token first.');

            return;
        }
        if (!manualHetznerServerId) {
            setHetznerSearchError('Please enter a Hetzner Server ID.');

            return;
        }
        setSearching(true);
        setHetznerSearchError(null);
        setHetznerNoMatchFound(false);
        setMatchedServer(null);
        const { ok, data: result } = await postJson(urls.hetznerSearchById, {
            token_id: selectedTokenId,
            hetzner_server_id: manualHetznerServerId,
        });
        setSearching(false);
        if (!ok) {
            setHetznerSearchError(result.message ?? 'Search failed.');

            return;
        }
        if (result.match) setMatchedServer(result.match);
        else setHetznerNoMatchFound(true);
    }

    function linkToHetzner() {
        router.post(
            urls.hetznerLink,
            { token_id: selectedTokenId, hetzner_server_id: matchedServer.id },
            { onSuccess: () => router.reload({ only: ['server', 'availableHetznerTokens'] }) },
        );
    }

    const needsValidation =
        (!server.isReachable || !server.isUsable) &&
        server.id !== 0 &&
        !isValidating &&
        !['initializing', 'starting', 'stopping', 'off'].includes(hetznerStatus);

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <form onSubmit={submitGeneral} className="flex flex-col">
                        <div className="flex items-center gap-2 flex-wrap">
                            <h2>General</h2>
                            {server.hetznerServerId && (
                                <div className="flex items-center gap-1">
                                    <span className="flex items-center gap-1.5 px-2 py-1 text-xs font-semibold rounded bg-white dark:bg-coolgray-100 dark:text-white">
                                        {hetznerStatus ? (
                                            <span
                                                className={
                                                    hetznerStatus === 'running' ? 'text-green-500' : hetznerStatus === 'off' ? 'text-red-500' : ''
                                                }
                                            >
                                                {refreshingHetzner && '⟳ '}
                                                {hetznerStatus.charAt(0).toUpperCase() + hetznerStatus.slice(1)}
                                            </span>
                                        ) : (
                                            <span>Checking status...</span>
                                        )}
                                    </span>
                                    <button type="button" title="Refresh Status" onClick={checkHetznerStatus} disabled={refreshingHetzner}>
                                        ⟳
                                    </button>
                                    {server.hasCloudProviderToken && !server.isFunctional && hetznerStatus === 'off' && (
                                        <button type="button" onClick={() => router.post(urls.hetznerStart)}>
                                            Power On
                                        </button>
                                    )}
                                </div>
                            )}
                            {isValidating && <span className="text-xs font-semibold text-warning">Validating...</span>}
                            <button type="submit" disabled={processing || isValidating}>
                                Save
                            </button>
                        </div>
                        <div className="mb-2 text-sm dark:text-neutral-400">
                            {server.isFunctional ? 'Server is reachable and validated.' : "You can't use this server until it is validated."}
                        </div>

                        {isValidating && installActivity && (
                            <div className="mb-4 p-4 border rounded-lg border-neutral-200 dark:border-coolgray-400">
                                <ActivityLog
                                    activityId={installActivity.id}
                                    header={installActivity.step === 'prerequisites' ? 'Installing Prerequisites' : 'Installing Docker'}
                                    onFinished={onInstallFinished}
                                />
                            </div>
                        )}

                        {needsValidation && (
                            <div className="mb-4 flex flex-col gap-2">
                                <button type="button" onClick={startValidate} disabled={validating || processing} className="w-full font-bold">
                                    {validating ? 'Validating…' : 'Validate Server & Install Docker Engine'}
                                </button>
                                {validateError && <div className="text-sm text-error whitespace-pre-line">{validateError}</div>}
                                {server.validationLogs && (
                                    <>
                                        <h4>Previous Validation Logs</h4>
                                        {}
                                        <div className="pb-4" dangerouslySetInnerHTML={{ __html: server.validationLogs }} />
                                    </>
                                )}
                            </div>
                        )}

                        {server.id === 0 && (!server.isReachable || !server.isUsable) && (
                            <div className="mb-4">
                                <button type="button" onClick={checkLocalhostConnection} className="font-bold">
                                    Validate Server
                                </button>
                            </div>
                        )}

                        {server.id !== 0 && server.isFunctional && (
                            <div className="mb-4">
                                <button type="button" onClick={startValidate} disabled={validating || processing}>
                                    Revalidate server
                                </button>
                                {validateError && <div className="text-sm text-error whitespace-pre-line">{validateError}</div>}
                            </div>
                        )}

                        {server.isForceDisabled && isCloud && (
                            <div className="mb-4 p-4 border border-error rounded-lg">
                                Server Disabled — the system has disabled the server because you have exceeded the number of servers for which you
                                have paid.
                            </div>
                        )}

                        <div className="flex flex-col gap-2 pt-2">
                            <div className="flex flex-col gap-2 w-full lg:flex-row">
                                <label className="flex flex-col gap-1 w-full">
                                    Name
                                    <input
                                        id="server-name"
                                        name="server-name"
                                        required
                                        disabled={isValidating}
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                    />
                                    {errors.name && <span className="text-error">{errors.name}</span>}
                                </label>
                                <label className="flex flex-col gap-1 w-full">
                                    Description
                                    <input
                                        id="server-description"
                                        name="server-description"
                                        disabled={isValidating}
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                    />
                                </label>
                                {!isBuildServer && (
                                    <label className="flex flex-col gap-1 w-full">
                                        Wildcard Domain
                                        <input
                                            id="server-wildcard-domain"
                                            name="server-wildcard-domain"
                                            disabled={isValidating}
                                            placeholder="https://example.com"
                                            value={data.wildcardDomain}
                                            onChange={(e) => setData('wildcardDomain', e.target.value)}
                                        />
                                        {errors.wildcardDomain && <span className="text-error">{errors.wildcardDomain}</span>}
                                    </label>
                                )}
                            </div>
                            <div className="flex flex-col gap-2 w-full lg:flex-row">
                                <label className="flex flex-col gap-1 w-full">
                                    IP Address/Domain
                                    <input
                                        id="server-ip"
                                        name="server-ip"
                                        type="password"
                                        required
                                        disabled={isValidating}
                                        value={data.ip}
                                        onChange={(e) => setData('ip', e.target.value)}
                                    />
                                    {errors.ip && <span className="text-error">{errors.ip}</span>}
                                </label>
                                <label className="flex flex-col gap-1">
                                    User
                                    <input
                                        id="server-user"
                                        name="server-user"
                                        required
                                        disabled={isValidating}
                                        value={data.user}
                                        onChange={(e) => setData('user', e.target.value)}
                                    />
                                    {errors.user && <span className="text-error">{errors.user}</span>}
                                </label>
                                <label className="flex flex-col gap-1">
                                    Port
                                    <input
                                        id="server-port"
                                        name="server-port"
                                        type="number"
                                        required
                                        disabled={isValidating}
                                        value={data.port}
                                        onChange={(e) => setData('port', e.target.value)}
                                    />
                                    {errors.port && <span className="text-error">{errors.port}</span>}
                                </label>
                            </div>
                            <label className="flex flex-col gap-1 w-full lg:w-64">
                                SSH Connection Timeout (s)
                                <input
                                    id="server-connection-timeout"
                                    name="server-connection-timeout"
                                    type="number"
                                    min="1"
                                    max="300"
                                    required
                                    disabled={isValidating}
                                    value={data.connectionTimeout}
                                    onChange={(e) => setData('connectionTimeout', e.target.value)}
                                />
                                {errors.connectionTimeout && <span className="text-error">{errors.connectionTimeout}</span>}
                            </label>
                            <label className="flex flex-col gap-1 w-full lg:w-64">
                                Server Timezone
                                <select
                                    id="server-timezone"
                                    name="server-timezone"
                                    disabled={isValidating}
                                    value={data.serverTimezone}
                                    onChange={(e) => setData('serverTimezone', e.target.value)}
                                >
                                    <option value="">Select Server Timezone</option>
                                    {timezones.map((tz) => (
                                        <option key={tz} value={tz}>
                                            {tz}
                                        </option>
                                    ))}
                                </select>
                                {errors.serverTimezone && <span className="text-error">{errors.serverTimezone}</span>}
                            </label>

                            {!server.isLocalhost && (
                                <label className="flex items-center gap-2 w-full sm:w-96">
                                    <input
                                        id="server-is-build-server"
                                        type="checkbox"
                                        disabled={server.isBuildServerLocked || isValidating}
                                        checked={isBuildServer}
                                        onChange={(e) => toggleBuildServer(e.target.checked)}
                                    />
                                    Use it as a build server?
                                    {server.isBuildServerLocked && (
                                        <span className="text-xs dark:text-neutral-500">(locked — this server has defined resources)</span>
                                    )}
                                </label>
                            )}
                        </div>
                    </form>

                    {server.isFunctional && (
                        <div className="pt-6">
                            <div className="flex items-center gap-2 mb-3">
                                <h3>Server Details</h3>
                                {server.serverMetadata && (
                                    <button type="button" title="Refresh server details" onClick={refreshMetadata}>
                                        ⟳
                                    </button>
                                )}
                            </div>
                            {server.serverMetadata ? (
                                <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm lg:grid-cols-3">
                                    <div>
                                        <span className="font-medium dark:text-neutral-400">OS:</span> {server.serverMetadata.os ?? 'N/A'}
                                    </div>
                                    <div>
                                        <span className="font-medium dark:text-neutral-400">Arch:</span> {server.serverMetadata.arch ?? 'N/A'}
                                    </div>
                                    <div>
                                        <span className="font-medium dark:text-neutral-400">Kernel:</span> {server.serverMetadata.kernel ?? 'N/A'}
                                    </div>
                                    <div>
                                        <span className="font-medium dark:text-neutral-400">CPU Cores:</span> {server.serverMetadata.cpus ?? 'N/A'}
                                    </div>
                                    <div>
                                        <span className="font-medium dark:text-neutral-400">RAM:</span>{' '}
                                        {server.serverMetadata.memory_bytes
                                            ? `${Math.round((server.serverMetadata.memory_bytes / 1073741824) * 10) / 10} GB`
                                            : 'N/A'}
                                    </div>
                                    <div>
                                        <span className="font-medium dark:text-neutral-400">Up Since:</span>{' '}
                                        {server.serverMetadata.uptime_since ?? 'N/A'}
                                    </div>
                                </div>
                            ) : (
                                <button type="button" onClick={refreshMetadata}>
                                    Fetch Server Details
                                </button>
                            )}
                        </div>
                    )}

                    {!server.hetznerServerId && availableHetznerTokens.length > 0 && (
                        <div className="pt-6">
                            <h3>Link to Hetzner Cloud</h3>
                            <p className="pb-4 text-sm dark:text-neutral-400">
                                Link this server to a Hetzner Cloud instance to enable power controls and status monitoring.
                            </p>
                            <div className="flex flex-wrap gap-4 items-end">
                                <label className="flex flex-col gap-1 w-72">
                                    Hetzner Token
                                    <select
                                        id="server-hetzner-token"
                                        name="server-hetzner-token"
                                        value={selectedTokenId}
                                        onChange={(e) => setSelectedTokenId(e.target.value)}
                                    >
                                        <option value="">Select a token...</option>
                                        {availableHetznerTokens.map((token) => (
                                            <option key={token.id} value={token.id}>
                                                {token.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="flex flex-col gap-1 w-48">
                                    Server ID
                                    <input
                                        id="server-hetzner-server-id"
                                        name="server-hetzner-server-id"
                                        placeholder="e.g., 12345678"
                                        value={manualHetznerServerId}
                                        onChange={(e) => setManualHetznerServerId(e.target.value)}
                                    />
                                </label>
                                <button type="button" onClick={searchById} disabled={searching}>
                                    {searching ? 'Searching…' : 'Search by ID'}
                                </button>
                                <div className="self-end pb-2 text-sm dark:text-neutral-500">OR</div>
                                <button type="button" onClick={searchByIp} disabled={searching}>
                                    {searching ? 'Searching…' : 'Search by IP'}
                                </button>
                            </div>

                            {hetznerSearchError && (
                                <div className="mt-4 p-4 border border-red-500 rounded-md text-red-600 dark:text-red-400">{hetznerSearchError}</div>
                            )}

                            {hetznerNoMatchFound && (
                                <div className="mt-4 p-4 border border-yellow-500 rounded-md text-yellow-600 dark:text-yellow-400">
                                    {manualHetznerServerId
                                        ? `No Hetzner server found with ID: ${manualHetznerServerId}`
                                        : `No Hetzner server found matching IP: ${server.ip}`}
                                </div>
                            )}

                            {matchedServer && (
                                <div className="mt-4 p-4 border border-green-500 rounded-md">
                                    <h4 className="font-semibold text-green-700 dark:text-green-400 mb-2">Match Found!</h4>
                                    <div className="grid grid-cols-2 gap-2 text-sm mb-4">
                                        <div>
                                            <span className="font-medium">Name:</span> {matchedServer.name}
                                        </div>
                                        <div>
                                            <span className="font-medium">ID:</span> {matchedServer.id}
                                        </div>
                                        <div>
                                            <span className="font-medium">Status:</span>{' '}
                                            {matchedServer.status?.charAt(0).toUpperCase() + matchedServer.status?.slice(1)}
                                        </div>
                                        <div>
                                            <span className="font-medium">Type:</span> {matchedServer.server_type?.name ?? 'Unknown'}
                                        </div>
                                    </div>
                                    <button type="button" onClick={linkToHetzner}>
                                        Link This Server
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {showLocalhostConfirm && (
                <PasswordConfirmModal
                    title="Confirm Server Settings Change?"
                    actions={['If you misconfigure the server, you could lose a lot of functionalities of Coolify.']}
                    withPassword={false}
                    action={{ url: urls.update, method: 'patch', data }}
                    onClose={() => setShowLocalhostConfirm(false)}
                    onDone={() => setShowLocalhostConfirm(false)}
                />
            )}
        </div>
    );
}
