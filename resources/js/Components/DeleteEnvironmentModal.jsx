import { router } from '@inertiajs/react';
import { useState } from 'react';

export default function DeleteEnvironmentModal({ environment, deleteUrl, onClose }) {
    const [confirmation, setConfirmation] = useState('');

    function destroy() {
        if (confirmation !== environment.name) return;
        router.delete(deleteUrl);
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <h3 className="text-2xl font-bold pb-4">Confirm Environment Deletion?</h3>
                {!environment.isEmpty ? (
                    <div className="pb-4 text-sm text-warning">This environment has resources defined, please delete them first.</div>
                ) : (
                    <>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>This will delete the selected environment.</li>
                        </ul>
                        <label className="flex flex-col gap-1 pb-4">
                            Please confirm by entering the environment name below
                            <input
                                id="delete-environment-confirm"
                                name="delete-environment-confirm"
                                value={confirmation}
                                onChange={(e) => setConfirmation(e.target.value)}
                                placeholder={environment.name}
                            />
                        </label>
                    </>
                )}
                <div className="flex gap-2 justify-end">
                    <button type="button" onClick={onClose}>
                        Cancel
                    </button>
                    {environment.isEmpty && (
                        <button type="button" disabled={confirmation !== environment.name} onClick={destroy}>
                            Permanently Delete
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
