import { useState } from 'react';
import AddProjectModal from '../../Components/AddProjectModal';

export default function Index({ projects, canCreate, createUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);

    return (
        <div>
            <div className="flex gap-2 items-center">
                <h1>Projects</h1>
                {canCreate && (
                    <button type="button" onClick={() => setShowAddModal(true)}>
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

            {showAddModal && <AddProjectModal createUrl={createUrl} onClose={() => setShowAddModal(false)} />}
        </div>
    );
}
