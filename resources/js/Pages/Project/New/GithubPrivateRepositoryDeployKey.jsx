import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import GitApplicationFields from '../../../Components/GitApplicationFields';

/**
 * React port of App\Livewire\Project\New\GithubPrivateRepositoryDeployKey — the "Private
 * Repository (with Deploy Key)" creation flow (see
 * App\Http\Controllers\ProjectResourceGitCreateController). Pick a team private key, then
 * describe the repository; no GitHub API involvement at all.
 */
export default function GithubPrivateRepositoryDeployKey({ defaultRepositoryUrl, privateKeys, privateKeyIndexUrl, submitUrl }) {
    const { data, setData, post, processing, errors } = useForm({
        private_key_id: null,
        repository_url: defaultRepositoryUrl || '',
        git_branch: '',
        port: 3000,
        is_static: false,
        publish_directory: '',
        build_pack: 'nixpacks',
        base_directory: '',
        docker_compose_location: '/docker-compose.yaml',
    });
    const [step, setStep] = useState('private_keys');

    function selectPrivateKey(privateKeyId) {
        setData('private_key_id', privateKeyId);
        setStep('repository');
    }

    function submit(e) {
        e.preventDefault();
        post(submitUrl);
    }

    return (
        <div>
            <h1>Create a new Application</h1>
            <div className="pb-4">Deploy any public or private Git repositories through a Deploy Key.</div>
            <div className="flex flex-col">
                {step === 'private_keys' && (
                    <>
                        <h2 className="pb-4">Select a private key</h2>
                        <div className="flex flex-col justify-center gap-2 text-left">
                            {privateKeys.length === 0 && (
                                <div className="flex flex-col items-center justify-center gap-2">
                                    <div>No private keys found.</div>
                                    <a href={privateKeyIndexUrl}>
                                        <button type="button">Create a new private key</button>
                                    </a>
                                </div>
                            )}
                            {privateKeys.map((privateKey) => (
                                <div key={privateKey.id} className="gap-2 py-4 cursor-pointer group coolbox" onClick={() => selectPrivateKey(privateKey.id)}>
                                    <div className="flex flex-col mx-6">
                                        <div className="box-title">{privateKey.name}</div>
                                        <div className="box-description">{privateKey.description}</div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                {step === 'repository' && (
                    <form className="flex flex-col gap-2" onSubmit={submit}>
                        <label className="flex flex-col gap-1">
                            Repository URL (https:// or git@)
                            <input required value={data.repository_url} onChange={(e) => setData('repository_url', e.target.value)} />
                            {errors.repository_url && <span className="text-error">{errors.repository_url}</span>}
                        </label>
                        <GitApplicationFields data={data} setData={setData} errors={errors}>
                            <label className="flex flex-col flex-1 gap-1">
                                Branch
                                <input required value={data.git_branch} onChange={(e) => setData('git_branch', e.target.value)} />
                                {errors.git_branch && <span className="text-error">{errors.git_branch}</span>}
                            </label>
                        </GitApplicationFields>
                        <button type="submit" className="mt-4" disabled={processing}>
                            Continue
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}
