import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import GitApplicationFields from '../../../Components/GitApplicationFields';

/**
 * React port of App\Livewire\Project\New\PublicGitRepository — the "Public Repository" creation
 * flow of the "+ New" resource wizard (see App\Http\Controllers\ProjectResourceGitCreateController).
 * "Check repository" is a fetch to a JSON endpoint (like Terminal's connect), not an Inertia
 * visit — the response configures this form rather than navigating.
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export default function PublicGitRepository({ defaultRepositoryUrl, checkUrl, submitUrl }) {
    const { data, setData, post, processing, errors } = useForm({
        repository_url: defaultRepositoryUrl || '',
        git_branch: 'main',
        port: 3000,
        is_static: false,
        publish_directory: '',
        build_pack: 'nixpacks',
        base_directory: '/',
        docker_compose_location: '/docker-compose.yaml',
    });
    const [checking, setChecking] = useState(false);
    const [checkResult, setCheckResult] = useState(null);
    const [checkError, setCheckError] = useState('');

    async function checkRepository(e) {
        e.preventDefault();
        setChecking(true);
        setCheckError('');
        setCheckResult(null);
        try {
            const response = await fetch(checkUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ repository_url: data.repository_url }),
            });
            const json = await response.json();
            if (!response.ok) {
                setCheckError(json.message ?? 'Failed to check the repository.');
                return;
            }
            setCheckResult(json);
            setData({
                ...data,
                repository_url: json.repositoryUrl,
                git_branch: json.branch,
                base_directory: json.baseDirectory,
            });
        } catch {
            setCheckError('Failed to check the repository.');
        } finally {
            setChecking(false);
        }
    }

    function submit(e) {
        e.preventDefault();
        post(submitUrl);
    }

    return (
        <div>
            <h1>Create a new Application</h1>
            <div className="pb-8">Deploy any public Git repositories.</div>

            <form className="flex flex-col gap-2" onSubmit={checkRepository}>
                <div className="flex gap-2 items-end">
                    <label className="flex flex-col flex-1 gap-1">
                        Repository URL (https://)
                        <input
                            id="public-git-repository-url"
                            name="public-git-repository-url"
                            required
                            autoFocus
                            value={data.repository_url}
                            onChange={(e) => setData('repository_url', e.target.value)}
                        />
                    </label>
                    <button type="submit" disabled={checking}>
                        {checking ? 'Checking...' : 'Check repository'}
                    </button>
                </div>
                {(checkError || errors.repository_url) && <span className="text-error">{checkError || errors.repository_url}</span>}
                <div>
                    For example application deployments, checkout{' '}
                    <a className="underline dark:text-white" href="https://github.com/coollabsio/coolify-examples/" target="_blank" rel="noreferrer">
                        Coolify Examples
                    </a>
                    .
                </div>
            </form>

            {checkResult?.branchFound && (
                <>
                    {checkResult.rateLimitRemaining && checkResult.rateLimitReset && (
                        <div
                            className="flex gap-2 py-2"
                            title={`Rate limit remaining: ${checkResult.rateLimitRemaining} — resets at ${checkResult.rateLimitReset} UTC`}
                        >
                            <div>Rate Limit Remaining: {checkResult.rateLimitRemaining}</div>
                        </div>
                    )}

                    <form className="flex flex-col gap-2 pt-4" onSubmit={submit}>
                        <div className="flex flex-col gap-2 pb-6">
                            <GitApplicationFields data={data} setData={setData} errors={errors}>
                                <label className="flex flex-col flex-1 gap-1">
                                    Branch
                                    <input
                                        id="public-git-branch"
                                        name="public-git-branch"
                                        value={data.git_branch}
                                        disabled={checkResult.isGithub}
                                        onChange={(e) => setData('git_branch', e.target.value)}
                                        title="You can select other branches after configuration is done."
                                    />
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
    );
}
