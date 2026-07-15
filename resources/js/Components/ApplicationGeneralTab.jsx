import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DomainConflictModal from './DomainConflictModal';
import MonacoEditor from './MonacoEditor';
import PasswordConfirmModal from './PasswordConfirmModal';
import ResourceDetailsModal from './ResourceDetailsModal';

/**
 * React port of App\Livewire\Project\Application\General — the main build-pack/deployment
 * configuration form for an Application resource. The largest single component in this
 * migration, matching the size of its source (1068 PHP + 619 Blade lines). See
 * ProjectApplicationConfigurationController::generalTabProps()/updateGeneral() for the
 * server side of every action here.
 *
 * All instant-save checkboxes (isStatic/isSpa/isPreserveRepositoryEnabled/
 * isBuildServerEnabled/isHttpBasicAuthEnabled/isContainerLabelEscapeEnabled/
 * isContainerLabelReadonlyEnabled) submit the FULL current form state to one
 * instantSaveGeneral endpoint, matching the original's instantSave() — Livewire's
 * checkboxes aren't scoped to just the toggled field, they re-save everything.
 *
 * Domain-conflict handling (both the top-level FQDN and, for docker-compose apps, the
 * per-service compose domains) follows ServiceStackTab.jsx's established flash contract:
 * server flashes domainConflicts/showDomainConflictModal, client re-submits the same save
 * with force_save_domains: true on confirm.
 */
function Field({ label, helper, className = '', ...props }) {
    return (
        <label className={`flex flex-col flex-1 gap-1 ${className}`}>
            {label && <span title={helper}>{label}</span>}
            <input {...props} />
        </label>
    );
}

function Checkbox({ id, label, helper, checked, onChange, disabled }) {
    return (
        <label className="flex items-center gap-2" title={helper}>
            <input id={id} type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
            {label}
        </label>
    );
}

export default function ApplicationGeneralTab({ general, resourceDetails, generalUrls, canUpdate }) {
    const { props } = usePage();
    const [form, setForm] = useState({
        name: general.name ?? '',
        description: general.description ?? '',
        fqdn: general.fqdn ?? '',
        gitRepository: general.gitRepository ?? '',
        gitBranch: general.gitBranch ?? '',
        gitCommitSha: general.gitCommitSha ?? '',
        installCommand: general.installCommand ?? '',
        buildCommand: general.buildCommand ?? '',
        startCommand: general.startCommand ?? '',
        buildPack: general.buildPack,
        staticImage: general.staticImage ?? 'nginx:alpine',
        baseDirectory: general.baseDirectory ?? '/',
        publishDirectory: general.publishDirectory ?? '',
        portsExposes: general.portsExposes ?? '',
        portsMappings: general.portsMappings ?? '',
        customNetworkAliases: general.customNetworkAliases ?? '',
        dockerfile: general.dockerfile ?? '',
        dockerfileLocation: general.dockerfileLocation ?? '/Dockerfile',
        dockerfileTargetBuild: general.dockerfileTargetBuild ?? '',
        dockerRegistryImageName: general.dockerRegistryImageName ?? '',
        dockerRegistryImageTag: general.dockerRegistryImageTag ?? '',
        dockerComposeLocation: general.dockerComposeLocation ?? '/docker-compose.yaml',
        dockerComposeCustomStartCommand: general.dockerComposeCustomStartCommand ?? '',
        dockerComposeCustomBuildCommand: general.dockerComposeCustomBuildCommand ?? '',
        customLabels: general.customLabels ?? '',
        customDockerRunOptions: general.customDockerRunOptions ?? '',
        preDeploymentCommand: general.preDeploymentCommand ?? '',
        preDeploymentCommandContainer: general.preDeploymentCommandContainer ?? '',
        postDeploymentCommand: general.postDeploymentCommand ?? '',
        postDeploymentCommandContainer: general.postDeploymentCommandContainer ?? '',
        customNginxConfiguration: general.customNginxConfiguration ?? '',
        isHttpBasicAuthEnabled: general.isHttpBasicAuthEnabled,
        httpBasicAuthUsername: general.httpBasicAuthUsername ?? '',
        httpBasicAuthPassword: general.httpBasicAuthPassword ?? '',
        watchPaths: general.watchPaths ?? '',
        redirect: general.redirect ?? 'both',
        isStatic: general.isStatic,
        isSpa: general.isSpa,
        isBuildServerEnabled: general.isBuildServerEnabled,
        isPreserveRepositoryEnabled: general.isPreserveRepositoryEnabled,
        isContainerLabelEscapeEnabled: general.isContainerLabelEscapeEnabled,
        isContainerLabelReadonlyEnabled: general.isContainerLabelReadonlyEnabled,
    });
    const [parsedServiceDomains, setParsedServiceDomains] = useState(general.parsedServiceDomains ?? {});
    const [showingDetails, setShowingDetails] = useState(false);
    const [loadingCompose, setLoadingCompose] = useState(false);
    const [showResetLabels, setShowResetLabels] = useState(false);
    const [showSetDirection, setShowSetDirection] = useState(false);
    const [conflictDismissed, setConflictDismissed] = useState(false);

    useEffect(() => setConflictDismissed(false), [props.flash?.domainConflicts]);

    // Loads the compose file automatically on first mount for a docker-compose app that
    // doesn't have one yet, matching the original's x-init="$wire.dispatch('loadCompose', true)".
    useEffect(() => {
        if (general.buildPack === 'dockercompose' && !general.dockerComposeRaw && canUpdate) {
            loadCompose(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function submit(e, extra = {}) {
        e?.preventDefault?.();
        router.patch(generalUrls.update, { ...form, parsedServiceDomains, ...extra }, { preserveScroll: true });
    }

    function handleBuildPackChange(e) {
        const value = e.target.value;
        const updated = { ...form, buildPack: value };
        setForm(updated);
        router.patch(generalUrls.update, { ...updated, parsedServiceDomains, buildPackChanged: true }, { preserveScroll: true });
    }

    function instantSave(overrides) {
        const updated = { ...form, ...overrides };
        setForm(updated);
        router.patch(generalUrls.instantSave, { ...updated, parsedServiceDomains }, { preserveScroll: true });
    }

    function loadCompose(isInit = false) {
        setLoadingCompose(true);
        router.post(
            generalUrls.loadCompose,
            { isInit },
            { preserveScroll: true, onFinish: () => setLoadingCompose(false) },
        );
    }

    function generateServiceDomain(serviceName) {
        router.post(generalUrls.generateServiceDomain, { serviceName }, { preserveScroll: true });
    }

    function generateWildcardDomain() {
        router.post(generalUrls.wildcardDomain, {}, { preserveScroll: true });
    }

    function generateNginxConfig(type) {
        router.post(generalUrls.generateNginxConfig, { type }, { preserveScroll: true, onSuccess: () => router.reload({ only: ['general'] }) });
    }

    const shouldDisable = loadingCompose || !canUpdate;
    const hasDockerfileOverride = Boolean(general.dockerfile);
    const isDockerImage = form.buildPack === 'dockerimage';
    const isStaticBuildPack = form.buildPack === 'static';
    const isComposeBuildPack = form.buildPack === 'dockercompose';
    const isDockerfileBuildPack = form.buildPack === 'dockerfile';
    const isNixpacksOrRailpack = form.buildPack === 'nixpacks' || form.buildPack === 'railpack';
    const showStaticToggle = general.couldSetBuildCommands && !hasDockerfileOverride && !isDockerImage;
    const showSpaToggle = form.isStatic && form.buildPack !== 'static';

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col pb-32">
                <div className="flex items-center gap-2">
                    <h2>General</h2>
                    <button type="submit" disabled={!canUpdate}>
                        Save
                    </button>
                    <button type="button" onClick={() => setShowingDetails(true)}>
                        Details
                    </button>
                    {isComposeBuildPack && canUpdate && (
                        <button type="button" disabled={loadingCompose} onClick={() => loadCompose(false)}>
                            {general.dockerComposeRaw ? 'Reload Compose File' : 'Load Compose File'}
                        </button>
                    )}
                </div>
                <div>General configuration for your application.</div>
                <div className="flex flex-col gap-2 py-4">
                    <div className="flex flex-col items-end gap-2 xl:flex-row">
                        <Field disabled={shouldDisable} label="Name" required value={form.name} onChange={(e) => set('name', e.target.value)} id="app-general-name" name="app-general-name" />
                        <Field disabled={shouldDisable} label="Description" value={form.description} onChange={(e) => set('description', e.target.value)} id="app-general-description" name="app-general-description" />
                    </div>

                    {!hasDockerfileOverride && !isDockerImage && (
                        <div className="flex flex-col gap-2">
                            <div className="flex gap-2">
                                <label className="flex flex-col flex-1 gap-1">
                                    Build Pack
                                    <select
                                        id="app-general-build-pack"
                                        name="app-general-build-pack"
                                        disabled={shouldDisable}
                                        value={form.buildPack}
                                        onChange={handleBuildPackChange}
                                    >
                                        <option value="nixpacks">Nixpacks</option>
                                        <option value="railpack">Railpack (Beta)</option>
                                        <option value="static">Static</option>
                                        <option value="dockerfile">Dockerfile</option>
                                        <option value="dockercompose">Docker Compose</option>
                                    </select>
                                </label>
                                {(form.isStatic || isStaticBuildPack) && (
                                    <label className="flex flex-col flex-1 gap-1">
                                        Static Image
                                        <select
                                            id="app-general-static-image"
                                            name="app-general-static-image"
                                            disabled={!canUpdate}
                                            value={form.staticImage}
                                            onChange={(e) => set('staticImage', e.target.value)}
                                        >
                                            <option value="nginx:alpine">nginx:alpine</option>
                                            <option disabled value="apache:alpine">
                                                apache:alpine
                                            </option>
                                        </select>
                                    </label>
                                )}
                            </div>

                            {isComposeBuildPack && general.composeServices.length > 0 && !general.isRawComposeDeploymentEnabled && (
                                <>
                                    {general.composeServices.some((s) => !s.isDatabaseImage) && <h3 className="pt-6">Domains</h3>}
                                    {general.composeServices
                                        .filter((s) => !s.isDatabaseImage)
                                        .map((s) => (
                                            <div key={s.sanitizedKey} className="flex items-end gap-2">
                                                <Field
                                                    id={`app-general-service-domain-${s.sanitizedKey}`}
                                                    name={`app-general-service-domain-${s.sanitizedKey}`}
                                                    disabled={shouldDisable}
                                                    label={`Domains for ${s.name}`}
                                                    helper="You can specify one domain with path or more with comma."
                                                    value={parsedServiceDomains[s.sanitizedKey]?.domain ?? ''}
                                                    onChange={(e) =>
                                                        setParsedServiceDomains((d) => ({ ...d, [s.sanitizedKey]: { ...d[s.sanitizedKey], domain: e.target.value } }))
                                                    }
                                                />
                                                {canUpdate && (
                                                    <button type="button" onClick={() => generateServiceDomain(s.name)}>
                                                        Generate Domain
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                </>
                            )}
                        </div>
                    )}

                    {(form.isStatic || isStaticBuildPack) && (
                        <>
                            <label className="flex flex-col gap-1">
                                Custom Nginx Configuration
                                <textarea
                                    id="app-general-custom-nginx-configuration"
                                    name="app-general-custom-nginx-configuration"
                                    placeholder="Empty means default configuration will be used."
                                    disabled={!canUpdate}
                                    value={form.customNginxConfiguration}
                                    onChange={(e) => set('customNginxConfiguration', e.target.value)}
                                />
                            </label>
                            {canUpdate && (
                                <button type="button" onClick={() => generateNginxConfig(general.isSpa ? 'spa' : 'static')}>
                                    Generate Default Nginx Configuration
                                </button>
                            )}
                        </>
                    )}

                    {(showStaticToggle || showSpaToggle) && (
                        <div className="w-96 pb-6 flex flex-col gap-1">
                            {showStaticToggle && (
                                <Checkbox
                                    id="app-general-is-static"
                                    label="Is it a static site?"
                                    helper="If your application is a static site or the final build assets should be served as a static site, enable this."
                                    checked={form.isStatic}
                                    disabled={!canUpdate}
                                    onChange={(checked) => instantSave({ isStatic: checked })}
                                />
                            )}
                            {showSpaToggle && (
                                <Checkbox
                                    id="app-general-is-spa"
                                    label="Is it a SPA (Single Page Application)?"
                                    helper="If your application is a SPA, enable this."
                                    checked={form.isSpa}
                                    disabled={!canUpdate}
                                    onChange={(checked) => instantSave({ isSpa: checked })}
                                />
                            )}
                        </div>
                    )}

                    {!isComposeBuildPack && (
                        <>
                            <div className="flex items-end gap-2">
                                {!general.isContainerLabelReadonlyEnabled ? (
                                    <Field
                                id="app-general-fqdn"
                                name="app-general-fqdn"
                                        placeholder="https://coolify.io"
                                        label="Domains"
                                        readOnly
                                        helper="Readonly labels are disabled. You can set the domains in the labels section."
                                        disabled={!canUpdate}
                                        value={form.fqdn}
                                    />
                                ) : (
                                    <>
                                        <Field
                                            id="app-general-fqdn"
                                            name="app-general-fqdn"
                                            placeholder="https://coolify.io"
                                            label="Domains"
                                            helper="You can specify one domain with path or more with comma."
                                            disabled={!canUpdate}
                                            value={form.fqdn}
                                            onChange={(e) => set('fqdn', e.target.value)}
                                        />
                                        {canUpdate && (
                                            <button type="button" onClick={generateWildcardDomain}>
                                                Generate Domain
                                            </button>
                                        )}
                                    </>
                                )}
                            </div>
                            <div className="flex items-end gap-2">
                                {!general.isContainerLabelReadonlyEnabled ? (
                                    <Field
                                        id="app-general-direction"
                                        name="app-general-direction"
                                        label="Direction"
                                        readOnly
                                        value={
                                            general.redirect === 'both'
                                                ? 'Allow www & non-www.'
                                                : general.redirect === 'www'
                                                  ? 'Redirect to www.'
                                                  : 'Redirect to non-www.'
                                        }
                                        helper="Readonly labels are disabled. You can set the direction in the labels section."
                                        disabled={!canUpdate}
                                    />
                                ) : (
                                    <>
                                        <label className="flex flex-col flex-1 gap-1">
                                            Direction
                                            <select
                                                id="app-general-direction"
                                                name="app-general-direction"
                                                disabled={!canUpdate}
                                                value={form.redirect}
                                                onChange={(e) => set('redirect', e.target.value)}
                                            >
                                                <option value="both">Allow www & non-www.</option>
                                                <option value="www">Redirect to www.</option>
                                                <option value="non-www">Redirect to non-www.</option>
                                            </select>
                                        </label>
                                        {canUpdate && (
                                            <button type="button" onClick={() => setShowSetDirection(true)}>
                                                Set Direction
                                            </button>
                                        )}
                                    </>
                                )}
                            </div>
                        </>
                    )}

                    {!isComposeBuildPack && (
                        <>
                            <div className="flex items-center gap-2 pt-8">
                                <h3>Docker Registry</h3>
                            </div>
                            <div className="flex flex-col gap-2 xl:flex-row">
                                <Field
                                    id="app-general-docker-registry-image-name"
                                    name="app-general-docker-registry-image-name"
                                    label="Docker Image"
                                    required={isDockerImage && general.isSwarm}
                                    disabled={!canUpdate}
                                    value={form.dockerRegistryImageName}
                                    onChange={(e) => set('dockerRegistryImageName', e.target.value)}
                                />
                                <Field
                                    id="app-general-docker-registry-image-tag"
                                    name="app-general-docker-registry-image-tag"
                                    label="Docker Image Tag"
                                    disabled={!canUpdate}
                                    value={form.dockerRegistryImageTag}
                                    onChange={(e) => set('dockerRegistryImageTag', e.target.value)}
                                />
                            </div>
                        </>
                    )}

                    <div className="pt-6">
                        <h3>Build</h3>
                        {isDockerImage ? (
                            <Field
                                id="app-general-custom-docker-run-options"
                                name="app-general-custom-docker-run-options"
                                label="Custom Docker Options"
                                placeholder="--cap-add SYS_ADMIN"
                                disabled={!canUpdate}
                                value={form.customDockerRunOptions}
                                onChange={(e) => set('customDockerRunOptions', e.target.value)}
                            />
                        ) : (
                            <div className="flex flex-col gap-2 pt-6 pb-10">
                                {isComposeBuildPack ? (
                                    <div className="flex flex-col gap-2">
                                        <div className="flex gap-2">
                                            <Field
                                                id="app-general-base-directory"
                                                name="app-general-base-directory"
                                                disabled={shouldDisable}
                                                placeholder="/"
                                                label="Base Directory"
                                                helper="Directory to use as root. Useful for monorepos."
                                                value={form.baseDirectory}
                                                onChange={(e) => set('baseDirectory', e.target.value)}
                                                onBlur={(e) => set('baseDirectory', normalizePath(e.target.value))}
                                            />
                                            <Field
                                                id="app-general-docker-compose-location"
                                                name="app-general-docker-compose-location"
                                                disabled={shouldDisable}
                                                placeholder="/docker-compose.yaml"
                                                label="Docker Compose Location"
                                                value={form.dockerComposeLocation}
                                                onChange={(e) => set('dockerComposeLocation', e.target.value)}
                                                onBlur={(e) => set('dockerComposeLocation', normalizePath(e.target.value))}
                                            />
                                        </div>
                                        <div className="w-full sm:w-96">
                                            <Checkbox
                                                id="app-general-is-preserve-repository-enabled"
                                                label="Preserve Repository During Deployment"
                                                helper="Git repository (based on the base directory settings) will be copied to the deployment directory."
                                                checked={form.isPreserveRepositoryEnabled}
                                                disabled={shouldDisable}
                                                onChange={(checked) => instantSave({ isPreserveRepositoryEnabled: checked })}
                                            />
                                        </div>
                                        <div className="pt-4">The following commands are for advanced use cases. Only modify them if you know what you are doing.</div>
                                        <div className="flex gap-2">
                                            <Field
                                                id="app-general-docker-compose-custom-build-command"
                                                name="app-general-docker-compose-custom-build-command"
                                                disabled={shouldDisable}
                                                placeholder="docker compose build"
                                                label="Custom Build Command"
                                                value={form.dockerComposeCustomBuildCommand}
                                                onChange={(e) => set('dockerComposeCustomBuildCommand', e.target.value)}
                                            />
                                            <Field
                                                id="app-general-docker-compose-custom-start-command"
                                                name="app-general-docker-compose-custom-start-command"
                                                disabled={shouldDisable}
                                                placeholder="docker compose up -d"
                                                label="Custom Start Command"
                                                value={form.dockerComposeCustomStartCommand}
                                                onChange={(e) => set('dockerComposeCustomStartCommand', e.target.value)}
                                            />
                                        </div>
                                        {general.dockerComposeBuildCommandPreview && (
                                            <Field
                                                id="app-general-docker-compose-build-command-preview"
                                                name="app-general-docker-compose-build-command-preview"
                                                readOnly
                                                label="Final Build Command (Preview)"
                                                value={general.dockerComposeBuildCommandPreview}
                                            />
                                        )}
                                        {general.dockerComposeStartCommandPreview && (
                                            <Field
                                                id="app-general-docker-compose-start-command-preview"
                                                name="app-general-docker-compose-start-command-preview"
                                                readOnly
                                                label="Final Start Command (Preview)"
                                                value={general.dockerComposeStartCommandPreview}
                                            />
                                        )}
                                        {general.isGithubBasedPrivateRepo && (
                                            <label className="flex flex-col gap-1 pt-4">
                                                Watch Paths
                                                <textarea
                                                    id="app-general-watch-paths"
                                                    name="app-general-watch-paths"
                                                    disabled={shouldDisable}
                                                    placeholder="services/api/**"
                                                    value={form.watchPaths}
                                                    onChange={(e) => set('watchPaths', e.target.value)}
                                                />
                                            </label>
                                        )}
                                    </div>
                                ) : (
                                    <>
                                        {general.couldSetBuildCommands && isNixpacksOrRailpack && (
                                            <div className="flex flex-col gap-2 xl:flex-row">
                                                <Field disabled={!canUpdate} label="Install Command" value={form.installCommand} onChange={(e) => set('installCommand', e.target.value)} id="app-general-install-command" name="app-general-install-command" />
                                                <Field disabled={!canUpdate} label="Build Command" value={form.buildCommand} onChange={(e) => set('buildCommand', e.target.value)} id="app-general-build-command" name="app-general-build-command" />
                                                <Field disabled={!canUpdate} label="Start Command" value={form.startCommand} onChange={(e) => set('startCommand', e.target.value)} id="app-general-start-command" name="app-general-start-command" />
                                            </div>
                                        )}
                                        <div className="flex flex-col gap-2 xl:flex-row">
                                            <Field
                                                id="app-general-base-directory"
                                                name="app-general-base-directory"
                                                placeholder="/"
                                                label="Base Directory"
                                                helper="Directory to use as root. Useful for monorepos."
                                                disabled={!canUpdate}
                                                value={form.baseDirectory}
                                                onChange={(e) => set('baseDirectory', e.target.value)}
                                                onBlur={(e) => set('baseDirectory', normalizePath(e.target.value))}
                                            />
                                            {isDockerfileBuildPack && !hasDockerfileOverride && (
                                                <Field
                                                    id="app-general-dockerfile-location"
                                                    name="app-general-dockerfile-location"
                                                    placeholder="/Dockerfile"
                                                    label="Dockerfile Location"
                                                    disabled={!canUpdate}
                                                    value={form.dockerfileLocation}
                                                    onChange={(e) => set('dockerfileLocation', e.target.value)}
                                                    onBlur={(e) => set('dockerfileLocation', normalizePath(e.target.value))}
                                                />
                                            )}
                                            {isDockerfileBuildPack && (
                                                <Field
                                                    id="app-general-dockerfile-target-build"
                                                    name="app-general-dockerfile-target-build"
                                                    label="Docker Build Stage Target"
                                                    helper="Useful if you have multi-staged dockerfile."
                                                    disabled={!canUpdate}
                                                    value={form.dockerfileTargetBuild}
                                                    onChange={(e) => set('dockerfileTargetBuild', e.target.value)}
                                                />
                                            )}
                                            {general.couldSetBuildCommands && (
                                                <Field
                                                    id="app-general-publish-directory"
                                                    name="app-general-publish-directory"
                                                    placeholder={form.isStatic ? '/dist' : '/'}
                                                    label="Publish Directory"
                                                    required={form.isStatic}
                                                    disabled={!canUpdate}
                                                    value={form.publishDirectory}
                                                    onChange={(e) => set('publishDirectory', e.target.value)}
                                                />
                                            )}
                                        </div>
                                        {general.isGithubBasedPrivateRepo && (
                                            <label className="flex flex-col gap-1 pb-4">
                                                Watch Paths
                                                <textarea
                                                    id="app-general-watch-paths"
                                                    name="app-general-watch-paths"
                                                    disabled={!canUpdate}
                                                    placeholder="src/pages/**"
                                                    value={form.watchPaths}
                                                    onChange={(e) => set('watchPaths', e.target.value)}
                                                />
                                            </label>
                                        )}
                                        <Field
                                            id="app-general-custom-docker-run-options"
                                            name="app-general-custom-docker-run-options"
                                            label="Custom Docker Options"
                                            placeholder="--cap-add SYS_ADMIN"
                                            disabled={!canUpdate}
                                            value={form.customDockerRunOptions}
                                            onChange={(e) => set('customDockerRunOptions', e.target.value)}
                                        />
                                        <div className="pt-2 w-full sm:w-96">
                                            <Checkbox
                                                id="app-general-is-build-server-enabled"
                                                label="Use a Build Server?"
                                                helper="Use a build server to build your application."
                                                checked={form.isBuildServerEnabled}
                                                disabled={!canUpdate}
                                                onChange={(checked) => instantSave({ isBuildServerEnabled: checked })}
                                            />
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>

                    {isComposeBuildPack && (
                        <div>
                            <h3>Docker Compose</h3>
                            <MonacoEditor value={general.dockerComposeRaw ?? ''} language="yaml" readOnly height="300px" />
                            <div className="w-full sm:w-96 pt-2">
                                <Checkbox
                                    id="app-general-is-container-label-escape-enabled"
                                    label="Escape special characters in labels?"
                                    helper="By default, $ (and other chars) is escaped."
                                    checked={form.isContainerLabelEscapeEnabled}
                                    disabled={!canUpdate}
                                    onChange={(checked) => instantSave({ isContainerLabelEscapeEnabled: checked })}
                                />
                            </div>
                        </div>
                    )}

                    {general.dockerfile && (
                        <label className="flex flex-col gap-1">
                            Dockerfile
                            <MonacoEditor value={form.dockerfile} onChange={(v) => set('dockerfile', v)} language="dockerfile" readOnly={!canUpdate} height="300px" />
                        </label>
                    )}

                    {!isComposeBuildPack && (
                        <>
                            <h3 className="pt-8">Network</h3>
                            {general.detectedPortInfo && (
                                <div className="p-4 mb-4 text-sm rounded-lg bg-warning/10">
                                    {general.detectedPortInfo.isEmpty
                                        ? `PORT environment variable detected (${general.detectedPortInfo.port}). Your Ports Exposes field is empty.`
                                        : !general.detectedPortInfo.matches
                                          ? `PORT mismatch detected: your PORT env var is ${general.detectedPortInfo.port}, but it's not in your Ports Exposes configuration.`
                                          : `PORT environment variable configured (${general.detectedPortInfo.port}) matches your Ports Exposes configuration.`}
                                </div>
                            )}
                            {(!form.portsExposes || form.portsExposes === '0') && form.fqdn && (
                                <div className="p-4 mb-4 text-sm rounded-lg bg-warning/10">
                                    This application does not expose any ports and will not be reachable through the proxy or your domains.
                                </div>
                            )}
                            <div className="flex flex-col gap-2 xl:flex-row">
                                <Field
                                    id="app-general-ports-exposes"
                                    name="app-general-ports-exposes"
                                    placeholder="3000,3001"
                                    label="Ports Exposes"
                                    readOnly={isStaticBuildPack}
                                    disabled={!canUpdate}
                                    value={form.portsExposes}
                                    onChange={(e) => set('portsExposes', e.target.value)}
                                />
                                {!general.isSwarm && (
                                    <Field
                                        id="app-general-ports-mappings"
                                        name="app-general-ports-mappings"
                                        placeholder="3000:3000"
                                        label="Port Mappings"
                                        disabled={!canUpdate}
                                        value={form.portsMappings}
                                        onChange={(e) => set('portsMappings', e.target.value)}
                                    />
                                )}
                                {!general.isSwarm && (
                                    <Field
                                        id="app-general-custom-network-aliases"
                                        name="app-general-custom-network-aliases"
                                        label="Network Aliases"
                                        disabled={!canUpdate}
                                        value={form.customNetworkAliases}
                                        onChange={(e) => set('customNetworkAliases', e.target.value)}
                                    />
                                )}
                            </div>

                            <h3 className="pt-8">HTTP Basic Authentication</h3>
                            <div className="w-full sm:w-96">
                                <Checkbox
                                    id="app-general-is-http-basic-auth-enabled"
                                    label="Enable"
                                    helper="This will add the proper proxy labels to the container."
                                    checked={form.isHttpBasicAuthEnabled}
                                    disabled={!canUpdate}
                                    onChange={(checked) => instantSave({ isHttpBasicAuthEnabled: checked })}
                                />
                            </div>
                            {form.isHttpBasicAuthEnabled && (
                                <div className="flex gap-2 py-2">
                                    <Field label="Username" required disabled={!canUpdate} value={form.httpBasicAuthUsername} onChange={(e) => set('httpBasicAuthUsername', e.target.value)} id="app-general-http-basic-auth-username" name="app-general-http-basic-auth-username" />
                                    <Field
                                        id="app-general-http-basic-auth-password"
                                        name="app-general-http-basic-auth-password"
                                        type="password"
                                        label="Password"
                                        required
                                        disabled={!canUpdate}
                                        value={form.httpBasicAuthPassword}
                                        onChange={(e) => set('httpBasicAuthPassword', e.target.value)}
                                    />
                                </div>
                            )}

                            <h3 className="pt-8">Labels</h3>
                            <label className="flex flex-col gap-1">
                                Container Labels
                                <MonacoEditor
                                    value={form.customLabels}
                                    onChange={(v) => set('customLabels', v)}
                                    language="ini"
                                    readOnly={general.isContainerLabelReadonlyEnabled || !canUpdate}
                                    height="300px"
                                />
                            </label>
                            <div className="w-full sm:w-96">
                                <Checkbox
                                    id="app-general-is-container-label-readonly-enabled"
                                    label="Readonly labels"
                                    helper="Labels are readonly by default. Coolify autogenerates them; disable to edit directly."
                                    checked={form.isContainerLabelReadonlyEnabled}
                                    disabled={!canUpdate}
                                    onChange={(checked) => instantSave({ isContainerLabelReadonlyEnabled: checked })}
                                />
                                <Checkbox
                                    id="app-general-is-container-label-escape-enabled"
                                    label="Escape special characters in labels?"
                                    helper="By default, $ is escaped. Turn off to use env variables inside labels."
                                    checked={form.isContainerLabelEscapeEnabled}
                                    disabled={!canUpdate}
                                    onChange={(checked) => instantSave({ isContainerLabelEscapeEnabled: checked })}
                                />
                            </div>
                            {canUpdate && (
                                <button type="button" onClick={() => setShowResetLabels(true)}>
                                    Reset Labels to Defaults
                                </button>
                            )}
                        </>
                    )}

                    <h3 className="pt-8">Pre/Post Deployment Commands</h3>
                    <div className="flex flex-col gap-2 xl:flex-row">
                        <Field
                            id="app-general-pre-deployment-command"
                            name="app-general-pre-deployment-command"
                            disabled={shouldDisable}
                            placeholder="php artisan migrate"
                            label="Pre-deployment"
                            value={form.preDeploymentCommand}
                            onChange={(e) => set('preDeploymentCommand', e.target.value)}
                        />
                        {isComposeBuildPack && (
                            <Field
                                id="app-general-pre-deployment-command-container"
                                name="app-general-pre-deployment-command-container"
                                disabled={shouldDisable}
                                label="Container Name"
                                value={form.preDeploymentCommandContainer}
                                onChange={(e) => set('preDeploymentCommandContainer', e.target.value)}
                            />
                        )}
                    </div>
                    <div className="flex flex-col gap-2 xl:flex-row">
                        <Field
                            id="app-general-post-deployment-command"
                            name="app-general-post-deployment-command"
                            disabled={shouldDisable}
                            placeholder="php artisan migrate"
                            label="Post-deployment"
                            value={form.postDeploymentCommand}
                            onChange={(e) => set('postDeploymentCommand', e.target.value)}
                        />
                        {isComposeBuildPack && (
                            <Field
                                id="app-general-post-deployment-command-container"
                                name="app-general-post-deployment-command-container"
                                disabled={shouldDisable}
                                label="Container Name"
                                value={form.postDeploymentCommandContainer}
                                onChange={(e) => set('postDeploymentCommandContainer', e.target.value)}
                            />
                        )}
                    </div>
                </div>
            </form>

            {showingDetails && <ResourceDetailsModal details={resourceDetails} onClose={() => setShowingDetails(false)} />}

            {props.flash?.showDomainConflictModal && !conflictDismissed && (
                <DomainConflictModal
                    conflicts={props.flash?.domainConflicts ?? []}
                    onCancel={() => setConflictDismissed(true)}
                    onConfirm={() => submit(null, { force_save_domains: true })}
                    consequences={['SSL certificates might not work correctly', 'Routing behavior will be unpredictable', 'Traffic may be routed to the wrong resource']}
                />
            )}

            {showResetLabels && (
                <PasswordConfirmModal
                    title="Confirm Labels Reset to Coolify Defaults?"
                    action={{ url: generalUrls.resetLabels, method: 'post', data: { manual: true } }}
                    actions={['All your custom proxy labels will be lost.', 'Proxy labels (traefik, caddy, etc) will be reset to the coolify defaults.']}
                    confirmationText={`${form.fqdn}/`}
                    confirmationLabel="Please confirm by entering the Application URL below"
                    withPassword={false}
                    onClose={() => setShowResetLabels(false)}
                    onDone={() => setShowResetLabels(false)}
                />
            )}

            {showSetDirection && (
                <PasswordConfirmModal
                    title="Confirm Redirection Setting?"
                    action={{ url: generalUrls.setRedirect, method: 'patch', data: { redirect: form.redirect } }}
                    actions={['All traffic will be redirected to the selected direction.']}
                    confirmationText={`${form.fqdn}/`}
                    confirmationLabel="Please confirm by entering the Application URL below"
                    withPassword={false}
                    onClose={() => setShowSetDirection(false)}
                    onDone={() => setShowSetDirection(false)}
                />
            )}
        </div>
    );
}

function normalizePath(path) {
    if (!path || path.trim() === '') return '/';
    path = path.trim().replace(/\/+$/, '');
    if (!path.startsWith('/')) path = '/' + path;

    return path;
}
