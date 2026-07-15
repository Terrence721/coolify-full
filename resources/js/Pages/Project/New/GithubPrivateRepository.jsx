import { useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import GitApplicationFields from '../../../Components/GitApplicationFields';
import GithubAppCreateModal from '../../../Components/GithubAppCreateModal';

/**
 * React port of App\Livewire\Project\New\GithubPrivateRepository — the "Private Repository
 * (with GitHub App)" creation flow (see App\Http\Controllers\ProjectResourceGitCreateController).
 * Repositories and branches load through fetch JSON endpoints; the server does the GitHub API
 * pagination. The "+ Add GitHub App" modal is the shared GithubAppCreateModal (ported to React
 * in Phase 53, restoring the in-page modal this page briefly linked away for); the original's
 * id-valued datalist is replaced by a filter input + select showing repository names.
 */
export default function GithubPrivateRepository({ githubApps, githubAppStoreUrl, githubAppDefaultName, isCloud, repositoriesUrl, branchesUrl, submitUrl }) {
    const { data, setData, post, processing, errors } = useForm({
        github_app_id: null,
        repository_id: null,
        owner: '',
        repo: '',
        git_branch: '',
        port: 3000,
        is_static: false,
        publish_directory: '',
        build_pack: 'nixpacks',
        base_directory: '/',
        docker_compose_location: '/docker-compose.yaml',
    });
    const [step, setStep] = useState('github_apps');
    const [loadingApp, setLoadingApp] = useState(null);
    const [repositories, setRepositories] = useState([]);
    const [installationUrl, setInstallationUrl] = useState(null);
    const [selectedRepositoryId, setSelectedRepositoryId] = useState('');
    const [repositoryFilter, setRepositoryFilter] = useState('');
    const [branches, setBranches] = useState([]);
    const [loadingBranches, setLoadingBranches] = useState(false);
    const [loadError, setLoadError] = useState('');
    const [createAppOpen, setCreateAppOpen] = useState(false);

    const filteredRepositories = useMemo(() => {
        const term = repositoryFilter.toLowerCase();

        return term ? repositories.filter((repository) => repository.name.toLowerCase().includes(term)) : repositories;
    }, [repositories, repositoryFilter]);

    async function loadRepositories(githubAppId) {
        setLoadingApp(githubAppId);
        setLoadError('');
        setBranches([]);
        try {
            const response = await fetch(`${repositoriesUrl}?github_app_id=${githubAppId}`, {
                headers: { Accept: 'application/json' },
            });
            const json = await response.json();
            if (!response.ok) {
                setLoadError(json.message ?? 'Failed to load repositories.');
                return;
            }
            setRepositories(json.repositories);
            setInstallationUrl(json.installationUrl);
            setSelectedRepositoryId(json.repositories[0]?.id ?? '');
            setData({ ...data, github_app_id: githubAppId });
            setStep('repository');
        } catch {
            setLoadError('Failed to load repositories.');
        } finally {
            setLoadingApp(null);
        }
    }

    async function loadBranches() {
        const repository = repositories.find((candidate) => String(candidate.id) === String(selectedRepositoryId));
        if (!repository) return;
        setLoadingBranches(true);
        setLoadError('');
        try {
            const query = new URLSearchParams({
                github_app_id: String(data.github_app_id),
                owner: repository.owner,
                repo: repository.name,
            });
            const response = await fetch(`${branchesUrl}?${query.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const json = await response.json();
            if (!response.ok) {
                setLoadError(json.message ?? 'Failed to load branches.');
                return;
            }
            setBranches(json.branches);
            setData({
                ...data,
                repository_id: repository.id,
                owner: repository.owner,
                repo: repository.name,
                git_branch: json.branches[0]?.name ?? 'main',
            });
        } catch {
            setLoadError('Failed to load branches.');
        } finally {
            setLoadingBranches(false);
        }
    }

    function submit(e) {
        e.preventDefault();
        post(submitUrl);
    }

    return (
        <div>
            <div className="flex items-end gap-2">
                <h1>Create a new Application</h1>
                <button type="button" onClick={() => setCreateAppOpen(true)}>
                    + Add GitHub App
                </button>
                {repositories.length > 0 && data.github_app_id && (
                    <>
                        <button type="button" onClick={() => loadRepositories(data.github_app_id)} disabled={loadingApp !== null}>
                            Refresh Repository List
                        </button>
                        {installationUrl && (
                            <a
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center self-center gap-1 text-sm hover:underline dark:text-neutral-400"
                                href={installationUrl}
                            >
                                Change Repositories on GitHub ↗
                            </a>
                        )}
                    </>
                )}
            </div>
            <div className="pb-4">Deploy any public or private Git repositories through a GitHub App.</div>

            {loadError && <div className="text-error pb-2">{loadError}</div>}

            {githubApps.length === 0 && <div className="hero">No GitHub Application found. Please create a new GitHub Application.</div>}

            {githubApps.length > 0 && step === 'github_apps' && (
                <div className="flex flex-col gap-2">
                    <h2 className="pt-4 pb-4">Select a Github App</h2>
                    <div className="flex flex-col justify-center gap-2 text-left">
                        {githubApps.map((app) => (
                            <div key={app.id} className="flex">
                                <div className="w-full gap-2 py-4 cursor-pointer group coolbox" onClick={() => loadRepositories(app.id)}>
                                    <div className="flex flex-col mx-6">
                                        <div className="box-title">{app.name}</div>
                                        <div className="box-description">{app.htmlUrl}</div>
                                    </div>
                                </div>
                                {loadingApp === app.id && <div className="flex flex-col items-center justify-center px-2">Loading...</div>}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {step === 'repository' && (
                <div className="flex flex-col gap-2">
                    {repositories.length === 0 && <div>No repositories found. Check your GitHub App configuration.</div>}

                    {repositories.length > 0 && (
                        <div className="flex flex-col gap-2 pb-6">
                            <div className="flex gap-2">
                                <input
                                    id="github-private-repo-filter"
                                    name="github-private-repo-filter"
                                    className="flex-1"
                                    placeholder="Search repositories..."
                                    value={repositoryFilter}
                                    onChange={(e) => setRepositoryFilter(e.target.value)}
                                />
                                <select
                                    id="github-private-repo-select"
                                    name="github-private-repo-select"
                                    className="flex-1"
                                    value={selectedRepositoryId}
                                    onChange={(e) => setSelectedRepositoryId(e.target.value)}
                                >
                                    {filteredRepositories.map((repository) => (
                                        <option key={repository.id} value={repository.id}>
                                            {repository.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <button type="button" onClick={loadBranches} disabled={loadingBranches || !selectedRepositoryId}>
                                {loadingBranches ? 'Loading...' : 'Load Repository'}
                            </button>
                        </div>
                    )}

                    {branches.length > 0 && (
                        <>
                            <h2 className="text-lg font-bold">Configuration</h2>
                            <form className="flex flex-col" onSubmit={submit}>
                                <div className="flex flex-col gap-2 pb-6">
                                    <GitApplicationFields data={data} setData={setData} errors={errors}>
                                        <label className="flex flex-col flex-1 gap-1">
                                            Branch
                                            <select
                                                id="github-private-repo-branch"
                                                name="github-private-repo-branch"
                                                value={data.git_branch}
                                                onChange={(e) => setData('git_branch', e.target.value)}
                                            >
                                                {branches.map((branch) => (
                                                    <option key={branch.name} value={branch.name}>
                                                        {branch.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.git_branch && <span className="text-error">{errors.git_branch}</span>}
                                        </label>
                                    </GitApplicationFields>
                                </div>
                                <button type="submit" disabled={processing}>
                                    Continue
                                </button>
                            </form>
                        </>
                    )}
                </div>
            )}

            <GithubAppCreateModal
                open={createAppOpen}
                onClose={() => setCreateAppOpen(false)}
                storeUrl={githubAppStoreUrl}
                defaultName={githubAppDefaultName}
                isCloud={isCloud}
            />
        </div>
    );
}
