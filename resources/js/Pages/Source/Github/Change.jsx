import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function DeleteGithubAppModal({ githubApp, hasApplications, deleteUrl, onClose }) {
    const [confirmation, setConfirmation] = useState('');

    function destroy() {
        if (confirmation !== githubApp.name) return;
        router.delete(deleteUrl);
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <h3 className="text-2xl font-bold pb-4">Confirm GitHub App Deletion?</h3>
                {hasApplications ? (
                    <div className="pb-4 text-sm text-warning">
                        This source is being used by an application. Please delete all applications first.
                    </div>
                ) : (
                    <>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>The selected GitHub App will be permanently deleted.</li>
                        </ul>
                        <label className="flex flex-col gap-1 pb-4">
                            Please confirm by entering the GitHub App name below
                            <input value={confirmation} onChange={(e) => setConfirmation(e.target.value)} placeholder={githubApp.name} />
                        </label>
                    </>
                )}
                <div className="flex gap-2 justify-end">
                    <button type="button" onClick={onClose}>
                        Cancel
                    </button>
                    {!hasApplications && (
                        <button type="button" disabled={confirmation !== githubApp.name} onClick={destroy}>
                            Permanently Delete
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

function createGithubApp({
    webhookEndpoint,
    useCustomWebhookEndpoint,
    customWebhookEndpoint,
    previewDeploymentPermissions,
    githubApp,
    name,
    manifestState,
    isDev,
    devWebhookUrl,
}) {
    const selectedEndpoint = webhookEndpoint ? webhookEndpoint.trim() : '';
    const customEndpoint = customWebhookEndpoint ? customWebhookEndpoint.trim() : '';
    if (useCustomWebhookEndpoint && !customEndpoint) {
        alert('Please enter a custom webhook endpoint.');
        return;
    }
    if (!useCustomWebhookEndpoint && !selectedEndpoint) {
        alert('Please enter a webhook endpoint.');
        return;
    }
    let baseUrl = (useCustomWebhookEndpoint ? customEndpoint : selectedEndpoint).replace(/\/+$/, '');
    if (isDev && devWebhookUrl) {
        baseUrl = devWebhookUrl;
    }
    const webhookBaseUrl = `${baseUrl}/webhooks`;
    const path = githubApp.organization ? `organizations/${githubApp.organization}/settings/apps/new` : 'settings/apps/new';
    const defaultPermissions = { contents: 'read', metadata: 'read', emails: 'read', administration: 'read' };
    const defaultEvents = ['push'];
    if (previewDeploymentPermissions) {
        defaultPermissions.pull_requests = 'write';
        defaultEvents.push('pull_request');
    }

    const data = {
        name,
        url: baseUrl,
        hook_attributes: { url: `${webhookBaseUrl}/source/github/events`, active: true },
        redirect_url: `${webhookBaseUrl}/source/github/redirect`,
        callback_urls: [`${baseUrl}/login/github/app`],
        public: false,
        request_oauth_on_install: false,
        setup_url: `${webhookBaseUrl}/source/github/install`,
        setup_on_update: true,
        default_permissions: defaultPermissions,
        default_events: defaultEvents,
    };

    const form = document.createElement('form');
    form.setAttribute('method', 'post');
    form.setAttribute('action', `${githubApp.htmlUrl}/${path}?state=${manifestState}`);
    const input = document.createElement('input');
    input.setAttribute('id', 'manifest');
    input.setAttribute('name', 'manifest');
    input.setAttribute('type', 'hidden');
    input.setAttribute('value', JSON.stringify(data));
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

export default function Change({
    githubApp,
    activeTab,
    isCloud,
    isDev,
    fqdn,
    ipv4,
    ipv6,
    appUrl,
    webhookEndpoint: defaultWebhookEndpoint,
    manifestState,
    devWebhookUrl,
    privateKeys,
    applications,
    canUpdate,
    canDelete,
    canCreate,
    installationPath,
    permissionsPath,
    nameUpdatePath,
    showUrl,
    permissionsUrl,
    resourcesUrl,
    updateUrl,
    updateNameUrl,
    checkPermissionsUrl,
    instantSaveUrl,
    createManualUrl,
    deleteUrl,
}) {
    const { errors } = usePage().props;
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const [search, setSearch] = useState('');
    const [webhookEndpoint, setWebhookEndpoint] = useState(defaultWebhookEndpoint);
    const [useCustomWebhookEndpoint, setUseCustomWebhookEndpoint] = useState(false);
    const [customWebhookEndpoint, setCustomWebhookEndpoint] = useState('');
    const [previewDeploymentPermissions, setPreviewDeploymentPermissions] = useState(true);

    const { data, setData, put, processing } = useForm({
        name: githubApp.name,
        organization: githubApp.organization ?? '',
        apiUrl: githubApp.apiUrl,
        htmlUrl: githubApp.htmlUrl,
        customUser: githubApp.customUser,
        customPort: githubApp.customPort,
        appId: githubApp.appId,
        installationId: githubApp.installationId,
        clientId: githubApp.clientId ?? '',
        clientSecret: githubApp.clientSecret ?? '',
        webhookSecret: githubApp.webhookSecret ?? '',
        isSystemWide: githubApp.isSystemWide,
        privateKeyId: githubApp.privateKeyId ?? '',
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function toggleSystemWide(checked) {
        setData('isSystemWide', checked);
        router.post(instantSaveUrl, { isSystemWide: checked }, { preserveScroll: true });
    }

    function updateName() {
        router.post(updateNameUrl, {}, { preserveScroll: true });
    }

    function checkPermissions() {
        router.post(checkPermissionsUrl, {}, { preserveScroll: true });
    }

    function createManual() {
        router.post(createManualUrl);
    }

    const filteredApplications = applications.filter((app) => {
        if (!search) return true;
        const term = search.toLowerCase();
        return (
            app.projectName.toLowerCase().includes(term) ||
            app.environmentName.toLowerCase().includes(term) ||
            app.name.toLowerCase().includes(term) ||
            app.type.toLowerCase().includes(term)
        );
    });

    const hasApplications = applications.length > 0;

    if (!githubApp.appId) {
        return (
            <div>
                <div className="flex flex-col sm:flex-row sm:items-center gap-2 pb-4">
                    <h1>GitHub App</h1>
                    {canDelete && (
                        <button type="button" onClick={() => setShowDeleteModal(true)}>
                            Delete
                        </button>
                    )}
                </div>
                <div className="flex items-center justify-center min-h-[calc(100vh-12rem)]">
                    <div className="mx-auto grid w-full max-w-5xl grid-cols-1 gap-4 lg:grid-cols-2">
                        {canCreate ? (
                            <>
                                <section className="box-without-bg flex-col gap-4 p-6 h-full">
                                    <div className="flex flex-col gap-4 text-left h-full">
                                        <h3 className="text-xl font-bold">Automated Installation (Recommended)</h3>
                                        <p className="text-sm dark:text-neutral-400">
                                            Register a GitHub App via GitHub's manifest flow. Permissions and webhooks are pre-configured.
                                        </p>
                                        {(!isCloud || isDev) && (
                                            <div className="flex flex-col gap-3 pt-4 border-t border-neutral-200 dark:border-coolgray-400">
                                                <label className="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={useCustomWebhookEndpoint}
                                                        onChange={(e) => setUseCustomWebhookEndpoint(e.target.checked)}
                                                    />
                                                    Use custom webhook endpoint
                                                </label>
                                                {!useCustomWebhookEndpoint ? (
                                                    <select value={webhookEndpoint} onChange={(e) => setWebhookEndpoint(e.target.value)}>
                                                        {fqdn && <option value={fqdn}>Use {fqdn}</option>}
                                                        {ipv4 && <option value={ipv4}>Use {ipv4}</option>}
                                                        {ipv6 && <option value={ipv6}>Use {ipv6}</option>}
                                                        {appUrl && <option value={appUrl}>Use {appUrl}</option>}
                                                    </select>
                                                ) : (
                                                    <input
                                                        type="url"
                                                        value={customWebhookEndpoint}
                                                        onChange={(e) => setCustomWebhookEndpoint(e.target.value)}
                                                        placeholder="https://coolify.example.com"
                                                    />
                                                )}
                                            </div>
                                        )}
                                        <div className="flex w-full flex-col gap-2">
                                            <label className="flex items-center gap-2">
                                                <input type="checkbox" checked disabled />
                                                Mandatory (Contents: read, Metadata: read, Email: read)
                                            </label>
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={previewDeploymentPermissions}
                                                    onChange={(e) => setPreviewDeploymentPermissions(e.target.checked)}
                                                />
                                                Preview Deployments (Pull Request: read &amp; write)
                                            </label>
                                        </div>
                                        <button
                                            type="button"
                                            className="w-full justify-center"
                                            onClick={() =>
                                                createGithubApp({
                                                    webhookEndpoint,
                                                    useCustomWebhookEndpoint,
                                                    customWebhookEndpoint,
                                                    previewDeploymentPermissions,
                                                    githubApp,
                                                    name: data.name,
                                                    manifestState,
                                                    isDev,
                                                    devWebhookUrl,
                                                })
                                            }
                                        >
                                            Register Now
                                        </button>
                                    </div>
                                </section>

                                <section className="box-without-bg flex-col gap-4 p-6 h-full">
                                    <div className="flex flex-col gap-4 text-left h-full">
                                        <h3 className="text-xl font-bold">Manual Installation</h3>
                                        <p className="text-sm dark:text-neutral-400">
                                            Fill the GitHub App form manually. For self-hosted GitHub Enterprise or custom permission setups.
                                        </p>
                                        <button type="button" className="w-full justify-center" onClick={createManual}>
                                            Continue
                                        </button>
                                    </div>
                                </section>
                            </>
                        ) : (
                            <div className="pb-10">You don't have permission to create new GitHub Apps. Please contact your team administrator.</div>
                        )}
                    </div>
                </div>

                {showDeleteModal && (
                    <DeleteGithubAppModal
                        githubApp={githubApp}
                        hasApplications={hasApplications}
                        deleteUrl={deleteUrl}
                        onClose={() => setShowDeleteModal(false)}
                    />
                )}
            </div>
        );
    }

    return (
        <div>
            <form onSubmit={submit}>
                <div className="flex flex-col sm:flex-row sm:items-center gap-2">
                    <h1>GitHub App</h1>
                    <div className="flex gap-2">
                        {githubApp.installationId && canUpdate && (
                            <button type="submit" disabled={processing || activeTab !== 'general'}>
                                Save
                            </button>
                        )}
                        {canDelete && (
                            <button type="button" onClick={() => setShowDeleteModal(true)}>
                                Delete
                            </button>
                        )}
                    </div>
                </div>
                <div className="subtitle">Your Private GitHub App for private repositories.</div>

                {!githubApp.installationId ? (
                    <>
                        <div className="mb-10 rounded-sm alert-error">
                            You must complete this step before you can use this source!
                        </div>
                        <a className="items-center justify-center coolbox" href={installationPath}>
                            Install Repositories on GitHub
                        </a>
                    </>
                ) : (
                    <>
                        <div className="navbar-main">
                            <nav className="flex shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
                                <a className={activeTab === 'general' ? 'dark:text-white' : ''} href={showUrl}>
                                    General
                                </a>
                                <a className={activeTab === 'permissions' ? 'dark:text-white' : ''} href={permissionsUrl}>
                                    Permissions
                                </a>
                                <a className={activeTab === 'resources' ? 'dark:text-white' : ''} href={resourcesUrl}>
                                    Resources
                                </a>
                            </nav>
                        </div>

                        <div className="pt-4">
                            {activeTab === 'general' && (
                                <div className="flex flex-col gap-2">
                                    <div className="flex flex-col sm:flex-row items-start sm:items-end gap-2 w-full">
                                        <label className="flex flex-col gap-1">
                                            App Name
                                            <input disabled={!canUpdate} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                            {errors.name && <span className="text-error">{errors.name}</span>}
                                        </label>
                                        {canUpdate && (
                                            <button type="button" onClick={updateName}>
                                                Sync Name
                                            </button>
                                        )}
                                        {canUpdate && (
                                            <a href={nameUpdatePath} target="_blank" rel="noreferrer">
                                                Rename
                                            </a>
                                        )}
                                        {canUpdate && (
                                            <a href={installationPath}>Update Repositories</a>
                                        )}
                                    </div>
                                    <label className="flex flex-col gap-1">
                                        Organization
                                        <input
                                            disabled={!canUpdate}
                                            placeholder="If empty, personal user will be used"
                                            value={data.organization}
                                            onChange={(e) => setData('organization', e.target.value)}
                                        />
                                    </label>
                                    {!isCloud && (
                                        <>
                                            <label className="flex items-center gap-2 w-48">
                                                <input
                                                    type="checkbox"
                                                    disabled={!canUpdate}
                                                    checked={data.isSystemWide}
                                                    onChange={(e) => toggleSystemWide(e.target.checked)}
                                                />
                                                System Wide?
                                            </label>
                                            {data.isSystemWide && (
                                                <div className="alert-warning">
                                                    System-wide GitHub Apps are shared across all teams on this Coolify instance. This means any
                                                    team can use this GitHub App to deploy applications from your repositories. For better security
                                                    and isolation, it's recommended to create team-specific GitHub Apps instead.
                                                </div>
                                            )}
                                        </>
                                    )}
                                    <div className="flex flex-col sm:flex-row gap-2">
                                        <label className="flex flex-col gap-1 w-full">
                                            HTML Url
                                            <input disabled={!canUpdate} value={data.htmlUrl} onChange={(e) => setData('htmlUrl', e.target.value)} />
                                            {errors.htmlUrl && <span className="text-error">{errors.htmlUrl}</span>}
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            API Url
                                            <input disabled={!canUpdate} value={data.apiUrl} onChange={(e) => setData('apiUrl', e.target.value)} />
                                            {errors.apiUrl && <span className="text-error">{errors.apiUrl}</span>}
                                        </label>
                                    </div>
                                    <div className="flex flex-col sm:flex-row gap-2">
                                        <label className="flex flex-col gap-1 w-full">
                                            User
                                            <input
                                                required
                                                disabled={!canUpdate}
                                                value={data.customUser}
                                                onChange={(e) => setData('customUser', e.target.value)}
                                            />
                                            {errors.customUser && <span className="text-error">{errors.customUser}</span>}
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Port
                                            <input
                                                type="number"
                                                required
                                                disabled={!canUpdate}
                                                value={data.customPort}
                                                onChange={(e) => setData('customPort', e.target.value)}
                                            />
                                            {errors.customPort && <span className="text-error">{errors.customPort}</span>}
                                        </label>
                                    </div>
                                    <div className="flex flex-col sm:flex-row gap-2">
                                        <label className="flex flex-col gap-1 w-full">
                                            App Id
                                            <input
                                                type="number"
                                                required
                                                disabled={!canUpdate}
                                                value={data.appId ?? ''}
                                                onChange={(e) => setData('appId', e.target.value)}
                                            />
                                            {errors.appId && <span className="text-error">{errors.appId}</span>}
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Installation Id
                                            <input
                                                type="number"
                                                required
                                                disabled={!canUpdate}
                                                value={data.installationId ?? ''}
                                                onChange={(e) => setData('installationId', e.target.value)}
                                            />
                                            {errors.installationId && <span className="text-error">{errors.installationId}</span>}
                                        </label>
                                    </div>
                                    <div className="flex flex-col sm:flex-row gap-2">
                                        <label className="flex flex-col gap-1 w-full">
                                            Client Id
                                            <input
                                                type="password"
                                                required
                                                disabled={!canUpdate}
                                                value={data.clientId}
                                                onChange={(e) => setData('clientId', e.target.value)}
                                            />
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Client Secret
                                            <input
                                                type="password"
                                                required
                                                disabled={!canUpdate}
                                                value={data.clientSecret}
                                                onChange={(e) => setData('clientSecret', e.target.value)}
                                            />
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Webhook Secret
                                            <input
                                                type="password"
                                                required
                                                disabled={!canUpdate}
                                                value={data.webhookSecret}
                                                onChange={(e) => setData('webhookSecret', e.target.value)}
                                            />
                                        </label>
                                    </div>
                                    <label className="flex flex-col gap-1">
                                        Private Key
                                        <select
                                            required
                                            disabled={!canUpdate}
                                            value={data.privateKeyId}
                                            onChange={(e) => setData('privateKeyId', e.target.value)}
                                        >
                                            {!data.privateKeyId && <option value="">Select a private key</option>}
                                            {privateKeys.map((key) => (
                                                <option key={key.id} value={key.id}>
                                                    {key.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                </div>
                            )}

                            {activeTab === 'permissions' && (
                                <div className="flex flex-col gap-2">
                                    <div className="flex flex-col sm:flex-row items-start sm:items-end gap-2">
                                        <h2>Permissions</h2>
                                        <button type="button" onClick={checkPermissions}>
                                            Refetch
                                        </button>
                                        <a href={permissionsPath} target="_blank" rel="noreferrer">
                                            Update
                                        </a>
                                    </div>
                                    <div className="flex flex-col sm:flex-row gap-2">
                                        <label className="flex flex-col gap-1 w-full">
                                            Content
                                            <input readOnly placeholder="N/A" value={githubApp.contents ?? ''} />
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Metadata
                                            <input readOnly placeholder="N/A" value={githubApp.metadata ?? ''} />
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Pull Request
                                            <input readOnly placeholder="N/A" value={githubApp.pullRequests ?? ''} />
                                        </label>
                                    </div>
                                </div>
                            )}

                            {activeTab === 'resources' && (
                                <div className="flex flex-col">
                                    {applications.length === 0 ? (
                                        <div className="py-4 text-sm opacity-70">No resources are currently using this GitHub App.</div>
                                    ) : (
                                        <>
                                            <input
                                                placeholder="Search resources..."
                                                value={search}
                                                onChange={(e) => setSearch(e.target.value)}
                                            />
                                            <div className="overflow-x-auto pt-4">
                                                <table className="min-w-full">
                                                    <thead>
                                                        <tr>
                                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Project</th>
                                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Environment</th>
                                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Type</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y">
                                                        {filteredApplications.map((app) => (
                                                            <tr key={app.uuid} className="dark:hover:bg-coolgray-300 hover:bg-neutral-100">
                                                                <td className="px-5 py-4 text-sm whitespace-nowrap">{app.projectName}</td>
                                                                <td className="px-5 py-4 text-sm whitespace-nowrap">{app.environmentName}</td>
                                                                <td className="px-5 py-4 text-sm whitespace-nowrap">
                                                                    <a href={app.link}>{app.name}</a>
                                                                </td>
                                                                <td className="px-5 py-4 text-sm whitespace-nowrap">{app.type}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}
                        </div>
                    </>
                )}
            </form>

            {showDeleteModal && (
                <DeleteGithubAppModal
                    githubApp={githubApp}
                    hasApplications={hasApplications}
                    deleteUrl={deleteUrl}
                    onClose={() => setShowDeleteModal(false)}
                />
            )}
        </div>
    );
}
