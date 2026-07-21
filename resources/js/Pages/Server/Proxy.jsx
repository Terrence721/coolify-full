import { router } from '@inertiajs/react';
import { useState } from 'react';
import MonacoEditor from '../../Components/MonacoEditor';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

function toast(title, options) {
    if (typeof window.toast === 'function') {
        window.toast(title, options);
    }
}

export default function Proxy({
    serverNavbar,
    sidebar,
    canUpdate,
    selectedProxy,
    proxyStatus,
    proxyOutOfSync,
    proxySettings: initialProxySettings,
    configurationFilePath,
    generateExactLabels: initialGenerateExactLabels,
    redirectEnabled: initialRedirectEnabled,
    redirectUrl: initialRedirectUrl,
    detectedTraefikVersion,
    latestTraefikVersion,
    isTraefikOutdated,
    newerTraefikBranchAvailable,
    selectProxyUrl,
    resetProxySelectionUrl,
    instantSaveUrl,
    instantSaveRedirectUrl,
    submitUrl,
    resetConfigurationUrl,
}) {
    const [proxySettings, setProxySettings] = useState(initialProxySettings ?? '');
    const [generateExactLabels, setGenerateExactLabels] = useState(initialGenerateExactLabels);
    const [redirectEnabled, setRedirectEnabled] = useState(initialRedirectEnabled);
    const [redirectUrl, setRedirectUrl] = useState(initialRedirectUrl ?? '');
    const [showSwitchModal, setShowSwitchModal] = useState(false);
    const [showResetModal, setShowResetModal] = useState(false);
    const [resetConfirmation, setResetConfirmation] = useState('');
    const [warningsDismissed, setWarningsDismissed] = useState(
        () => localStorage.getItem(`callout-dismissed-traefik-warnings-${serverNavbar.server.id}`) === 'true',
    );
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    const isTraefik = selectedProxy === 'TRAEFIK';
    const proxyTitle = isTraefik ? 'Traefik (Coolify Proxy)' : 'Caddy (Coolify Proxy)';
    const canSwitchDirectly = proxyStatus === 'exited' || proxyStatus === 'removing';

    function dismissWarnings() {
        setWarningsDismissed(true);
        localStorage.setItem(`callout-dismissed-traefik-warnings-${serverNavbar.server.id}`, 'true');
    }

    function showWarnings() {
        setWarningsDismissed(false);
        localStorage.removeItem(`callout-dismissed-traefik-warnings-${serverNavbar.server.id}`);
    }

    function selectProxy(type) {
        router.post(selectProxyUrl, { proxy_type: type }, { preserveScroll: true });
    }

    function switchProxy() {
        setShowSwitchModal(false);
        router.post(resetProxySelectionUrl, {}, { preserveScroll: true });
    }

    function blockedSwitchProxy() {
        toast('Error', { type: 'danger', description: 'Currently running proxy must be stopped before switching proxy' });
    }

    function toggleGenerateExactLabels(e) {
        const value = e.target.checked;
        setGenerateExactLabels(value);
        router.post(instantSaveUrl, { generateExactLabels: value }, { preserveScroll: true });
    }

    function toggleRedirectEnabled(e) {
        const value = e.target.checked;
        setRedirectEnabled(value);
        router.post(instantSaveRedirectUrl, { redirectEnabled: value }, { preserveScroll: true });
    }

    function submitConfiguration(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        router.post(
            submitUrl,
            { proxySettings, redirectUrl },
            {
                preserveScroll: true,
                onError: (validationErrors) => setErrors(validationErrors),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    function resetConfiguration() {
        if (resetConfirmation !== serverNavbar.server.name) return;
        setShowResetModal(false);
        setResetConfirmation('');
        router.post(resetConfigurationUrl, {}, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    {!selectedProxy ? (
                        <div>
                            <h2>Configuration</h2>
                            <div className="subtitle">Select a proxy you would like to use on this server.</div>
                            {canUpdate ? (
                                <div className="grid gap-4 max-w-xs">
                                    <button type="button" onClick={() => selectProxy('NONE')}>
                                        Custom (None)
                                    </button>
                                    <button type="button" onClick={() => selectProxy('TRAEFIK')}>
                                        Traefik
                                    </button>
                                    <button type="button" onClick={() => selectProxy('CADDY')}>
                                        Caddy
                                    </button>
                                </div>
                            ) : (
                                <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded mb-4">
                                    You don&apos;t have permission to configure proxy settings for this server.
                                </div>
                            )}
                        </div>
                    ) : selectedProxy === 'NONE' ? (
                        <div>
                            <div className="flex items-center gap-2">
                                <h2>Configuration</h2>
                                {canUpdate && (
                                    <button type="button" onClick={() => setShowSwitchModal(true)}>
                                        Switch Proxy
                                    </button>
                                )}
                            </div>
                            <div className="pt-2 pb-4">Custom (None) Proxy Selected</div>
                        </div>
                    ) : (
                        <form onSubmit={submitConfiguration}>
                            <div className="flex items-center gap-2">
                                <h2>Configuration</h2>
                                {canUpdate &&
                                    (canSwitchDirectly ? (
                                        <button type="button" onClick={() => setShowSwitchModal(true)}>
                                            Switch Proxy
                                        </button>
                                    ) : (
                                        <button type="button" onClick={blockedSwitchProxy}>
                                            Switch Proxy
                                        </button>
                                    ))}
                                <button type="submit" disabled={submitting}>
                                    Save
                                </button>
                            </div>
                            <div className="pb-4">Configure your proxy settings and advanced options.</div>

                            {proxyOutOfSync && (
                                <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded my-4">
                                    The saved proxy configuration differs from the currently running configuration. Restart the proxy to apply your
                                    changes.
                                </div>
                            )}

                            <h3>Advanced</h3>
                            <div className="pb-6 w-full sm:w-96">
                                <label className="flex items-center gap-2">
                                    <input
                                        id="proxy-generate-exact-labels"
                                        type="checkbox"
                                        disabled={!canUpdate}
                                        checked={generateExactLabels}
                                        onChange={toggleGenerateExactLabels}
                                    />
                                    Generate labels only for {isTraefik ? 'Traefik' : 'Caddy'}
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        id="proxy-redirect-enabled"
                                        type="checkbox"
                                        disabled={!canUpdate}
                                        checked={redirectEnabled}
                                        onChange={toggleRedirectEnabled}
                                    />
                                    Override default request handler
                                </label>
                                {redirectEnabled && (
                                    <label className="flex flex-col gap-1 pt-2">
                                        Redirect to (optional)
                                        <input
                                            id="proxy-redirect-url"
                                            name="proxy-redirect-url"
                                            disabled={!canUpdate}
                                            placeholder="https://app.coolify.io"
                                            value={redirectUrl}
                                            onChange={(e) => setRedirectUrl(e.target.value)}
                                        />
                                        {errors.redirectUrl && <span className="text-error">{errors.redirectUrl}</span>}
                                    </label>
                                )}
                            </div>

                            {(isTraefik || selectedProxy === 'CADDY') && (
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3>{proxyTitle}</h3>
                                        {canUpdate && proxySettings && (
                                            <button type="button" onClick={() => setShowResetModal(true)}>
                                                Reset Configuration
                                            </button>
                                        )}
                                        {isTraefik && warningsDismissed && (
                                            <button type="button" onClick={showWarnings} title="Show Traefik warnings">
                                                ⚠
                                            </button>
                                        )}
                                    </div>

                                    {isTraefik && !warningsDismissed && (
                                        <div className="flex flex-col gap-2 my-4">
                                            {detectedTraefikVersion === 'latest' ? (
                                                <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded">
                                                    <div className="font-bold">Using &apos;latest&apos; Traefik Tag</div>
                                                    Your proxy container is running the <span className="font-mono">latest</span> tag. While this
                                                    ensures you always have the newest version, it may introduce unexpected breaking changes.
                                                    <br />
                                                    <br />
                                                    <strong>Recommendation:</strong> Pin to a specific version (e.g.,{' '}
                                                    <span className="font-mono">traefik:{latestTraefikVersion}</span>) to ensure stability and
                                                    predictable updates.
                                                    <div className="pt-2">
                                                        <button type="button" onClick={dismissWarnings}>
                                                            Dismiss
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : (
                                                isTraefikOutdated && (
                                                    <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded">
                                                        <div className="font-bold">Traefik Patch Update Available</div>
                                                        Your Traefik proxy container is running version{' '}
                                                        <span className="font-mono">v{detectedTraefikVersion}</span>, but version{' '}
                                                        <span className="font-mono">{latestTraefikVersion}</span> is available.
                                                        <br />
                                                        <br />
                                                        <strong>Recommendation:</strong> Update to the latest patch version for security fixes and bug
                                                        fixes. Please test in a non-production environment first.
                                                        <div className="pt-2">
                                                            <button type="button" onClick={dismissWarnings}>
                                                                Dismiss
                                                            </button>
                                                        </div>
                                                    </div>
                                                )
                                            )}
                                            {newerTraefikBranchAvailable && (
                                                <div className="p-3 border border-sky-500/30 bg-sky-500/10 text-sm rounded">
                                                    <div className="font-bold">New Minor Traefik Version Available</div>A new minor version of Traefik
                                                    is available: <span className="font-mono">{newerTraefikBranchAvailable}</span>
                                                    <br />
                                                    <br />
                                                    You are currently running <span className="font-mono">v{detectedTraefikVersion}</span>. Upgrading
                                                    to <span className="font-mono">{newerTraefikBranchAvailable}</span> will give you access to new
                                                    features and improvements.
                                                    <br />
                                                    <br />
                                                    <strong>Important:</strong> Before upgrading to a new minor version, please read the{' '}
                                                    <a
                                                        href="https://github.com/traefik/traefik/releases"
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="underline"
                                                    >
                                                        Traefik changelog
                                                    </a>{' '}
                                                    to understand breaking changes and new features.
                                                    <br />
                                                    <br />
                                                    <strong>Recommendation:</strong> Test the upgrade in a non-production environment first.
                                                    <div className="pt-2">
                                                        <button type="button" onClick={dismissWarnings}>
                                                            Dismiss
                                                        </button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            {proxySettings && (
                                <div className="flex flex-col gap-2 pt-2">
                                    <label>Configuration file ( {configurationFilePath} )</label>
                                    <MonacoEditor value={proxySettings} onChange={setProxySettings} language="yaml" readOnly={!canUpdate} />
                                </div>
                            )}
                        </form>
                    )}
                </div>
            </div>

            {showSwitchModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowSwitchModal(false)} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <h3 className="text-2xl font-bold pb-4">Confirm Proxy Switching?</h3>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>Custom proxy configurations may be reset to their default settings.</li>
                        </ul>
                        <div className="pb-4 text-sm text-warning">
                            This operation may cause issues. Please refer to the guide{' '}
                            <a
                                href="https://coolify.io/docs/knowledge-base/server/proxies#switch-between-proxies"
                                target="_blank"
                                rel="noreferrer"
                                className="underline"
                            >
                                switching between proxies
                            </a>{' '}
                            before proceeding!
                        </div>
                        <div className="flex gap-2 justify-end">
                            <button type="button" onClick={() => setShowSwitchModal(false)}>
                                Cancel
                            </button>
                            <button type="button" onClick={switchProxy}>
                                Switch Proxy
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {showResetModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div
                        className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs"
                        onClick={() => {
                            setShowResetModal(false);
                            setResetConfirmation('');
                        }}
                    />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <h3 className="text-2xl font-bold pb-4">Reset Proxy Configuration?</h3>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>Reset proxy configuration to default settings</li>
                            <li>All custom configurations will be lost</li>
                            <li>Custom ports and entrypoints will be removed</li>
                        </ul>
                        <label className="flex flex-col gap-1 pb-4">
                            Please confirm by entering the server name below
                            <input
                                id="proxy-reset-confirm"
                                name="proxy-reset-confirm"
                                value={resetConfirmation}
                                onChange={(e) => setResetConfirmation(e.target.value)}
                                placeholder={serverNavbar.server.name}
                            />
                        </label>
                        <div className="flex gap-2 justify-end">
                            <button
                                type="button"
                                onClick={() => {
                                    setShowResetModal(false);
                                    setResetConfirmation('');
                                }}
                            >
                                Cancel
                            </button>
                            <button type="button" disabled={resetConfirmation !== serverNavbar.server.name} onClick={resetConfiguration}>
                                Reset Configuration
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
