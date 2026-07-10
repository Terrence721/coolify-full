import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import DeleteProjectModal from '../../Components/DeleteProjectModal';

export default function Show({ project, environments, canUpdate, canDelete, createEnvironmentUrl, deleteUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ name: '' });

    function openAddModal() {
        reset();
        clearErrors();
        setShowAddModal(true);
    }

    function submit(e) {
        e.preventDefault();
        post(createEnvironmentUrl, {
            preserveScroll: true,
            onSuccess: () => setShowAddModal(false),
        });
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Environments</h1>
                {canUpdate && (
                    <button type="button" onClick={openAddModal}>
                        + Add
                    </button>
                )}
                {canDelete && (
                    <button type="button" onClick={() => setShowDeleteModal(true)}>
                        Delete Project
                    </button>
                )}
            </div>
            <div className="text-xs truncate subtitle lg:text-sm">{project.name}.</div>
            <div className="grid gap-2 lg:grid-cols-2">
                {environments.length === 0 && <p>No environments found.</p>}
                {environments.map((environment) => (
                    <div key={environment.uuid} className="gap-2 coolbox group">
                        <div className="flex flex-1 mx-6">
                            <a className="flex flex-col justify-center flex-1" href={environment.showUrl}>
                                <div className="font-bold dark:text-white">{environment.name}</div>
                                <div className="description">{environment.description}</div>
                            </a>
                            {canUpdate && (
                                <div className="flex items-center justify-center gap-2 text-xs">
                                    <a className="font-bold hover:underline" href={environment.editUrl}>
                                        Settings
                                    </a>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {showAddModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowAddModal(false)} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">New Environment</h3>
                            <button type="button" onClick={() => setShowAddModal(false)}>
                                ✕
                            </button>
                        </div>
                        <form className="flex flex-col w-full gap-2 rounded-sm" onSubmit={submit}>
                            <label className="flex flex-col gap-1">
                                Name
                                <input
                                    required
                                    placeholder="production"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                                {errors.name && <span className="text-error">{errors.name}</span>}
                            </label>
                            <button type="submit" disabled={processing}>
                                Save
                            </button>
                        </form>
                    </div>
                </div>
            )}

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
