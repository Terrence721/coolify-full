export default function Index({ projects }) {
    return (
        <div>
            <div className="flex gap-2">
                <h1>Environments</h1>
            </div>
            <div className="subtitle">List of your environments by projects.</div>
            <div className="flex flex-col gap-2">
                {projects.length === 0 && <div>No project found.</div>}
                {projects.map((project) => (
                    <div key={project.name}>
                        <h2>Project: {project.name}</h2>
                        <div className="pt-0 pb-3">{project.description}</div>
                        {project.environments.length === 0 && <p className="pb-4">No environments found.</p>}
                        {project.environments.map((environment) => (
                            <a key={environment.href} className="coolbox group" href={environment.href}>
                                <div className="flex flex-col justify-center flex-1 mx-6">
                                    <div className="box-title">{environment.name}</div>
                                    <div className="box-description">{environment.description}</div>
                                </div>
                            </a>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
