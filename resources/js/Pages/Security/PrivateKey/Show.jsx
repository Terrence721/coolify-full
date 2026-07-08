import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({ privateKey, canUpdate, canDelete, updateUrl, deleteUrl }) {
    const [showPrivateKey, setShowPrivateKey] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        name: privateKey.name,
        description: privateKey.description ?? '',
        privateKeyValue: privateKey.privateKeyValue,
        isGitRelated: privateKey.isGitRelated,
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function destroy() {
        const confirmation = window.prompt(
            `This private key will be permanently deleted, and any server/git app using it will stop working. Type the private key name to confirm:`,
        );
        if (confirmation !== privateKey.name) return;
        router.delete(deleteUrl);
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Security</h1>
                <div className="subtitle">Security related settings.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 scrollbar min-h-10">
                        <a href="/security/private-key" className="dark:text-white">
                            Private Keys
                        </a>
                        <a href="/security/cloud-tokens">Cloud Tokens</a>
                        <a href="/security/cloud-init-scripts">Cloud-Init Scripts</a>
                        <a href="/security/api-tokens">API Tokens</a>
                    </nav>
                </div>
            </div>

            <form onSubmit={submit} className="flex flex-col">
                <div className="flex items-start gap-2">
                    <h2 className="pb-4">Private Key</h2>
                    {canUpdate && (
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    )}
                    {canDelete && privateKey.id > 0 && (
                        <button type="button" onClick={destroy}>
                            Delete
                        </button>
                    )}
                </div>
                <div className="flex flex-col gap-2">
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1">
                            Name
                            <input
                                disabled={!canUpdate}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <span className="text-error">{errors.name}</span>}
                        </label>
                        <label className="flex flex-col gap-1">
                            Description
                            <input
                                disabled={!canUpdate}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            {errors.description && <span className="text-error">{errors.description}</span>}
                        </label>
                    </div>
                    <div>
                        <div className="flex items-end gap-2 py-2">
                            <div className="pl-1">Public Key</div>
                        </div>
                        <input readOnly value={privateKey.publicKey} />
                        <div className="flex items-end gap-2 py-2">
                            <div className="pl-1">
                                Private Key <span className="text-helper">*</span>
                            </div>
                            <button type="button" className="text-xs underline" onClick={() => setShowPrivateKey((v) => !v)}>
                                {showPrivateKey ? 'Hide' : 'Edit'}
                            </button>
                        </div>
                        {data.isGitRelated && (
                            <div className="w-48">
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={data.isGitRelated} disabled />
                                    Is used by a Git App?
                                </label>
                            </div>
                        )}
                        {showPrivateKey ? (
                            <textarea
                                disabled={!canUpdate}
                                rows={10}
                                className="font-mono"
                                value={data.privateKeyValue}
                                onChange={(e) => setData('privateKeyValue', e.target.value)}
                            />
                        ) : (
                            <textarea disabled rows={10} value="••••••••••••••••••••••••" readOnly />
                        )}
                        {errors.privateKeyValue && <span className="text-error">{errors.privateKeyValue}</span>}
                    </div>
                </div>
            </form>
        </div>
    );
}
