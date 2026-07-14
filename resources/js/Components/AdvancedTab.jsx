import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * React port of App\Livewire\Project\Application\Advanced — a large collection of instant-save
 * checkboxes (Build/Container/Deployment/Git/Docker Compose/Proxy/Logs) plus three standalone
 * forms (Custom Container Name, Stop Grace Period, Max Restart Count) and a GPU section that
 * saves via its own explicit "Save" button rather than instant-save. Instant-save checkboxes
 * submit the *entire* current form state to one endpoint (instantSaveGeneral()'s established
 * pattern, not a field-scoped PATCH) — Livewire's `syncData(toModel: true)` behavior this port
 * replicates throughout the migration.
 */
function Checkbox({ label, helper, checked, onChange, disabled = false }) {
    return (
        <label className="flex items-start gap-2">
            <input type="checkbox" checked={checked} disabled={disabled} onChange={onChange} className="mt-1" />
            <span className="flex flex-col">
                <span>{label}</span>
                {helper && <span className="text-xs text-neutral-500" dangerouslySetInnerHTML={{ __html: helper }} />}
            </span>
        </label>
    );
}

export default function AdvancedTab({ advanced, advancedUrls, canUpdate }) {
    const [form, setForm] = useState({
        isForceHttpsEnabled: advanced.isForceHttpsEnabled,
        isGzipEnabled: advanced.isGzipEnabled,
        isStripprefixEnabled: advanced.isStripprefixEnabled,
        isLogDrainEnabled: advanced.isLogDrainEnabled,
        isGitSubmodulesEnabled: advanced.isGitSubmodulesEnabled,
        isGitLfsEnabled: advanced.isGitLfsEnabled,
        isGitShallowCloneEnabled: advanced.isGitShallowCloneEnabled,
        isPreviewDeploymentsEnabled: advanced.isPreviewDeploymentsEnabled,
        isPrDeploymentsPublicEnabled: advanced.isPrDeploymentsPublicEnabled,
        isAutoDeployEnabled: advanced.isAutoDeployEnabled,
        isGpuEnabled: advanced.isGpuEnabled,
        gpuDriver: advanced.gpuDriver ?? '',
        gpuCount: advanced.gpuCount ?? '',
        gpuDeviceIds: advanced.gpuDeviceIds ?? '',
        gpuOptions: advanced.gpuOptions ?? '',
        isBuildServerEnabled: advanced.isBuildServerEnabled,
        isConsistentContainerNameEnabled: advanced.isConsistentContainerNameEnabled,
        isRawComposeDeploymentEnabled: advanced.isRawComposeDeploymentEnabled,
        isConnectToDockerNetworkEnabled: advanced.isConnectToDockerNetworkEnabled,
        disableBuildCache: advanced.disableBuildCache,
        injectBuildArgsToDockerfile: advanced.injectBuildArgsToDockerfile,
        includeSourceCommitInBuild: advanced.includeSourceCommitInBuild,
    });
    const [customInternalName, setCustomInternalName] = useState(advanced.customInternalName ?? '');
    const [stopGracePeriod, setStopGracePeriod] = useState(advanced.stopGracePeriod ?? '');
    const [maxRestartCount, setMaxRestartCount] = useState(advanced.maxRestartCount);

    function instantSave(overrides) {
        const next = { ...form, ...overrides };
        setForm(next);
        router.patch(advancedUrls.instantSave, next, { preserveScroll: true });
    }

    function saveGpuSettings(e) {
        e.preventDefault();
        router.patch(advancedUrls.update, form, { preserveScroll: true });
    }

    function saveCustomName(e) {
        e.preventDefault();
        router.post(advancedUrls.customName, { customInternalName }, { preserveScroll: true });
    }

    function saveStopGracePeriod(e) {
        e.preventDefault();
        router.patch(advancedUrls.stopGracePeriod, { stopGracePeriod }, { preserveScroll: true });
    }

    function saveMaxRestartCount(e) {
        e.preventDefault();
        router.patch(advancedUrls.maxRestartCount, { maxRestartCount }, { preserveScroll: true });
    }

    return (
        <div>
            <div className="flex flex-col md:w-96">
                <div className="flex items-center gap-2">
                    <h2>Advanced</h2>
                </div>
                <div>Advanced configuration for your application.</div>

                <div className="flex flex-col gap-1 pt-4">
                    <h3>Build</h3>
                    <Checkbox
                        label="Disable Build Cache"
                        helper="Disable Docker build cache on every deployment."
                        checked={form.disableBuildCache}
                        disabled={!canUpdate}
                        onChange={(e) => instantSave({ disableBuildCache: e.target.checked })}
                    />
                    <Checkbox
                        label="Inject Build Args to Dockerfile"
                        helper="When enabled, Coolify automatically adds ARG statements to your Dockerfile for build-time variables. Disable this if you manage ARGs manually in your Dockerfile to preserve Docker build cache."
                        checked={form.injectBuildArgsToDockerfile}
                        disabled={!canUpdate}
                        onChange={(e) => instantSave({ injectBuildArgsToDockerfile: e.target.checked })}
                    />
                    <Checkbox
                        label="Include Source Commit in Build"
                        helper="When enabled, SOURCE_COMMIT (git commit hash) is available during Docker build. Disable to preserve cache across different commits - SOURCE_COMMIT will still be available at runtime."
                        checked={form.includeSourceCommitInBuild}
                        disabled={!canUpdate}
                        onChange={(e) => instantSave({ includeSourceCommitInBuild: e.target.checked })}
                    />

                    <h3 className="pt-4">Container</h3>
                    <Checkbox
                        label="Consistent Container Names"
                        helper="The deployed container will have the same name. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                        checked={form.isConsistentContainerNameEnabled}
                        disabled={!canUpdate}
                        onChange={(e) => instantSave({ isConsistentContainerNameEnabled: e.target.checked })}
                    />
                    {!form.isConsistentContainerNameEnabled && (
                        <form className="flex items-end gap-2" onSubmit={saveCustomName}>
                            <label className="flex flex-col gap-1">
                                Custom Container Name
                                <input disabled={!canUpdate} value={customInternalName} onChange={(e) => setCustomInternalName(e.target.value)} />
                                <span className="text-xs text-neutral-500">
                                    You can add a custom name for your container. The name will be converted to slug format when you save it. You will lose
                                    the rolling update feature!
                                </span>
                            </label>
                            {canUpdate && <button type="submit">Save</button>}
                        </form>
                    )}

                    {advanced.gitBased && (
                        <>
                            <h3 className="pt-4">Deployment</h3>
                            <Checkbox
                                label="Auto Deploy"
                                helper="Automatically deploy new commits based on Git webhooks."
                                checked={form.isAutoDeployEnabled}
                                disabled={!canUpdate}
                                onChange={(e) => instantSave({ isAutoDeployEnabled: e.target.checked })}
                            />
                            <Checkbox
                                label="Preview Deployments"
                                helper="Allow to automatically deploy Preview Deployments for all opened PR's.<br/><br/>Closing a PR will delete Preview Deployments."
                                checked={form.isPreviewDeploymentsEnabled}
                                disabled={!canUpdate}
                                onChange={(e) => instantSave({ isPreviewDeploymentsEnabled: e.target.checked })}
                            />
                            <Checkbox
                                label="Allow Public PR Deployments"
                                helper="When enabled, anyone can trigger PR deployments. When disabled, fork PRs are blocked and only repository owners, members, and collaborators can trigger PR deployments."
                                checked={form.isPrDeploymentsPublicEnabled}
                                disabled={!canUpdate || !form.isPreviewDeploymentsEnabled}
                                onChange={(e) => instantSave({ isPrDeploymentsPublicEnabled: e.target.checked })}
                            />

                            <h3 className="pt-4">Git</h3>
                            <Checkbox
                                label="Submodules"
                                helper="Allow Git Submodules during build process."
                                checked={form.isGitSubmodulesEnabled}
                                disabled={!canUpdate}
                                onChange={(e) => instantSave({ isGitSubmodulesEnabled: e.target.checked })}
                            />
                            <Checkbox
                                label="LFS"
                                helper="Allow Git LFS during build process."
                                checked={form.isGitLfsEnabled}
                                disabled={!canUpdate}
                                onChange={(e) => instantSave({ isGitLfsEnabled: e.target.checked })}
                            />
                            <Checkbox
                                label="Shallow Clone"
                                helper="Use shallow cloning (--depth=1) to speed up deployments by only fetching the latest commit history. This reduces clone time and resource usage, especially for large repositories."
                                checked={form.isGitShallowCloneEnabled}
                                disabled={!canUpdate}
                                onChange={(e) => instantSave({ isGitShallowCloneEnabled: e.target.checked })}
                            />
                        </>
                    )}

                    {advanced.buildPack === 'dockercompose' && (
                        <>
                            <h3 className="pt-4">Docker Compose</h3>
                            <Checkbox
                                label="Raw Compose Deployment"
                                helper="WARNING: Advanced use cases only. Your docker compose file will be deployed as-is. Nothing is modified by Coolify. You need to configure the proxy parts."
                                checked={form.isRawComposeDeploymentEnabled}
                                disabled={!canUpdate}
                                onChange={(e) => instantSave({ isRawComposeDeploymentEnabled: e.target.checked })}
                            />
                            <Checkbox
                                label="Connect To Predefined Network"
                                helper="By default, you do not reach the Coolify defined networks. Starting a docker compose based resource will have an internal network. If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource."
                                checked={form.isConnectToDockerNetworkEnabled}
                                disabled={!canUpdate}
                                onChange={(e) => instantSave({ isConnectToDockerNetworkEnabled: e.target.checked })}
                            />
                        </>
                    )}

                    <h3 className="pt-4">Proxy</h3>
                    <Checkbox
                        label="Force Https"
                        helper={
                            advanced.isContainerLabelReadonlyEnabled
                                ? 'Your application will be available only on https if your domain starts with https://...'
                                : 'Readonly labels are disabled. You need to set the labels in the labels section.'
                        }
                        checked={form.isForceHttpsEnabled}
                        disabled={!canUpdate || !advanced.isContainerLabelReadonlyEnabled}
                        onChange={(e) => instantSave({ isForceHttpsEnabled: e.target.checked })}
                    />
                    <Checkbox
                        label="Enable Gzip Compression"
                        helper={
                            advanced.isContainerLabelReadonlyEnabled
                                ? 'You can disable gzip compression if you want. Some services are compressing data by default. In this case, you do not need this.'
                                : 'Readonly labels are disabled. You need to set the labels in the labels section.'
                        }
                        checked={form.isGzipEnabled}
                        disabled={!canUpdate || !advanced.isContainerLabelReadonlyEnabled}
                        onChange={(e) => instantSave({ isGzipEnabled: e.target.checked })}
                    />
                    <Checkbox
                        label="Strip Prefixes"
                        helper={
                            advanced.isContainerLabelReadonlyEnabled
                                ? 'Strip Prefix is used to remove prefixes from paths. Like /api/ to /api.'
                                : 'Readonly labels are disabled. You need to set the labels in the labels section.'
                        }
                        checked={form.isStripprefixEnabled}
                        disabled={!canUpdate || !advanced.isContainerLabelReadonlyEnabled}
                        onChange={(e) => instantSave({ isStripprefixEnabled: e.target.checked })}
                    />

                    <h3 className="pt-4">Operations</h3>
                    <form className="flex items-end gap-2" onSubmit={saveStopGracePeriod}>
                        <label className="flex flex-col gap-1">
                            Stop Grace Period (seconds)
                            <input type="number" disabled={!canUpdate} value={stopGracePeriod} onChange={(e) => setStopGracePeriod(e.target.value)} />
                            <span className="text-xs text-neutral-500">
                                How long to wait for graceful shutdown during rolling updates, manual stops, and restarts.
                            </span>
                        </label>
                        {canUpdate && <button type="submit">Save</button>}
                    </form>
                    <form className="flex items-end gap-2" onSubmit={saveMaxRestartCount}>
                        <label className="flex flex-col gap-1">
                            Max Restart Count
                            <input type="number" min={0} disabled={!canUpdate} value={maxRestartCount} onChange={(e) => setMaxRestartCount(e.target.value)} />
                            <span className="text-xs text-neutral-500">
                                Maximum number of crash restarts before Coolify automatically stops the application and sends a notification. Set to 0 to
                                disable the limit.
                            </span>
                        </label>
                        {canUpdate && <button type="submit">Save</button>}
                    </form>

                    <h3 className="pt-4">Logs</h3>
                    <Checkbox
                        label="Drain Logs"
                        helper="Drain logs to your configured log drain endpoint in your Server settings."
                        checked={form.isLogDrainEnabled}
                        disabled={!canUpdate}
                        onChange={(e) => instantSave({ isLogDrainEnabled: e.target.checked })}
                    />
                </div>
            </div>

            {advanced.buildPack !== 'dockercompose' && (
                <form onSubmit={saveGpuSettings} className="flex flex-col gap-2">
                    <div className="flex gap-2 items-end pt-4">
                        <h3>GPU</h3>
                        {form.isGpuEnabled && canUpdate && <button type="submit">Save</button>}
                    </div>
                    <div className="md:w-96 pb-4">
                        <Checkbox
                            label="Enable GPU"
                            helper="Enable GPU usage for this application."
                            checked={form.isGpuEnabled}
                            disabled={!canUpdate}
                            onChange={(e) => setForm({ ...form, isGpuEnabled: e.target.checked })}
                        />
                    </div>
                    {form.isGpuEnabled && (
                        <div className="flex flex-col w-full gap-2">
                            <div className="flex gap-2 items-end">
                                <label className="flex flex-col gap-1">
                                    GPU Driver
                                    <input disabled={!canUpdate} value={form.gpuDriver} onChange={(e) => setForm({ ...form, gpuDriver: e.target.value })} />
                                </label>
                                <label className="flex flex-col gap-1">
                                    GPU Count
                                    <input
                                        placeholder="empty means use all GPUs"
                                        disabled={!canUpdate}
                                        value={form.gpuCount}
                                        onChange={(e) => setForm({ ...form, gpuCount: e.target.value })}
                                    />
                                </label>
                            </div>
                            <label className="flex flex-col gap-1">
                                GPU Device Ids
                                <input
                                    placeholder="0,2"
                                    disabled={!canUpdate}
                                    value={form.gpuDeviceIds}
                                    onChange={(e) => setForm({ ...form, gpuDeviceIds: e.target.value })}
                                />
                                <span className="text-xs text-neutral-500">Comma separated list of device ids.</span>
                            </label>
                            <label className="flex flex-col gap-1">
                                GPU Options
                                <textarea rows={10} disabled={!canUpdate} value={form.gpuOptions} onChange={(e) => setForm({ ...form, gpuOptions: e.target.value })} />
                            </label>
                        </div>
                    )}
                </form>
            )}
        </div>
    );
}
