import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DomainConflictModal from './DomainConflictModal';
import PasswordConfirmModal from './PasswordConfirmModal';

/**
 * React port of App\Livewire\Project\Application\Previews (+ its PreviewsCompose and
 * Preview\Form child components) — pull-request-based preview deployments. Domain-conflict
 * handling follows the same flash contract as ApplicationGeneralTab.jsx/ServiceStackTab.jsx,
 * tracked per-preview via `conflictPreviewId` since (unlike those single-form tabs) any one of
 * several preview cards on this page can be the one that triggered the flash.
 */
export default function PreviewDeploymentsTab({ previews, previewUrls, canUpdate }) {
    const { props } = usePage();
    const [previewUrlTemplate, setPreviewUrlTemplate] = useState(previews.previewUrlTemplate);
    const [realPreviewUrlTemplate, setRealPreviewUrlTemplate] = useState(previews.realPreviewUrlTemplate);
    const [pullRequests, setPullRequests] = useState([]);
    const [rateLimitRemaining, setRateLimitRemaining] = useState(null);
    const [loadingPRs, setLoadingPRs] = useState(false);
    const [manualPullRequestId, setManualPullRequestId] = useState('');
    const [manualDockerTag, setManualDockerTag] = useState('');
    const [domainForms, setDomainForms] = useState(() =>
        Object.fromEntries(previews.deployments.map((p) => [p.id, { fqdn: p.fqdn ?? '', dockerRegistryImageTag: p.dockerRegistryImageTag ?? '' }])),
    );
    const [composeDomainForms, setComposeDomainForms] = useState(() =>
        Object.fromEntries(previews.deployments.flatMap((p) => p.composeDomains.map((d) => [`${p.id}:${d.serviceName}`, d.domain ?? '']))),
    );
    const [conflictPreviewId, setConflictPreviewId] = useState(null);
    const [conflictDismissed, setConflictDismissed] = useState(false);
    const [stoppingPreview, setStoppingPreview] = useState(null);
    const [deletingPreview, setDeletingPreview] = useState(null);

    useEffect(() => setConflictDismissed(false), [props.flash?.domainConflicts]);

    function loadPullRequests() {
        setLoadingPRs(true);
        router.post(
            previewUrls.loadPullRequests,
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    setPullRequests(page.props.flash?.pullRequests ?? []);
                    setRateLimitRemaining(page.props.flash?.rateLimitRemaining ?? null);
                },
                onFinish: () => setLoadingPRs(false),
            },
        );
    }

    function saveTemplate(e) {
        e.preventDefault();
        router.patch(
            previewUrls.updateTemplate,
            { previewUrlTemplate },
            {
                preserveScroll: true,
                onSuccess: (page) => setRealPreviewUrlTemplate(page.props.previews?.realPreviewUrlTemplate ?? realPreviewUrlTemplate),
            },
        );
    }

    function resetTemplate() {
        router.patch(
            previewUrls.updateTemplate,
            { reset: true },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    setPreviewUrlTemplate(page.props.previews?.previewUrlTemplate ?? '{{pr_id}}.{{domain}}');
                    setRealPreviewUrlTemplate(page.props.previews?.realPreviewUrlTemplate ?? realPreviewUrlTemplate);
                },
            },
        );
    }

    function configurePreview(pr) {
        router.post(previewUrls.store, { pullRequestId: pr.number, pullRequestHtmlUrl: pr.htmlUrl }, { preserveScroll: true });
    }

    function deployFromList(pr) {
        router.post(previewUrls.addAndDeploy, { pullRequestId: pr.number, pullRequestHtmlUrl: pr.htmlUrl }, { preserveScroll: true });
    }

    function submitManualDockerImagePreview(e) {
        e.preventDefault();
        if (!manualPullRequestId || !manualDockerTag) return;
        router.post(
            previewUrls.addAndDeploy,
            { pullRequestId: manualPullRequestId, dockerRegistryImageTag: manualDockerTag },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setManualPullRequestId('');
                    setManualDockerTag('');
                },
            },
        );
    }

    function updateDomainForm(previewId, field, value) {
        setDomainForms((prev) => ({ ...prev, [previewId]: { ...prev[previewId], [field]: value } }));
    }

    function saveDomain(preview, extra = {}) {
        setConflictPreviewId(preview.id);
        router.patch(preview.urls.domainUpdate, { ...domainForms[preview.id], ...extra }, { preserveScroll: true });
    }

    function generateDomain(preview) {
        router.post(preview.urls.domainGenerate, {}, { preserveScroll: true });
    }

    function updateComposeDomainForm(previewId, serviceName, value) {
        setComposeDomainForms((prev) => ({ ...prev, [`${previewId}:${serviceName}`]: value }));
    }

    function saveComposeDomain(preview, serviceName) {
        router.patch(
            preview.urls.composeDomainUpdate,
            { serviceName, domain: composeDomainForms[`${preview.id}:${serviceName}`] },
            { preserveScroll: true },
        );
    }

    function generateComposeDomain(preview, serviceName) {
        router.post(preview.urls.composeDomainGenerate, { serviceName }, { preserveScroll: true });
    }

    function redeploy(preview) {
        router.post(preview.urls.deploy, { dockerRegistryImageTag: preview.dockerRegistryImageTag }, { preserveScroll: true });
    }

    function forceDeploy(preview) {
        router.post(preview.urls.forceDeploy, {}, { preserveScroll: true });
    }

    const showConflictModal = !conflictDismissed && props.flash?.showDomainConflictModal && conflictPreviewId !== null;
    const conflictPreview = showConflictModal ? previews.deployments.find((p) => p.id === conflictPreviewId) : null;

    return (
        <div className="flex flex-col gap-8">
            <form onSubmit={saveTemplate}>
                <div className="flex items-center gap-2">
                    <h2>Preview Deployments</h2>
                    {canUpdate && (
                        <>
                            <button type="submit">Save</button>
                            <button type="button" onClick={resetTemplate}>
                                Reset template to default
                            </button>
                        </>
                    )}
                </div>
                <div className="pb-4">Preview Deployments based on pull requests are here.</div>
                <div className="flex flex-col gap-2 pb-4">
                    <label className="flex flex-col gap-1">
                        Preview URL Template
                        <input
                            id="previews-url-template"
                            name="previews-url-template"
                            value={previewUrlTemplate}
                            onChange={(e) => setPreviewUrlTemplate(e.target.value)}
                            disabled={!canUpdate}
                        />
                    </label>
                    {realPreviewUrlTemplate && <div>Domain Preview: {realPreviewUrlTemplate}</div>}
                </div>
            </form>

            {previews.additionalServersCount > 0 && (
                <div>
                    Previews will be deployed on <span className="dark:text-warning">{previews.primaryServerName}</span>.
                </div>
            )}

            {previews.isGithubBased && (
                <div>
                    <div className="flex items-center gap-2">
                        {canUpdate && (
                            <>
                                <h3>Pull Requests on Git</h3>
                                <button type="button" onClick={loadPullRequests} disabled={loadingPRs}>
                                    Load Pull Requests
                                </button>
                            </>
                        )}
                    </div>
                    {rateLimitRemaining !== null && (
                        <div className="pt-1 pb-4">Requests remaining till rate limited by Git: {rateLimitRemaining}</div>
                    )}
                    {pullRequests.length > 0 && (
                        <div className="overflow-x-auto table-md">
                            <table>
                                <thead>
                                    <tr>
                                        <th>PR Number</th>
                                        <th>PR Title</th>
                                        <th>Git</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pullRequests.map((pr) => (
                                        <tr key={pr.number}>
                                            <th>{pr.number}</th>
                                            <td>{pr.title}</td>
                                            <td>
                                                <a target="_blank" rel="noreferrer" className="text-xs" href={pr.htmlUrl}>
                                                    Open PR on Git
                                                </a>
                                            </td>
                                            <td className="flex flex-col gap-1 md:flex-row">
                                                {canUpdate && (
                                                    <button type="button" onClick={() => configurePreview(pr)}>
                                                        Configure
                                                    </button>
                                                )}
                                                {previews.canDeploy && (
                                                    <button type="button" onClick={() => deployFromList(pr)}>
                                                        Deploy
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            {previews.buildPack === 'dockerimage' && (
                <div className="flex flex-col gap-2">
                    <h3>Manual Preview Deployment</h3>
                    <form onSubmit={submitManualDockerImagePreview} className="flex flex-col gap-2 xl:flex-row xl:items-end">
                        <label className="flex flex-col gap-1">
                            Pull Request Id
                            <input
                                id="previews-manual-pull-request-id"
                                name="previews-manual-pull-request-id"
                                value={manualPullRequestId}
                                onChange={(e) => setManualPullRequestId(e.target.value)}
                            />
                            <span className="text-xs text-neutral-500">Used as the preview identifier for naming, domains, logs, and cleanup.</span>
                        </label>
                        <label className="flex flex-col gap-1">
                            Docker Tag
                            <input
                                id="previews-manual-docker-tag"
                                name="previews-manual-docker-tag"
                                value={manualDockerTag}
                                onChange={(e) => setManualDockerTag(e.target.value)}
                            />
                            <span className="text-xs text-neutral-500">The image tag to deploy for this preview, for example pr_1234.</span>
                        </label>
                        {previews.canDeploy && <button type="submit">Deploy Preview</button>}
                    </form>
                </div>
            )}

            {previews.deployments.length > 0 && (
                <div>
                    <h3 className="py-4">Deployments</h3>
                    <div className="flex flex-wrap w-full gap-4">
                        {previews.deployments.map((preview) => (
                            <div key={preview.id} className="flex flex-col w-full p-4 border dark:border-coolgray-200">
                                <div className="flex flex-wrap gap-2 items-center">
                                    PR #{preview.pullRequestId} | <span>{preview.status ?? 'exited'}</span>
                                    {preview.status !== 'exited' && preview.fqdn && (
                                        <>
                                            |{' '}
                                            <a target="_blank" rel="noreferrer" href={preview.fqdn}>
                                                Open Preview
                                            </a>
                                        </>
                                    )}
                                    {preview.pullRequestHtmlUrl && (
                                        <>
                                            |{' '}
                                            <a target="_blank" rel="noreferrer" href={preview.pullRequestHtmlUrl}>
                                                Open PR on Git
                                            </a>
                                        </>
                                    )}
                                    | <a href={preview.deploymentLogsUrl}>Deployment Logs</a> |{' '}
                                    <a href={preview.applicationLogsUrl}>Application Logs</a>
                                </div>

                                {previews.buildPack === 'dockercompose' ? (
                                    <div className="flex flex-col gap-4 pt-4">
                                        {preview.composeDomains.length === 0 ? (
                                            <form
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    saveDomain(preview);
                                                }}
                                                className="flex items-end gap-2 pt-4"
                                            >
                                                <label className="flex flex-col gap-1">
                                                    Domain
                                                    <input
                                                        id={`preview-${preview.id}-domain`}
                                                        name={`preview-${preview.id}-domain`}
                                                        disabled={!canUpdate}
                                                        value={domainForms[preview.id]?.fqdn ?? ''}
                                                        onChange={(e) => updateDomainForm(preview.id, 'fqdn', e.target.value)}
                                                    />
                                                    <span className="text-xs text-neutral-500">One domain per preview.</span>
                                                </label>
                                                {canUpdate && (
                                                    <>
                                                        <button type="submit">Save</button>
                                                        <button type="button" onClick={() => generateDomain(preview)}>
                                                            Generate Domain
                                                        </button>
                                                    </>
                                                )}
                                            </form>
                                        ) : (
                                            preview.composeDomains.map((d) => (
                                                <form
                                                    key={d.serviceName}
                                                    onSubmit={(e) => {
                                                        e.preventDefault();
                                                        saveComposeDomain(preview, d.serviceName);
                                                    }}
                                                    className="flex items-end gap-2"
                                                >
                                                    <label className="flex flex-col gap-1">
                                                        Domains for {d.serviceName}
                                                        <input
                                                            id={`preview-${preview.id}-compose-domain-${d.serviceName}`}
                                                            name={`preview-${preview.id}-compose-domain-${d.serviceName}`}
                                                            disabled={!canUpdate}
                                                            value={composeDomainForms[`${preview.id}:${d.serviceName}`] ?? ''}
                                                            onChange={(e) => updateComposeDomainForm(preview.id, d.serviceName, e.target.value)}
                                                        />
                                                        <span className="text-xs text-neutral-500">One domain per preview.</span>
                                                    </label>
                                                    {canUpdate && (
                                                        <>
                                                            <button type="submit">Save</button>
                                                            <button type="button" onClick={() => generateComposeDomain(preview, d.serviceName)}>
                                                                Generate Domain
                                                            </button>
                                                        </>
                                                    )}
                                                </form>
                                            ))
                                        )}
                                    </div>
                                ) : (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            saveDomain(preview);
                                        }}
                                        className="flex items-end gap-2 pt-4"
                                    >
                                        <label className="flex flex-col gap-1">
                                            Domain
                                            <input
                                                id={`preview-${preview.id}-domain`}
                                                name={`preview-${preview.id}-domain`}
                                                disabled={!canUpdate}
                                                value={domainForms[preview.id]?.fqdn ?? ''}
                                                onChange={(e) => updateDomainForm(preview.id, 'fqdn', e.target.value)}
                                            />
                                            <span className="text-xs text-neutral-500">One domain per preview.</span>
                                        </label>
                                        {previews.buildPack === 'dockerimage' && (
                                            <label className="flex flex-col gap-1">
                                                Docker Tag
                                                <input
                                                    id={`preview-${preview.id}-docker-tag`}
                                                    name={`preview-${preview.id}-docker-tag`}
                                                    disabled={!canUpdate}
                                                    value={domainForms[preview.id]?.dockerRegistryImageTag ?? ''}
                                                    onChange={(e) => updateDomainForm(preview.id, 'dockerRegistryImageTag', e.target.value)}
                                                />
                                                <span className="text-xs text-neutral-500">The image tag used for this preview deployment.</span>
                                            </label>
                                        )}
                                        {canUpdate && (
                                            <>
                                                <button type="submit">Save</button>
                                                <button type="button" onClick={() => generateDomain(preview)}>
                                                    Generate Domain
                                                </button>
                                            </>
                                        )}
                                    </form>
                                )}

                                <div className="flex flex-col xl:flex-row xl:items-center gap-2 pt-6">
                                    <div className="flex-1" />
                                    {previews.canDeploy && (
                                        <>
                                            <button type="button" onClick={() => forceDeploy(preview)}>
                                                Force deploy (without cache)
                                            </button>
                                            <button type="button" onClick={() => redeploy(preview)}>
                                                {preview.status === 'exited' ? 'Deploy' : 'Redeploy'}
                                            </button>
                                        </>
                                    )}
                                    {preview.status !== 'exited' && previews.canDeploy && (
                                        <button type="button" className="text-error" onClick={() => setStoppingPreview(preview)}>
                                            Stop
                                        </button>
                                    )}
                                    {previews.canDelete && (
                                        <button type="button" className="text-error" onClick={() => setDeletingPreview(preview)}>
                                            Delete
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {conflictPreview && (
                <DomainConflictModal
                    conflicts={props.flash?.domainConflicts ?? []}
                    onCancel={() => setConflictDismissed(true)}
                    onConfirm={() => saveDomain(conflictPreview, { force_save_domains: true })}
                    consequences={[
                        'The preview deployment may not be accessible',
                        'Conflicts with production or other preview deployments',
                        'SSL certificates might not work correctly',
                        'Unpredictable routing behavior',
                    ]}
                />
            )}

            {stoppingPreview && (
                <PasswordConfirmModal
                    title="Confirm Preview Deployment Stopping?"
                    withPassword={false}
                    actions={[
                        'This preview deployment will be stopped.',
                        'If the preview deployment is currently in use data could be lost.',
                        "All non-persistent data of this preview deployment (containers, networks, unused images) will be deleted (don't worry, no data is lost and you can start the preview deployment again).",
                    ]}
                    action={{ url: stoppingPreview.urls.stop, method: 'post' }}
                    onClose={() => setStoppingPreview(null)}
                    onDone={() => setStoppingPreview(null)}
                />
            )}

            {deletingPreview && (
                <PasswordConfirmModal
                    title="Confirm Preview Deployment Deletion?"
                    withPassword={false}
                    confirmationText={`${deletingPreview.fqdn ?? ''}/`}
                    confirmationLabel="Please confirm the execution of the actions by entering the Preview Deployment name below"
                    actions={['All containers of this preview deployment will be stopped and permanently deleted.']}
                    action={{ url: deletingPreview.urls.destroy, method: 'delete' }}
                    onClose={() => setDeletingPreview(null)}
                    onDone={() => setDeletingPreview(null)}
                />
            )}
        </div>
    );
}
