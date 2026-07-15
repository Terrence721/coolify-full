import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import DeleteEnvironmentModal from '../../Components/DeleteEnvironmentModal';

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
    const { data, setData, put, processing, errors } = useForm({
        name: environment.name ?? '',
        description: environment.description ?? '',
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
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
                            id="environment-edit-name"
                            name="environment-edit-name"
                            disabled={!canUpdate}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        {errors.name && <span className="text-error">{errors.name}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Description
                        <input
                            id="environment-edit-description"
                            name="environment-edit-description"
                            disabled={!canUpdate}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                        {errors.description && <span className="text-error">{errors.description}</span>}
                    </label>
                </div>
            </form>

            {showDeleteModal && (
                <DeleteEnvironmentModal
                    environment={environment}
                    deleteUrl={deleteUrl}
                    onClose={() => setShowDeleteModal(false)}
                />
            )}
        </div>
    );
}
