import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function CloudInitScripts({ scripts, canCreate, storeUrl }) {
    const [modalScript, setModalScript] = useState(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({ name: '', script: '' });

    function openCreateModal() {
        reset('name', 'script');
        setModalScript({ id: null });
    }

    function openEditModal(script) {
        setData({ name: script.name, script: script.script });
        setModalScript(script);
    }

    function closeModal() {
        setModalScript(null);
        reset('name', 'script');
    }

    function submit(e) {
        e.preventDefault();
        const onSuccess = () => closeModal();

        if (modalScript?.id) {
            put(modalScript.updateUrl, { onSuccess });
        } else {
            post(storeUrl, { onSuccess });
        }
    }

    function destroyScript(script) {
        const confirmation = window.prompt(
            'This cloud-init script will be permanently deleted. This action cannot be undone. Type the script name to confirm:',
        );
        if (confirmation !== script.name) return;
        router.delete(script.destroyUrl);
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Security</h1>
                <div className="subtitle">Security related settings.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 scrollbar min-h-10">
                        <a href="/security/private-key">Private Keys</a>
                        <a href="/security/cloud-tokens">Cloud Tokens</a>
                        <a href="/security/cloud-init-scripts" className="dark:text-white">
                            Cloud-Init Scripts
                        </a>
                        <a href="/security/api-tokens">API Tokens</a>
                    </nav>
                </div>
            </div>

            <div className="flex gap-2">
                <h2 className="pb-4">Cloud-Init Scripts</h2>
                {canCreate && (
                    <button type="button" onClick={openCreateModal}>
                        + Add
                    </button>
                )}
            </div>
            <div className="pb-4 text-sm">
                Manage reusable cloud-init scripts for server initialization. Currently working only with{' '}
                <span className="text-red-500 font-bold">Hetzner&apos;s</span> integration.
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {scripts.length === 0 && <div className="text-neutral-500">No cloud-init scripts found. Create one to get started.</div>}
                {scripts.map((script) => (
                    <div key={script.id} className="flex flex-col gap-1 p-2 border dark:border-coolgray-200 hover:no-underline">
                        <div className="flex justify-between items-center">
                            <div className="flex-1">
                                <div className="font-bold dark:text-white">{script.name}</div>
                                <div className="text-xs text-neutral-500 dark:text-neutral-400">Created {script.createdAgo}</div>
                            </div>
                        </div>
                        <div className="flex gap-2 mt-2">
                            {script.canUpdate && (
                                <button type="button" onClick={() => openEditModal(script)}>
                                    Edit
                                </button>
                            )}
                            {script.canDelete && (
                                <button type="button" className="text-error" onClick={() => destroyScript(script)}>
                                    Delete
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {modalScript && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={closeModal} />
                    <div className="relative flex max-h-[85vh] w-full flex-col rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                        <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                            <h3 className="text-2xl font-bold">{modalScript.id ? 'Edit Cloud-Init Script' : 'New Cloud-Init Script'}</h3>
                            <button type="button" onClick={closeModal}>
                                ✕
                            </button>
                        </div>
                        <form onSubmit={submit} className="flex flex-col gap-2 overflow-y-auto p-6">
                            <label className="flex flex-col gap-1">
                                Name
                                <input
                                    id="cloud-init-script-name"
                                    name="cloud-init-script-name"
                                    required
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                                {errors.name && <span className="text-error">{errors.name}</span>}
                            </label>
                            <label className="flex flex-col gap-1">
                                Script
                                <textarea
                                    id="cloud-init-script-content"
                                    name="cloud-init-script-content"
                                    required
                                    rows={12}
                                    className="font-mono"
                                    value={data.script}
                                    onChange={(e) => setData('script', e.target.value)}
                                />
                                {errors.script && <span className="text-error">{errors.script}</span>}
                            </label>
                            <div>
                                <button type="submit" disabled={processing}>
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
