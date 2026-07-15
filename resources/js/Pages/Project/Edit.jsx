import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import DeleteProjectModal from '../../Components/DeleteProjectModal';

export default function Edit({ project, canDelete, updateUrl, deleteUrl }) {
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        name: project.name,
        description: project.description ?? '',
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col pb-10">
                <div className="flex gap-2">
                    <h1>{project.name}</h1>
                    <div className="flex items-end gap-2">
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                        {canDelete && (
                            <button type="button" onClick={() => setShowDeleteModal(true)}>
                                Delete Project
                            </button>
                        )}
                    </div>
                </div>
                <div className="pt-2 pb-10">Edit project details here.</div>
                <div className="flex gap-2">
                    <label className="flex flex-col gap-1">
                        Name
                        <input id="project-edit-name" name="project-edit-name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        {errors.name && <span className="text-error">{errors.name}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Description
                        <input
                            id="project-edit-description"
                            name="project-edit-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                        {errors.description && <span className="text-error">{errors.description}</span>}
                    </label>
                </div>
            </form>

            <DeleteProjectModal
                open={showDeleteModal}
                onClose={() => setShowDeleteModal(false)}
                projectName={project.name}
                disabled={!project.isEmpty}
                deleteUrl={deleteUrl}
            />
        </div>
    );
}
