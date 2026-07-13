import { useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * React port of App\Livewire\Source\Github\Create (the "New GitHub App" modal) — used by
 * Sources/Index.jsx and embedded in Project/New/GithubPrivateRepository.jsx (restoring the
 * in-page modal Phase 52 temporarily replaced with a link to the Sources page). Submitting
 * redirects to the new app's configuration page (source.github.show), same as the original.
 */
export default function GithubAppCreateModal({ open, onClose, storeUrl, defaultName, isCloud }) {
    const [showAdvanced, setShowAdvanced] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: defaultName || '',
        organization: '',
        apiUrl: 'https://api.github.com',
        htmlUrl: 'https://github.com',
        customUser: 'git',
        customPort: 22,
        isSystemWide: false,
    });

    function submit(e) {
        e.preventDefault();
        post(storeUrl);
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New GitHub App</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col w-full gap-2" onSubmit={submit}>
                    <div className="pb-2">
                        This is required, if you would like to get full integration (commit / pull request deployments, etc) with GitHub.
                    </div>
                    <div className="flex gap-2">
                        <label className="flex flex-col flex-1 gap-1">
                            Name
                            <input required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors.name && <span className="text-error">{errors.name}</span>}
                        </label>
                        <label className="flex flex-col flex-1 gap-1">
                            Organization (on GitHub)
                            <input
                                placeholder="If empty, your GitHub user will be used."
                                value={data.organization}
                                onChange={(e) => setData('organization', e.target.value)}
                            />
                            {errors.organization && <span className="text-error">{errors.organization}</span>}
                        </label>
                    </div>
                    {!isCloud && (
                        <div>
                            <label className="flex gap-2 items-center w-48">
                                <input
                                    type="checkbox"
                                    checked={data.isSystemWide}
                                    onChange={(e) => setData('isSystemWide', e.target.checked)}
                                />
                                System Wide
                            </label>
                            {data.isSystemWide && (
                                <div className="w-full max-w-2xl mx-auto pt-2 dark:text-warning">
                                    <div className="font-bold">Not Recommended</div>
                                    <div className="whitespace-normal break-words">
                                        System-wide GitHub Apps are shared across all teams on this Coolify instance. This means any
                                        team can use this GitHub App to deploy applications from your repositories. For better
                                        security and isolation, it's recommended to create team-specific GitHub Apps instead.
                                    </div>
                                </div>
                            )}
                            {errors.isSystemWide && <span className="text-error">{errors.isSystemWide}</span>}
                        </div>
                    )}
                    <div className="py-2">
                        <button
                            type="button"
                            className="flex items-center justify-between w-full px-1 py-2 text-left"
                            onClick={() => setShowAdvanced(!showAdvanced)}
                        >
                            <h4>Self-hosted / Enterprise GitHub</h4>
                            <span>{showAdvanced ? '▲' : '▼'}</span>
                        </button>
                        {showAdvanced && (
                            <div className="flex flex-col gap-2 px-2 pt-0 opacity-70">
                                <div className="flex gap-2">
                                    <label className="flex flex-col flex-1 gap-1">
                                        HTML Url
                                        <input required value={data.htmlUrl} onChange={(e) => setData('htmlUrl', e.target.value)} />
                                        {errors.htmlUrl && <span className="text-error">{errors.htmlUrl}</span>}
                                    </label>
                                    <label className="flex flex-col flex-1 gap-1">
                                        API Url
                                        <input required value={data.apiUrl} onChange={(e) => setData('apiUrl', e.target.value)} />
                                        {errors.apiUrl && <span className="text-error">{errors.apiUrl}</span>}
                                    </label>
                                </div>
                                <div className="flex gap-2">
                                    <label className="flex flex-col flex-1 gap-1">
                                        Custom Git User
                                        <input required value={data.customUser} onChange={(e) => setData('customUser', e.target.value)} />
                                        {errors.customUser && <span className="text-error">{errors.customUser}</span>}
                                    </label>
                                    <label className="flex flex-col flex-1 gap-1">
                                        Custom Git Port
                                        <input
                                            required
                                            type="number"
                                            value={data.customPort}
                                            onChange={(e) => setData('customPort', e.target.value)}
                                        />
                                        {errors.customPort && <span className="text-error">{errors.customPort}</span>}
                                    </label>
                                </div>
                            </div>
                        )}
                    </div>
                    {(errors.apiUrl || errors.htmlUrl) && !showAdvanced && (
                        <span className="text-error">{errors.apiUrl || errors.htmlUrl}</span>
                    )}
                    <button type="submit" className="mt-4" disabled={processing}>
                        Continue
                    </button>
                </form>
            </div>
        </div>
    );
}
