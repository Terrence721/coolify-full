import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function EnvironmentEdit({
    project,
    environment,
    canUpdate,
    canDelete,
    projectShowUrl,
    resourceIndexUrl,
    updateUrl,
    deleteUrl,
}) {
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const [confirmation, setConfirmation] = useState('');
    const { data, setData, put, processing, errors } = useForm({
        name: environment.name ?? '',
        description: environment.description ?? '',
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function destroy() {
        if (confirmation !== environment.name) return;
        router.delete(deleteUrl);
    }

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col">
                <div className="flex items-end gap-2">
                    <h1>Environment: {environment.name}</h1>
                    {canUpdate && (
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    )}
                    {canDelete && (
                        <button type="button" onClick={() => setShowDeleteModal(true)}>
                            Delete Environment
                        </button>
                    )}
                </div>
                <nav className="flex pt-2 pb-10">
                    <ol className="flex flex-wrap items-center gap-y-1">
                        <li className="inline-flex items-center">
                            <a className="text-xs truncate lg:text-sm" href={projectShowUrl}>
                                {project.name}
                            </a>
                        </li>
                        <li>
                            <a className="text-xs truncate lg:text-sm" href={resourceIndexUrl}>
                                {environment.name}
                            </a>
                        </li>
                        <li className="text-xs truncate lg:text-sm">Edit</li>
                    </ol>
                </nav>
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
            </form>

            {showDeleteModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div
                        className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs"
                        onClick={() => {
                            setShowDeleteModal(false);
                            setConfirmation('');
                        }}
                    />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <h3 className="text-2xl font-bold pb-4">Confirm Environment Deletion?</h3>
                        {!environment.isEmpty ? (
                            <div className="pb-4 text-sm text-warning">
                                This environment has resources defined, please delete them first.
                            </div>
                        ) : (
                            <>
                                <ul className="list-disc pl-4 pb-4 text-sm">
                                    <li>This will delete the selected environment.</li>
                                </ul>
                                <label className="flex flex-col gap-1 pb-4">
                                    Please confirm by entering the environment name below
                                    <input
                                        value={confirmation}
                                        onChange={(e) => setConfirmation(e.target.value)}
                                        placeholder={environment.name}
                                    />
                                </label>
                            </>
                        )}
                        <div className="flex gap-2 justify-end">
                            <button
                                type="button"
                                onClick={() => {
                                    setShowDeleteModal(false);
                                    setConfirmation('');
                                }}
                            >
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
            )}
        </div>
    );
}
