import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * React port of App\Livewire\Project\DeleteProject's modal_confirmation usage — reused by both
 * Project/Show.jsx and Project/Edit.jsx. The Livewire component itself is retired outright (this
 * migration's first full component removal), since both consumers are converting together.
 */
export default function DeleteProjectModal({ open, onClose, projectName, disabled, deleteUrl }) {
    const [confirmation, setConfirmation] = useState('');

    function handleClose() {
        setConfirmation('');
        onClose();
    }

    function destroy() {
        if (confirmation !== projectName) return;
        router.delete(deleteUrl);
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={handleClose} />
            <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <h3 className="text-2xl font-bold pb-4">Confirm Project Deletion?</h3>
                {disabled ? (
                    <div className="pb-4 text-sm text-warning">This project has resources defined, please delete them first.</div>
                ) : (
                    <>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>This will delete the selected project</li>
                            <li>All Environments inside the project will be deleted as well.</li>
                        </ul>
                        <label className="flex flex-col gap-1 pb-4">
                            Please confirm the execution of the actions by entering the Project Name below
                            <input
                                id="delete-project-confirm"
                                name="delete-project-confirm"
                                value={confirmation}
                                onChange={(e) => setConfirmation(e.target.value)}
                                placeholder={projectName}
                            />
                        </label>
                    </>
                )}
                <div className="flex gap-2 justify-end">
                    <button type="button" onClick={handleClose}>
                        Cancel
                    </button>
                    {!disabled && (
                        <button type="button" disabled={confirmation !== projectName} onClick={destroy}>
                            Permanently Delete
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
