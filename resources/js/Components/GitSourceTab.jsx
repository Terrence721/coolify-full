import { router } from '@inertiajs/react';
import { useState } from 'react';
import PasswordConfirmModal from './PasswordConfirmModal';

/**
 * React port of App\Livewire\Project\Application\Source — the git repository/branch/commit
 * form, deploy-key management, and Change Git Source picker. The last tab of this router:
 * Application\Configuration is now fully retired from Livewire (matching Service\Configuration
 * and Database\Configuration's own precedent), so `App\Livewire\Project\Application\Configuration`
 * (the shell), `Source`, `Heading` (superseded by ApplicationHeading.jsx back in Phase 64 but
 * never cleaned up since the Livewire shell kept using it), and `ServerStatusBadge` (a sidebar
 * decoration with no other consumer) are all deleted in this same phase.
 */
export default function GitSourceTab({ source, sourceUrls, canUpdate }) {
    const [form, setForm] = useState({
        gitRepository: source.gitRepository,
        gitBranch: source.gitBranch,
        gitCommitSha: source.gitCommitSha ?? '',
    });
    const [changingSource, setChangingSource] = useState(null);

    function submit(e) {
        e.preventDefault();
        router.patch(sourceUrls.update, form, { preserveScroll: true });
    }

    function setPrivateKey(privateKeyId) {
        router.patch(sourceUrls.setPrivateKey, { privateKeyId }, { preserveScroll: true });
    }

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col">
                <div className="flex items-center gap-2 flex-wrap">
                    <h2>Source</h2>
                    {canUpdate && <button type="submit">Save</button>}
                    <div className="flex items-center gap-4 px-2">
                        <a target="_blank" rel="noreferrer" className="flex items-center gap-1" href={source.gitBranchLocation}>
                            Open Repository
                        </a>
                        {!source.isSourcePublic && source.installationPath && (
                            <a target="_blank" rel="noreferrer" className="flex items-center gap-1" href={source.installationPath}>
                                Open Git App
                            </a>
                        )}
                        <a target="_blank" rel="noreferrer" className="flex items-center gap-1" href={source.gitCommits}>
                            Open Commits on Git
                        </a>
                    </div>
                </div>
                <div className="pb-4">Code source of your application.</div>

                <div className="flex flex-col gap-2">
                    {!source.privateKeyId && (
                        <div>
                            Currently connected source: <span className="font-bold text-warning">{source.currentSourceName}</span>
                        </div>
                    )}
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1">
                            Repository
                            <input
                                id="git-source-repository"
                                name="git-source-repository"
                                placeholder="coollabsio/coolify-example"
                                disabled={!canUpdate}
                                value={form.gitRepository}
                                onChange={(e) => setForm({ ...form, gitRepository: e.target.value })}
                            />
                        </label>
                        <label className="flex flex-col gap-1">
                            Branch
                            <input
                                id="git-source-branch"
                                name="git-source-branch"
                                placeholder="main"
                                disabled={!canUpdate}
                                value={form.gitBranch}
                                onChange={(e) => setForm({ ...form, gitBranch: e.target.value })}
                            />
                        </label>
                    </div>
                    <label className="flex flex-col gap-1">
                        Commit SHA
                        <input
                            id="git-source-commit-sha"
                            name="git-source-commit-sha"
                            placeholder="HEAD"
                            disabled={!canUpdate}
                            value={form.gitCommitSha}
                            onChange={(e) => setForm({ ...form, gitCommitSha: e.target.value })}
                        />
                    </label>
                </div>
            </form>

            {source.privateKeyId ? (
                <>
                    <h3 className="pt-4">Deploy Key</h3>
                    <div className="py-2 pt-4">
                        Currently attached Private Key: <span className="dark:text-warning">{source.privateKeyName}</span>
                    </div>
                    {canUpdate && source.privateKeys.length > 0 && (
                        <>
                            <h4 className="py-2">Select another Private Key</h4>
                            <div className="flex flex-wrap gap-2">
                                {source.privateKeys.map((key) => (
                                    <button key={key.id} type="button" onClick={() => setPrivateKey(key.id)}>
                                        {key.name}
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                </>
            ) : (
                canUpdate && (
                    <div className="pt-4">
                        <h3 className="pb-2">Change Git Source</h3>
                        <div className="grid grid-cols-1 gap-2">
                            {source.sources.length > 0 ? (
                                source.sources.map((candidate) => (
                                    <button
                                        key={candidate.id}
                                        type="button"
                                        disabled={candidate.isCurrent}
                                        className="flex items-center gap-2 w-full text-left"
                                        onClick={() => setChangingSource(candidate)}
                                    >
                                        <div className="box-title">
                                            {candidate.name}
                                            {candidate.isCurrent && <span className="text-xs"> (current)</span>}
                                        </div>
                                        <div className="box-description">{candidate.organization ?? 'Personal Account'}</div>
                                    </button>
                                ))
                            ) : (
                                <div>No other sources found</div>
                            )}
                        </div>
                    </div>
                )
            )}

            {changingSource && (
                <PasswordConfirmModal
                    title="Change Git Source"
                    withPassword={false}
                    confirmationText="Change Git Source"
                    confirmationLabel="Please confirm changing the git source by entering the text below"
                    actions={[`Change git source to ${changingSource.name}`]}
                    action={{ method: 'post', url: sourceUrls.changeSource, data: { sourceId: changingSource.id, sourceType: changingSource.type } }}
                    onClose={() => setChangingSource(null)}
                    onDone={() => setChangingSource(null)}
                />
            )}
        </div>
    );
}
