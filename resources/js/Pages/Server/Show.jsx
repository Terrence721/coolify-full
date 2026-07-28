import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
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

/**
 * React port of App\Livewire\Server\Show — the "General" tab, and the last full-page Livewire
 * component in the whole migration. See ServerShowController's docblock for the two findings
 * that shrank this port's scope (dead Sentinel/metrics UI on this page; swarm toggles live on
 * the separate /server/{uuid}/swarm page, not here) and why the validate/install flow reuses
 * ServerValidationService instead of a third implementation.
 */
export default function Show({ serverNavbar, sidebar, server, timezones, isCloud, urls }) {
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

    const [isValidating, setIsValidating] = useState(server.isValidating);
    const [installActivity, setInstallActivity] = useState(null);
    const [validateError, setValidateError] = useState(null);
    const [attempt, setAttempt] = useState(0);
    const [validating, setValidating] = useState(false);

    useTeamChannel(['.ServerValidated'], (eventName, payload) => {
        if (payload?.serverUuid && payload.serverUuid !== server.uuid) return;
        router.reload({ only: ['server'] });
    });

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

    const needsValidation = (!server.isReachable || !server.isUsable) && server.id !== 0 && !isValidating;

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <form onSubmit={submitGeneral} className="flex flex-col">
                        <div className="flex items-center gap-2 flex-wrap">
                            <h2>General</h2>
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
