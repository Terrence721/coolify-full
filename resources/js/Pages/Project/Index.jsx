import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ projects, canCreate, createUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ name: '', description: '' });

    function openAddModal() {
        reset();
        clearErrors();
        setShowAddModal(true);
    }

    function submit(e) {
        e.preventDefault();
        post(createUrl, { preserveScroll: true });
    }

    return (
        <div>
            <div className="flex gap-2 items-center">
                <h1>Projects</h1>
                {canCreate && (
                    <button type="button" onClick={openAddModal}>
                        + Add
                    </button>
                )}
            </div>
            <div className="subtitle">All your projects are here.</div>
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2 -mt-1">
                {projects.map((project) => (
                    <div key={project.uuid} className="relative gap-2 cursor-pointer coolbox group">
                        <a href={project.navigateUrl} className="absolute inset-0" />
                        <div className="flex flex-1 mx-6">
                            <div className="flex flex-col justify-center flex-1">
                                <div className="box-title">{project.name}</div>
                                <div className="box-description">{project.description}</div>
                            </div>
                            <div className="relative z-10 flex items-center justify-center gap-4 text-xs font-bold">
                                {project.addResourceUrl && (
                                    <a className="hover:underline" href={project.addResourceUrl}>
                                        + Add Resource
                                    </a>
                                )}
                                {project.canUpdate && (
                                    <a className="hover:underline" href={project.editUrl}>
                                        Settings
                                    </a>
                                )}
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {showAddModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowAddModal(false)} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">New Project</h3>
                            <button type="button" onClick={() => setShowAddModal(false)}>
                                ✕
                            </button>
                        </div>
                        <form className="flex flex-col gap-2" onSubmit={submit}>
                            <label className="flex flex-col gap-1">
                                Name
                                <input required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                {errors.name && <span className="text-error">{errors.name}</span>}
                            </label>
                            <label className="flex flex-col gap-1">
                                Description
                                <input value={data.description} onChange={(e) => setData('description', e.target.value)} />
                                {errors.description && <span className="text-error">{errors.description}</span>}
                            </label>
                            <button type="submit" disabled={processing}>
                                Save
                            </button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
