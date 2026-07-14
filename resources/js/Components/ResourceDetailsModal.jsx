import { useState } from 'react';

/**
 * React port of the service-scoped and database-scoped views of
 * Project\Shared\ResourceDetails (a read-only identifiers modal), extracted from
 * ServiceStackTab.jsx on its second consumer (the database General tab, Phase 62).
 */
function Modal({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-xl">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">{title}</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function CopyRow({ label, text }) {
    const [copied, setCopied] = useState(false);

    function copy() {
        navigator.clipboard?.writeText(text ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    return (
        <button
            type="button"
            onClick={copy}
            className="flex items-center justify-between gap-2 px-3 py-2 text-left text-sm bg-white border dark:bg-coolgray-100 dark:border-coolgray-300 border-neutral-200 rounded-sm"
            title="Click to copy"
        >
            <span>
                <span className="font-bold">{label}:</span> {text}
            </span>
            <span className="text-xs opacity-70">{copied ? 'Copied!' : 'Copy'}</span>
        </button>
    );
}

export default function ResourceDetailsModal({ details, onClose }) {
    const sections = [
        ['Resource', details.resource],
        ['Environment', details.environment],
        ['Project', details.project],
        ['Server', details.server],
    ].filter(([, data]) => data && data.uuid);

    const stackApplications = details.stackApplications ?? [];
    const stackDatabases = details.stackDatabases ?? [];

    return (
        <Modal title="Resource Details" onClose={onClose}>
            <div className="pb-4 text-sm dark:text-neutral-400">Identifiers for this resource. Read-only</div>
            <div className="flex flex-col gap-6">
                {sections.map(([title, data]) => (
                    <div key={title}>
                        <h3>{title}</h3>
                        <div className="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <CopyRow label="Name" text={data.name} />
                            <CopyRow label="UUID" text={data.uuid} />
                        </div>
                    </div>
                ))}
                {stackApplications.length > 0 && (
                    <div>
                        <h3>Stack Applications</h3>
                        <div className="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                            {stackApplications.map((app) => (
                                <CopyRow key={app.uuid} label={app.name} text={app.uuid} />
                            ))}
                        </div>
                    </div>
                )}
                {stackDatabases.length > 0 && (
                    <div>
                        <h3>Stack Databases</h3>
                        <div className="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                            {stackDatabases.map((db) => (
                                <CopyRow key={db.uuid} label={db.name} text={db.uuid} />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}
