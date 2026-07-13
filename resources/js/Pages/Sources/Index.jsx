import { useState } from 'react';
import GithubAppCreateModal from '../../Components/GithubAppCreateModal';

/**
 * React port of the `/sources` page (previously a route closure rendering the `source.all`
 * Blade view, with the "New GitHub App" modal as a nested Livewire component). Arriving with
 * `?create=1` opens the create modal immediately — used by GlobalSearch's "GitHub App" quick
 * action, which previously opened its own embedded copy of the Livewire modal.
 */
export default function Index({ sources, canCreate, storeUrl, defaultName, isCloud }) {
    const [createOpen, setCreateOpen] = useState(() => new URLSearchParams(window.location.search).get('create') === '1');

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Sources</h1>
                {canCreate && (
                    <button type="button" onClick={() => setCreateOpen(true)}>
                        + Add
                    </button>
                )}
            </div>
            <div className="subtitle">Git sources for your applications.</div>

            <div className="grid gap-4 lg:grid-cols-2 -mt-1">
                {sources.length === 0 && <div>No sources found.</div>}
                {sources.map((source) => (
                    <a key={source.uuid} className="flex gap-2 text-center hover:no-underline coolbox group" href={source.url}>
                        <div className="text-left dark:group-hover:text-white flex flex-col justify-center mx-6">
                            <div className="box-title">{source.name}</div>
                            {!source.configured && <span className="box-description text-error!">Configuration is not finished.</span>}
                            {source.configured && source.organization && (
                                <span className="box-description">Organization: {source.organization}</span>
                            )}
                        </div>
                    </a>
                ))}
            </div>

            <GithubAppCreateModal
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                storeUrl={storeUrl}
                defaultName={defaultName}
                isCloud={isCloud}
            />
        </div>
    );
}
