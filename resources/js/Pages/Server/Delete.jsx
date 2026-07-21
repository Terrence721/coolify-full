import { router } from '@inertiajs/react';
import { useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

export default function Delete({ serverNavbar, sidebar, server, hasResources, checkboxes, destroyUrl }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedActions, setSelectedActions] = useState([]);
    const [confirmationText, setConfirmationText] = useState('');
    const [password, setPassword] = useState('');

    function toggleAction(id) {
        setSelectedActions((prev) => (prev.includes(id) ? prev.filter((a) => a !== id) : [...prev, id]));
    }

    function submit(e) {
        e.preventDefault();
        if (confirmationText !== server.name) return;
        router.delete(destroyUrl, {
            data: { password, selected_actions: selectedActions },
        });
    }

    if (server.id === 0) {
        return (
            <div>
                <ServerNavbar serverNavbar={serverNavbar} />
                <div className="flex flex-col h-full gap-8 sm:flex-row">
                    <ServerSidebar sidebar={sidebar} />
                </div>
            </div>
        );
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <h2>Danger Zone</h2>
                    <div>Woah. I hope you know what are you doing.</div>
                    <h4 className="pt-4">Delete Server</h4>
                    <div className="pb-4">This will remove this server from Coolify. Beware! There is no coming back!</div>
                    {hasResources && (
                        <div className="pb-2 text-red-500">
                            This server has resources. You can force delete all resources by checking the option below.
                        </div>
                    )}
                    <button type="button" className="text-error" onClick={() => setModalOpen(true)}>
                        Delete
                    </button>
                </div>
            </div>

            {modalOpen && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setModalOpen(false)} />
                    <div className="relative flex max-h-[85vh] w-full flex-col rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                            <h3 className="text-2xl font-bold">Confirm Server Deletion?</h3>
                            <button type="button" onClick={() => setModalOpen(false)}>
                                ✕
                            </button>
                        </div>
                        <form onSubmit={submit} className="flex flex-col gap-3 overflow-y-auto p-6">
                            <p>This server will be permanently deleted from Coolify.</p>
                            {checkboxes.map((cb) => (
                                <label key={cb.id} className="flex items-center gap-2">
                                    <input
                                        id={cb.id}
                                        type="checkbox"
                                        checked={selectedActions.includes(cb.id)}
                                        onChange={() => toggleAction(cb.id)}
                                    />
                                    {cb.label}
                                </label>
                            ))}
                            <label className="flex flex-col gap-1">
                                Please confirm the execution of the actions by entering the Server Name below
                                <input
                                    id="server-delete-confirm"
                                    name="server-delete-confirm"
                                    value={confirmationText}
                                    onChange={(e) => setConfirmationText(e.target.value)}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                Password
                                <input
                                    id="server-delete-password"
                                    name="server-delete-password"
                                    type="password"
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                />
                            </label>
                            <div>
                                <button type="submit" className="text-error" disabled={confirmationText !== server.name || !password}>
                                    Delete
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
