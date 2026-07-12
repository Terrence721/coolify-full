const DEFAULT_CONSEQUENCES = [
    'The Coolify instance domain will conflict with existing resources',
    'SSL certificates might not work correctly',
    'Routing behavior will be unpredictable',
    'You may not be able to access the Coolify dashboard properly',
];

export default function DomainConflictModal({ conflicts, onCancel, onConfirm, confirming = false, consequences = DEFAULT_CONSEQUENCES }) {
    if (!conflicts || conflicts.length === 0) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onCancel} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-3xl">
                <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                    <h2 className="font-bold">Domain Already In Use</h2>
                    <button type="button" onClick={onCancel}>
                        ✕
                    </button>
                </div>
                <div className="flex flex-col gap-4 p-6">
                    <div className="p-3 bg-red-500/10 rounded-lg border border-red-500/20 text-sm">
                        <strong>Domain Conflict Detected.</strong> The following domain(s) are already in use by other
                        resources. Using the same domain for multiple resources can cause routing conflicts and
                        unpredictable behavior.
                    </div>

                    <ul className="space-y-2 text-sm">
                        {conflicts.map((conflict, i) => (
                            <li key={i} className="text-error">
                                <strong>{conflict.domain}</strong> is used by{' '}
                                {conflict.resource_type === 'instance' ? (
                                    <strong>{conflict.resource_name}</strong>
                                ) : (
                                    <a href={conflict.resource_link} target="_blank" rel="noreferrer" className="underline">
                                        {conflict.resource_name}
                                    </a>
                                )}{' '}
                                ({conflict.resource_type})
                            </li>
                        ))}
                    </ul>

                    <div className="p-3 bg-yellow-500/10 rounded-lg border border-yellow-500/20 text-sm">
                        <strong>What will happen if you continue?</strong>
                        <ul className="mt-2 ml-4 list-disc">
                            {consequences.map((consequence) => (
                                <li key={consequence}>{consequence}</li>
                            ))}
                        </ul>
                    </div>

                    <div className="flex flex-wrap justify-between gap-2">
                        <button type="button" onClick={onCancel}>
                            Cancel
                        </button>
                        <button type="button" className="text-error" disabled={confirming} onClick={onConfirm}>
                            I understand, proceed anyway
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
